<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Evaluacion;
use App\Models\SeleccionCalificacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeleccionCalificacionAyudas;
use Tests\TestCase;

class SeleccionCalificacionHabilitacionTest extends TestCase
{
    use RefreshDatabase;
    use SeleccionCalificacionAyudas;

    public function test_solo_un_candidato_habilitado_puede_calificarse(): void
    {
        [$seleccionador, $convocatoria] = $this->escenario();

        $pendiente = $this->postulacion($convocatoria);
        $noHabilitado = $this->postulacion($convocatoria);
        $this->evaluacion($noHabilitado, 'finalizada', 30, false);
        $enRevision = $this->postulacion($convocatoria);
        $this->evaluacion($enRevision, 'finalizada', 90, true);
        $this->evaluacion($enRevision, 'finalizada', 20, false);

        foreach ([$pendiente, $noHabilitado, $enRevision] as $candidato) {
            $this->calificar($seleccionador, $convocatoria, $candidato, 'entrevista', ['nota' => 8])
                ->assertUnprocessable()->assertJsonValidationErrors('postulacion');
        }
        $this->assertSame(0, SeleccionCalificacion::count());
    }

    public function test_la_habilitacion_vale_si_la_decide_la_referencia_del_instructor(): void
    {
        [$seleccionador, $convocatoria] = $this->escenario();
        $instructor = $this->usuario(Role::Instructor, null, ['ficha_id' => $convocatoria->ficha_id]);
        $postulacion = $this->postulacion($convocatoria);
        $buena = $this->evaluacion($postulacion, 'finalizada', 90, true);
        $this->evaluacion($postulacion, 'finalizada', 20, false);

        $this->calificar($seleccionador, $convocatoria, $postulacion, 'entrevista', ['nota' => 8])->assertUnprocessable();

        $this->actingAs($instructor)->postJson("/api/seleccion/convocatorias/{$convocatoria->id}/candidatos/{$postulacion->id}/referencia-documental", [
            'evaluacion_id' => $buena->id,
            'justificacion' => 'La primera evaluación tiene el soporte completo.',
        ])->assertCreated();

        $this->calificar($seleccionador, $convocatoria, $postulacion, 'entrevista', ['nota' => 8])->assertCreated();
    }

    public function test_si_se_invalida_la_habilitacion_las_notas_siguen_visibles_y_no_se_registran_nuevas(): void
    {
        [$seleccionador, $convocatoria, $postulacion] = $this->escenario();
        $this->calificar($seleccionador, $convocatoria, $postulacion, 'entrevista', ['nota' => 9])->assertCreated();

        // La evaluación se reabre: el candidato deja de estar habilitado.
        Evaluacion::where('postulacion_id', $postulacion->id)->update(['estado' => 'en_proceso', 'pasa_seleccion' => null]);

        $this->actingAs($seleccionador)->getJson($this->url($convocatoria, $postulacion))->assertOk()
            ->assertJsonPath('data.estado_documental', 'pendiente')
            ->assertJsonPath('data.calificable', false)
            ->assertJsonPath('data.actividades.entrevista.calificacion.nota', '9.00');

        $this->calificar($seleccionador, $convocatoria, $postulacion, 'prueba_tecnica', ['nota' => 8])->assertUnprocessable();
        $this->calificar($seleccionador, $convocatoria, $postulacion, 'entrevista', ['nota' => 7, 'motivo' => 'Intento de corrección bloqueado'])->assertUnprocessable();
        $this->assertSame('9.00', SeleccionCalificacion::first()->nota);
    }

    // ── Reenvío y correcciones ─────────────────────────────────────────

}
