<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeleccionListadoAyudas;
use Tests\TestCase;

class SeleccionListadoFiltrosTest extends TestCase
{
    use RefreshDatabase;
    use SeleccionListadoAyudas;

    public function test_el_filtro_de_estado_se_aplica_antes_de_paginar(): void
    {
        $ficha = $this->ficha();
        $actor = $this->seleccionador($ficha);
        // Las 3 en proceso son las más viejas: con 21 publicadas encima quedarían fuera de la primera página.
        $enProceso = collect(range(1, 3))->map(fn () => $this->convocatoria($ficha, 'en_proceso'));
        foreach (range(1, 21) as $_) {
            $this->convocatoria($ficha, 'publicada');
        }

        $this->listado($actor)->assertOk()->assertJsonPath('meta.total', 24)->assertJsonCount(20, 'data');

        $respuesta = $this->listado($actor, '?estado=en_proceso')->assertOk();
        $respuesta->assertJsonPath('meta.total', 3)->assertJsonPath('meta.last_page', 1);
        $this->assertEqualsCanonicalizing($enProceso->pluck('id')->all(), collect($respuesta->json('data'))->pluck('id')->all());
        $this->assertSame(['en_proceso'], collect($respuesta->json('data'))->pluck('estado.codigo')->unique()->values()->all());
    }

    public function test_un_estado_invalido_o_borrador_responde_422(): void
    {
        $actor = $this->seleccionador($this->ficha());

        $this->listado($actor, '?estado=borrador')->assertUnprocessable()->assertJsonValidationErrors('estado');
        $this->listado($actor, '?estado=inexistente')->assertUnprocessable()->assertJsonValidationErrors('estado');
    }

    public function test_el_filtro_de_estado_respeta_el_alcance_por_ficha(): void
    {
        $propia = $this->ficha('2896411');
        $ajena = $this->ficha('2896412');
        $mia = $this->convocatoria($propia, 'en_proceso');
        $this->convocatoria($ajena, 'en_proceso');

        $ids = collect($this->listado($this->seleccionador($propia), '?estado=en_proceso')->assertOk()->json('data'))->pluck('id')->all();

        $this->assertSame([$mia->id], $ids);
    }

    public function test_una_convocatoria_por_id_se_resuelve_fuera_de_la_primera_pagina_y_solo_dentro_del_alcance(): void
    {
        $propia = $this->ficha('2896411');
        $ajena = $this->ficha('2896412');
        $vieja = $this->convocatoria($propia);
        foreach (range(1, 21) as $_) {
            $this->convocatoria($propia, 'publicada');
        }
        $deOtra = $this->convocatoria($ajena);
        $actor = $this->seleccionador($propia);

        $this->assertNotContains($vieja->id, collect($this->listado($actor)->json('data'))->pluck('id')->all());
        $this->assertSame([$vieja->id], collect($this->listado($actor, "?convocatoria={$vieja->id}")->assertOk()->json('data'))->pluck('id')->all());
        $this->assertSame([], $this->listado($actor, "?convocatoria={$deOtra->id}")->assertOk()->json('data'));
    }

    // ── Conteo de candidatos ───────────────────────────────────────────

    public function test_el_conteo_coincide_con_el_total_del_panel_de_candidatos_e_incluye_el_cero(): void
    {
        $ficha = $this->ficha();
        $actor = $this->seleccionador($ficha);
        $con = $this->convocatoria($ficha);
        foreach (range(1, 3) as $_) {
            $this->postulacion($con, 'postulada');
        }
        $this->postulacion($con, 'borrador');
        $this->postulacion($con, 'retirada');
        $sin = $this->convocatoria($ficha);

        $filas = collect($this->listado($actor)->assertOk()->json('data'))->keyBy('id');

        $this->assertSame(3, $filas[$con->id]['candidatos']);
        $this->assertSame(0, $filas[$sin->id]['candidatos']);
        $this->actingAs($actor)->getJson("/api/seleccion/convocatorias/{$con->id}/candidatos")
            ->assertOk()->assertJsonPath('meta.total', $filas[$con->id]['candidatos']);
    }

    public function test_el_conteo_no_incluye_postulaciones_de_otra_convocatoria_ni_de_otra_ficha(): void
    {
        $propia = $this->ficha('2896411');
        $ajena = $this->ficha('2896412');
        $mia = $this->convocatoria($propia);
        $this->postulacion($mia);
        $otra = $this->convocatoria($ajena);
        $this->postulacion($otra);
        $this->postulacion($otra);

        $filas = $this->listado($this->seleccionador($propia))->assertOk()->json('data');

        $this->assertCount(1, $filas);
        $this->assertSame(1, $filas[0]['candidatos']);
    }

    // ── Requiere atención ──────────────────────────────────────────────

}
