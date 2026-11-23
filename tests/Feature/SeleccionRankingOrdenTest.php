<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeleccionRankingAyudas;
use Tests\TestCase;

class SeleccionRankingOrdenTest extends TestCase
{
    use RefreshDatabase;
    use SeleccionRankingAyudas;

    public function test_ordena_por_resultado_ponderado_e_incluye_la_nota_cero(): void
    {
        $ficha = $this->ficha();
        $convocatoria = $this->convocatoria($ficha);
        $this->configurar($convocatoria, true, true, 40, 60);
        $seleccionador = $this->seleccionador($ficha);

        $a = $this->candidato($convocatoria, '8.50', '7.25'); // 7.75
        $b = $this->candidato($convocatoria, '10', '5');       // 7.00
        $c = $this->candidato($convocatoria, '0', '10');       // 6.00 (la nota cero es válida)
        $d = $this->candidato($convocatoria, '0', '0');        // 0.00

        $filas = $this->ranking($seleccionador, $convocatoria)->assertOk()->json('data');

        $this->assertSame([$a->id, $b->id, $c->id, $d->id], collect($filas)->pluck('postulacion_id')->all());
        $this->assertSame(['7.75', '7.00', '6.00', '0.00'], collect($filas)->pluck('resultado_adicional')->all());
        $this->assertSame([1, 2, 3, 4], collect($filas)->pluck('posicion')->all());
        $this->assertSame(85, $filas[0]['cumplimiento_documental']);
    }

    public function test_el_ranking_da_el_mismo_resultado_que_el_detalle_de_calificaciones(): void
    {
        $ficha = $this->ficha();
        $convocatoria = $this->convocatoria($ficha);
        $this->configurar($convocatoria, true, true, 30, 70);
        $seleccionador = $this->seleccionador($ficha);
        $candidatos = [$this->candidato($convocatoria, '7.33', '8.91'), $this->candidato($convocatoria, '0.01', '0.00'), $this->candidato($convocatoria, '10', '9.99')];

        $filas = collect($this->ranking($seleccionador, $convocatoria)->json('data'))->keyBy('postulacion_id');

        foreach ($candidatos as $candidato) {
            $detalle = $this->actingAs($seleccionador)
                ->getJson("/api/seleccion/convocatorias/{$convocatoria->id}/candidatos/{$candidato->id}/calificaciones")
                ->assertOk()->json('data.resultado_adicional.valor');
            $this->assertSame($detalle, $filas[$candidato->id]['resultado_adicional']);
        }
    }

    public function test_notas_iguales_comparten_posicion_incluso_entre_paginas(): void
    {
        // 1 de 9, 4 de 8 y 20 de 7: el grupo de 7 queda partido entre la página 1 y la 2.
        [$seleccionador, $convocatoria] = $this->escenarioConNotas(array_merge(['9'], array_fill(0, 4, '8'), array_fill(0, 20, '7')));

        $pagina1 = $this->ranking($seleccionador, $convocatoria)->assertOk();
        $pagina2 = $this->ranking($seleccionador, $convocatoria, '?page=2')->assertOk();

        $this->assertCount(20, $pagina1->json('data'));
        $this->assertCount(5, $pagina2->json('data'));
        $this->assertSame(25, $pagina1->json('meta.total'));
        $this->assertSame(2, $pagina1->json('meta.last_page'));

        $todas = collect($pagina1->json('data'))->merge($pagina2->json('data'));
        $this->assertSame([1, 2, 2, 2, 2], $todas->take(5)->pluck('posicion')->all());
        $this->assertSame([6], $todas->skip(5)->pluck('posicion')->unique()->values()->all());
        $this->assertSame(6, $pagina1->json('data.19.posicion'));
        $this->assertSame(6, $pagina2->json('data.0.posicion'));
        // El orden entre empatados es estable: sin repetidos ni faltantes entre páginas.
        $this->assertCount(25, $todas->pluck('postulacion_id')->unique());
    }

    // ── Sugerencia ─────────────────────────────────────────────────────

    public function test_sugiere_el_doble_de_las_vacantes_sin_empate(): void
    {
        [$seleccionador, $convocatoria, $c] = $this->escenarioConNotas(['9', '8', '7', '6', '5'], 2);

        $respuesta = $this->ranking($seleccionador, $convocatoria)->assertOk()
            ->assertJsonPath('meta.objetivo', ['valor' => 4, 'configurado' => false, 'efectivo' => 4])
            ->assertJsonPath('meta.sugeridos', 4)
            ->assertJsonPath('meta.empate_en_corte', null);

        $this->assertSame(['sugerido', 'sugerido', 'sugerido', 'sugerido', 'no_sugerido'], collect($respuesta->json('data'))->pluck('sugerencia')->all());
    }

    public function test_un_empate_en_el_corte_deja_el_grupo_completo_pendiente(): void
    {
        // Objetivo 4: un 9 y cuatro 8. El grupo de 8 cruza el corte (posiciones 2 a 5).
        [$seleccionador, $convocatoria, $c] = $this->escenarioConNotas(['9', '8', '8', '8', '8'], 2);

        $respuesta = $this->ranking($seleccionador, $convocatoria)->assertOk();

        $this->assertSame(['sugerido', 'empate_en_corte', 'empate_en_corte', 'empate_en_corte', 'empate_en_corte'], collect($respuesta->json('data'))->pluck('sugerencia')->all());
        $respuesta->assertJsonPath('meta.sugeridos', 1)
            ->assertJsonPath('meta.empate_en_corte.posicion', 2)
            ->assertJsonPath('meta.empate_en_corte.resultado', '8.00');
        $this->assertEqualsCanonicalizing(
            [$c[1]->id, $c[2]->id, $c[3]->id, $c[4]->id],
            $respuesta->json('meta.empate_en_corte.postulaciones'),
        );
    }

    public function test_un_empate_dentro_del_corte_no_requiere_resolucion(): void
    {
        // Objetivo 4: el empate de los dos 8 queda dentro de las primeras cuatro posiciones (9, 8, 8, 7 | 6).
        [$seleccionador, $convocatoria] = $this->escenarioConNotas(['9', '8', '8', '7', '6'], 2);

        $this->ranking($seleccionador, $convocatoria)->assertOk()
            ->assertJsonPath('meta.empate_en_corte', null)
            ->assertJsonPath('meta.sugeridos', 4);
    }

}
