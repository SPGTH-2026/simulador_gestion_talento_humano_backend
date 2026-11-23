<?php

namespace Tests\Feature;

use App\Models\SeleccionCalificacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeleccionCalificacionAyudas;
use Tests\TestCase;

class SeleccionCalificacionRegistroTest extends TestCase
{
    use RefreshDatabase;
    use SeleccionCalificacionAyudas;

    public function test_registra_cero_ocho_cincuenta_y_diez_como_notas_reales(): void
    {
        [$seleccionador, $convocatoria] = $this->escenario();

        foreach (['0' => '0.00', '8.50' => '8.50', '10' => '10.00'] as $enviada => $guardada) {
            $postulacion = $this->candidatoHabilitado($convocatoria);
            $this->calificar($seleccionador, $convocatoria, $postulacion, 'entrevista', ['nota' => (float) $enviada, 'observacion' => 'Buen desempeño'])
                ->assertCreated()
                ->assertJsonPath('data.actividades.entrevista.estado', 'registrada')
                ->assertJsonPath('data.actividades.entrevista.calificacion.nota', $guardada)
                ->assertJsonPath('data.actividades.entrevista.calificacion.registrada_por', $seleccionador->name)
                ->assertJsonPath('data.actividades.prueba_tecnica.estado', 'pendiente');

            $this->assertSame($guardada, SeleccionCalificacion::where('postulacion_id', $postulacion->id)->value('nota'));
        }
    }

    public function test_rechaza_valores_fuera_de_rango_o_con_precision_excesiva(): void
    {
        [$seleccionador, $convocatoria, $postulacion] = $this->escenario();

        foreach ([10.01, 11, -1, 100, 8.123, 8.555, 'abc', '8,5', true, [8], null, ''] as $nota) {
            $this->calificar($seleccionador, $convocatoria, $postulacion, 'entrevista', ['nota' => $nota])
                ->assertUnprocessable()->assertJsonValidationErrors('nota');
        }
        $this->calificar($seleccionador, $convocatoria, $postulacion, 'entrevista', [])->assertUnprocessable()->assertJsonValidationErrors('nota');

        // Fecha futura, mal formada u observación demasiado larga.
        $this->actingAs($seleccionador)->putJson($this->url($convocatoria, $postulacion, 'entrevista'), ['nota' => 8, 'fecha_realizacion' => now('America/Bogota')->addDays(2)->toDateString()])
            ->assertUnprocessable()->assertJsonValidationErrors('fecha_realizacion');
        $this->actingAs($seleccionador)->putJson($this->url($convocatoria, $postulacion, 'entrevista'), ['nota' => 8, 'fecha_realizacion' => '12/11/2026'])
            ->assertUnprocessable()->assertJsonValidationErrors('fecha_realizacion');
        $this->actingAs($seleccionador)->putJson($this->url($convocatoria, $postulacion, 'entrevista'), ['nota' => 8])
            ->assertUnprocessable()->assertJsonValidationErrors('fecha_realizacion');
        $this->calificar($seleccionador, $convocatoria, $postulacion, 'entrevista', ['nota' => 8, 'observacion' => str_repeat('x', 501)])
            ->assertUnprocessable()->assertJsonValidationErrors('observacion');

        $this->assertSame(0, SeleccionCalificacion::count());
    }

    // ── Condiciones para calificar ─────────────────────────────────────

    public function test_solo_se_califica_con_la_convocatoria_en_proceso_pero_las_notas_se_consultan_siempre(): void
    {
        [$seleccionador, $convocatoria, $postulacion] = $this->escenario();
        $this->calificar($seleccionador, $convocatoria, $postulacion, 'entrevista', ['nota' => 7])->assertCreated();

        foreach (['publicada', 'cerrada'] as $estado) {
            $convocatoria->forceFill(['estado_id' => $this->parametroId('estado_convocatoria', $estado)])->save();

            $this->calificar($seleccionador, $convocatoria, $postulacion, 'prueba_tecnica', ['nota' => 7])
                ->assertUnprocessable()->assertJsonValidationErrors('convocatoria');
            $this->calificar($seleccionador, $convocatoria, $postulacion, 'entrevista', ['nota' => 9, 'motivo' => 'Corrección de la nota original'])
                ->assertUnprocessable()->assertJsonValidationErrors('convocatoria');

            $this->actingAs($seleccionador)->getJson($this->url($convocatoria, $postulacion))->assertOk()
                ->assertJsonPath('data.calificable', false)
                ->assertJsonPath('data.actividades.entrevista.calificacion.nota', '7.00');
        }
        $this->assertSame(1, SeleccionCalificacion::count());
    }

    public function test_no_se_califica_una_postulacion_que_dejo_de_estar_postulada(): void
    {
        [$seleccionador, $convocatoria, $postulacion] = $this->escenario();
        $postulacion->update(['estado_id' => $this->parametroId('estado_postulacion', 'retirada')]);

        $this->calificar($seleccionador, $convocatoria, $postulacion, 'entrevista', ['nota' => 8])
            ->assertUnprocessable()->assertJsonValidationErrors('postulacion');
        $this->assertSame(0, SeleccionCalificacion::count());
    }

    public function test_actividad_desactivada_sin_configuracion_o_sin_actividades_se_rechaza(): void
    {
        $ficha = $this->ficha('2896411');
        $seleccionador = $this->seleccionador($ficha);

        // Sin configuración guardada.
        $sinConfig = $this->convocatoria($ficha);
        $p0 = $this->candidatoHabilitado($sinConfig);
        $this->calificar($seleccionador, $sinConfig, $p0, 'entrevista', ['nota' => 8])->assertUnprocessable()->assertJsonValidationErrors('actividad');

        // Solo entrevista: la prueba técnica está desactivada.
        $soloEntrevista = $this->convocatoria($ficha);
        $this->configurar($soloEntrevista, true, false);
        $p1 = $this->candidatoHabilitado($soloEntrevista);
        $this->calificar($seleccionador, $soloEntrevista, $p1, 'prueba_tecnica', ['nota' => 8])->assertUnprocessable()->assertJsonValidationErrors('actividad');
        $this->calificar($seleccionador, $soloEntrevista, $p1, 'entrevista', ['nota' => 8])->assertCreated();

        // Sin actividades explícito.
        $sinActividades = $this->convocatoria($ficha);
        $this->configurar($sinActividades, false, false);
        $p2 = $this->candidatoHabilitado($sinActividades);
        $this->calificar($seleccionador, $sinActividades, $p2, 'entrevista', ['nota' => 8])->assertUnprocessable()->assertJsonValidationErrors('actividad');

        $this->assertSame(1, SeleccionCalificacion::count());
    }

}
