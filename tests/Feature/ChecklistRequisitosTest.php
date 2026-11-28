<?php

namespace Tests\Feature;

use App\Models\Parametro;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\RondasCorreccionAyudas;
use Tests\TestCase;

/**
 * Checklist de requisitos de la convocatoria: el detalle trae los requisitos; cada requisito guarda si cumple y qué
 * documento lo respalda. Son marcas informativas: no cambian el cumplimiento de los documentos.
 */
class ChecklistRequisitosTest extends TestCase
{
    use RefreshDatabase;
    use RondasCorreccionAyudas;

    public function test_el_detalle_incluye_los_requisitos_de_la_convocatoria(): void
    {
        $this->postulacion->convocatoria->forceFill([
            'estado_id' => Parametro::porTemaYCodigo('estado_convocatoria', 'publicada')->id,
            'formacion' => "Técnico en sistemas\nTecnólogo en sistemas",
            'experiencia' => 'Dos años en desarrollo web',
            'competencias' => null,
            'conocimientos_especificos' => 'PHP y SQL',
        ])->save();

        $this->actingAs($this->evaluador)->getJson("/api/evaluaciones/{$this->postulacion->id}")
            ->assertOk()
            ->assertJsonPath('data.convocatoria.requisitos.formacion', "Técnico en sistemas\nTecnólogo en sistemas")
            ->assertJsonPath('data.convocatoria.requisitos.experiencia', 'Dos años en desarrollo web')
            ->assertJsonPath('data.convocatoria.requisitos.conocimientos_especificos', 'PHP y SQL');
    }

    public function test_el_detalle_incluye_la_fecha_de_evaluacion_de_la_convocatoria(): void
    {
        $this->postulacion->convocatoria->forceFill(['fecha_evaluacion' => '2026-12-15'])->save();

        $this->actingAs($this->evaluador)->getJson("/api/evaluaciones/{$this->postulacion->id}")
            ->assertOk()
            ->assertJsonPath('data.convocatoria.fecha_evaluacion', '2026-12-15');
    }

    public function test_las_marcas_y_el_documento_de_cada_requisito_se_guardan_sin_cambiar_el_cumplimiento(): void
    {
        $documentos = ['doc-'.$this->hoja => true, 'doc-'.$this->identidad => true];
        $cuerpo = fn (array $perfil) => [
            'estado' => 'en_proceso',
            'criterios' => [['criterio' => 'Evaluación', 'puntaje' => 0, 'ponderacion' => 1]],
            'perfil' => $perfil,
        ];

        $sinChecklist = $this->actingAs($this->evaluador)
            ->putJson("/api/evaluaciones/{$this->postulacion->id}", $cuerpo($documentos))
            ->assertOk()->json('data.evaluacion.cumplimiento');

        $this->actingAs($this->evaluador)->putJson("/api/evaluaciones/{$this->postulacion->id}", $cuerpo($documentos + [
            'req-formacion-0' => true,
            'reqdoc-formacion-0' => $this->hoja,
            'req-experiencia-0' => false,
        ]))->assertOk()->assertJsonPath('data.evaluacion.cumplimiento', $sinChecklist);

        $this->actingAs($this->evaluador)->getJson("/api/evaluaciones/{$this->postulacion->id}")
            ->assertOk()
            ->assertJsonPath('data.evaluacion.perfil.req-formacion-0', true)
            ->assertJsonPath('data.evaluacion.perfil.reqdoc-formacion-0', $this->hoja)
            ->assertJsonPath('data.evaluacion.perfil.req-experiencia-0', false)
            ->assertJsonPath('data.evaluacion.cumplimiento', $sinChecklist);
    }
}
