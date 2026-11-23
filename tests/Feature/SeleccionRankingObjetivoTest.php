<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeleccionRankingAyudas;
use Tests\TestCase;

class SeleccionRankingObjetivoTest extends TestCase
{
    use RefreshDatabase;
    use SeleccionRankingAyudas;

    public function test_con_menos_candidatos_que_el_objetivo_se_sugieren_todos(): void
    {
        [$seleccionador, $convocatoria] = $this->escenarioConNotas(['9', '8', '7'], 5);

        $this->ranking($seleccionador, $convocatoria)->assertOk()
            ->assertJsonPath('meta.objetivo', ['valor' => 10, 'configurado' => false, 'efectivo' => 3])
            ->assertJsonPath('meta.sugeridos', 3);
    }

    public function test_el_objetivo_es_configurable_y_se_restablece(): void
    {
        [$seleccionador, $convocatoria] = $this->escenarioConNotas(['9', '8', '7', '6'], 2);
        $url = "/api/seleccion/convocatorias/{$convocatoria->id}/ranking/objetivo";

        $this->actingAs($seleccionador)->putJson($url, ['objetivo' => 2])->assertOk()
            ->assertJsonPath('data.objetivo', 2)->assertJsonPath('data.configurado', true)->assertJsonPath('data.efectivo', 2);
        $this->ranking($seleccionador, $convocatoria)->assertJsonPath('meta.sugeridos', 2)->assertJsonPath('meta.objetivo.configurado', true);

        $this->actingAs($seleccionador)->putJson($url, ['objetivo' => 0])->assertUnprocessable()->assertJsonValidationErrors('objetivo');
        $this->actingAs($seleccionador)->putJson($url, ['objetivo' => 501])->assertUnprocessable();
        $this->actingAs($seleccionador)->putJson($url, [])->assertUnprocessable();

        $this->actingAs($seleccionador)->putJson($url, ['objetivo' => null])->assertOk()
            ->assertJsonPath('data.objetivo', 4)->assertJsonPath('data.configurado', false);

        $convocatoria->forceFill(['estado_id' => $this->parametroId('estado_convocatoria', 'cerrada')])->save();
        $this->actingAs($seleccionador)->putJson($url, ['objetivo' => 3])->assertUnprocessable()->assertJsonValidationErrors('convocatoria');
    }

    // ── Sin actividades ────────────────────────────────────────────────

    public function test_sin_actividades_lista_a_los_habilitados_sin_nota_posicion_ni_sugerencia(): void
    {
        $ficha = $this->ficha();
        $convocatoria = $this->convocatoria($ficha);
        $this->configurar($convocatoria, false, false);
        $seleccionador = $this->seleccionador($ficha);
        $a = $this->candidato($convocatoria, null);
        $b = $this->candidato($convocatoria, null);
        $this->postulacion($convocatoria); // sin habilitar: queda fuera

        $respuesta = $this->ranking($seleccionador, $convocatoria)->assertOk()
            ->assertJsonPath('meta.modo', 'sin_actividades')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.sugeridos', 0)
            ->assertJsonPath('meta.empate_en_corte', null);

        foreach ($respuesta->json('data') as $fila) {
            $this->assertNull($fila['posicion']);
            $this->assertNull($fila['resultado_adicional']);
            $this->assertNull($fila['sugerencia']);
        }
        $this->assertSame([$a->id, $b->id], collect($respuesta->json('data'))->pluck('postulacion_id')->all());
    }

    public function test_sin_actividades_la_eleccion_manual_exige_justificacion(): void
    {
        $ficha = $this->ficha();
        $convocatoria = $this->convocatoria($ficha);
        $this->configurar($convocatoria, false, false);
        $seleccionador = $this->seleccionador($ficha);
        $a = $this->candidato($convocatoria, null);
        $this->candidato($convocatoria, null);
        $version = $this->ranking($seleccionador, $convocatoria)->json('meta.version');

        $this->confirmar($seleccionador, $convocatoria, $version, [$a])->assertUnprocessable()->assertJsonValidationErrors('justificacion');
        $this->confirmar($seleccionador, $convocatoria, $version, [$a], 'Elegido por el comité en la reunión del lunes')
            ->assertCreated()->assertJsonPath('data.modo', 'sin_actividades')->assertJsonPath('data.finalistas.0.posicion', null);
    }

    // ── Confirmación ───────────────────────────────────────────────────

}
