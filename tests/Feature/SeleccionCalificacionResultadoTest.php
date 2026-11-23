<?php

namespace Tests\Feature;

use App\Models\SeleccionConfiguracion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeleccionCalificacionAyudas;
use Tests\TestCase;

class SeleccionCalificacionResultadoTest extends TestCase
{
    use RefreshDatabase;
    use SeleccionCalificacionAyudas;

    public function test_el_resultado_se_calcula_con_pesos_40_60_solo_cuando_estan_todas_las_notas(): void
    {
        [$seleccionador, $convocatoria, $postulacion] = $this->escenario(40, 60);

        $this->actingAs($seleccionador)->getJson($this->url($convocatoria, $postulacion))->assertOk()
            ->assertJsonPath('data.resultado_adicional', ['estado' => 'incompleto', 'valor' => null])
            ->assertJsonPath('data.progreso.pendientes', ['entrevista', 'prueba_tecnica']);

        // Una nota real de 0 no es una actividad pendiente.
        $this->calificar($seleccionador, $convocatoria, $postulacion, 'entrevista', ['nota' => 0])->assertCreated()
            ->assertJsonPath('data.actividades.entrevista.estado', 'registrada')
            ->assertJsonPath('data.actividades.prueba_tecnica.estado', 'pendiente')
            ->assertJsonPath('data.resultado_adicional', ['estado' => 'incompleto', 'valor' => null])
            ->assertJsonPath('data.progreso', ['requeridas' => 2, 'registradas' => 1, 'pendientes' => ['prueba_tecnica'], 'completo' => false]);

        $this->calificar($seleccionador, $convocatoria, $postulacion, 'prueba_tecnica', ['nota' => 10])->assertCreated()
            ->assertJsonPath('data.resultado_adicional', ['estado' => 'completo', 'valor' => '6.00'])
            ->assertJsonPath('data.progreso.completo', true);

        // 8.50 * 40 % + 7.25 * 60 % = 7.75
        $otro = $this->candidatoHabilitado($convocatoria);
        $this->calificar($seleccionador, $convocatoria, $otro, 'entrevista', ['nota' => 8.5])->assertCreated();
        $this->calificar($seleccionador, $convocatoria, $otro, 'prueba_tecnica', ['nota' => 7.25])->assertCreated()
            ->assertJsonPath('data.resultado_adicional.valor', '7.75');
    }

    public function test_una_sola_actividad_vale_cien_y_el_redondeo_es_mitad_hacia_arriba(): void
    {
        $ficha = $this->ficha('2896411');
        $seleccionador = $this->seleccionador($ficha);

        $soloPrueba = $this->convocatoria($ficha);
        $this->configurar($soloPrueba, false, true);
        $p = $this->candidatoHabilitado($soloPrueba);
        $this->calificar($seleccionador, $soloPrueba, $p, 'prueba_tecnica', ['nota' => 9.35])->assertCreated()
            ->assertJsonPath('data.resultado_adicional', ['estado' => 'completo', 'valor' => '9.35']);

        // 0.01 * 50 % + 0.00 * 50 % = 0.005 -> 0.01 (la mitad sube).
        $ambas = $this->convocatoria($ficha);
        $this->configurar($ambas);
        $q = $this->candidatoHabilitado($ambas);
        $this->calificar($seleccionador, $ambas, $q, 'entrevista', ['nota' => 0.01])->assertCreated();
        $this->calificar($seleccionador, $ambas, $q, 'prueba_tecnica', ['nota' => 0])->assertCreated()
            ->assertJsonPath('data.resultado_adicional.valor', '0.01');
    }

    // ── Bloqueo de la configuración ────────────────────────────────────

    public function test_la_primera_nota_bloquea_cambios_de_configuracion_de_toda_la_convocatoria(): void
    {
        [$seleccionador, $convocatoria, $postulacion] = $this->escenario(40, 60);
        $url = "/api/seleccion/convocatorias/{$convocatoria->id}/configuracion";
        $otroCandidato = $this->candidatoHabilitado($convocatoria);

        $this->actingAs($seleccionador)->getJson($url)->assertOk()
            ->assertJsonPath('data.bloqueada', false)
            ->assertJsonPath('data.editable', true);
        $this->actingAs($seleccionador)->putJson($url, ['entrevista_activa' => true, 'prueba_tecnica_activa' => false])->assertOk();
        $this->actingAs($seleccionador)->putJson($url, ['entrevista_activa' => true, 'prueba_tecnica_activa' => true, 'peso_entrevista' => 40, 'peso_prueba_tecnica' => 60])->assertOk();

        // Un solo registro, de un candidato, congela a todos.
        $this->calificar($seleccionador, $convocatoria, $postulacion, 'entrevista', ['nota' => 8])->assertCreated();

        $this->actingAs($seleccionador)->getJson($url)->assertOk()
            ->assertJsonPath('data.bloqueada', true)
            ->assertJsonPath('data.editable', false)
            ->assertJsonPath('data.estado_convocatoria', 'en_proceso');
        // Cambia actividades, pesos o ambos: rechazado.
        $this->actingAs($seleccionador)->putJson($url, ['entrevista_activa' => true, 'prueba_tecnica_activa' => false])
            ->assertUnprocessable()->assertJsonValidationErrors('convocatoria');
        $this->actingAs($seleccionador)->putJson($url, ['entrevista_activa' => true, 'prueba_tecnica_activa' => true, 'peso_entrevista' => 50, 'peso_prueba_tecnica' => 50])
            ->assertUnprocessable();
        $this->actingAs($seleccionador)->putJson($url, ['entrevista_activa' => false, 'prueba_tecnica_activa' => false])->assertUnprocessable();

        // Reenviar exactamente lo guardado sigue respondiendo con la configuración existente.
        $this->actingAs($seleccionador)->putJson($url, ['entrevista_activa' => true, 'prueba_tecnica_activa' => true, 'peso_entrevista' => 40, 'peso_prueba_tecnica' => 60])
            ->assertOk()->assertJsonPath('data.bloqueada', true)->assertJsonPath('data.prueba_tecnica.peso', 60);

        $this->assertSame(1, SeleccionConfiguracion::count());
        $this->assertSame(60, SeleccionConfiguracion::first()->peso_prueba_tecnica);
        // El segundo candidato sigue calificándose con la configuración congelada.
        $this->calificar($seleccionador, $convocatoria, $otroCandidato, 'prueba_tecnica', ['nota' => 7])->assertCreated();
    }

    public function test_el_bloqueo_es_por_convocatoria(): void
    {
        [$seleccionador, $convocatoria, $postulacion, $ficha] = $this->escenario();
        $otra = $this->convocatoria($ficha);
        $this->configurar($otra);

        $this->calificar($seleccionador, $convocatoria, $postulacion, 'entrevista', ['nota' => 8])->assertCreated();

        $this->actingAs($seleccionador)->putJson("/api/seleccion/convocatorias/{$otra->id}/configuracion", ['entrevista_activa' => false, 'prueba_tecnica_activa' => true])
            ->assertOk()->assertJsonPath('data.bloqueada', false);
    }

}
