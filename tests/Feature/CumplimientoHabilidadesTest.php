<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\RondasCorreccionAyudas;
use Tests\TestCase;

/** El cumplimiento suma documentos y habilidades ponderadas (juntos 100 %) según lo que marca el evaluador. */
class CumplimientoHabilidadesTest extends TestCase
{
    use RefreshDatabase;
    use RondasCorreccionAyudas;

    public function test_el_cumplimiento_suma_las_habilidades_marcadas_con_su_porcentaje(): void
    {
        // Documentos 80 % (40 + 40) y una habilidad de 20 %.
        $convocatoria = $this->postulacion->convocatoria;
        $convocatoria->documentos()->updateExistingPivot($this->hoja, ['ponderacion' => 40]);
        $convocatoria->documentos()->updateExistingPivot($this->identidad, ['ponderacion' => 40]);
        $habilidad = $convocatoria->skills()->create(['nombre' => 'Excel', 'ponderacion' => 20]);

        $cuerpo = fn (array $perfil) => [
            'estado' => 'en_proceso',
            'criterios' => [['criterio' => 'Evaluación', 'puntaje' => 0, 'ponderacion' => 1]],
            'perfil' => $perfil,
        ];
        $documentos = ['doc-'.$this->hoja => true, 'doc-'.$this->identidad => true];

        // Documentos cumplidos, habilidad no marcada: 80 %.
        $this->actingAs($this->evaluador)->putJson("/api/evaluaciones/{$this->postulacion->id}", $cuerpo($documentos + ['skill-'.$habilidad->id => false]))
            ->assertOk()->assertJsonPath('data.evaluacion.cumplimiento', 80);

        // Y la habilidad marcada como cumple: 100 %.
        $this->actingAs($this->evaluador)->putJson("/api/evaluaciones/{$this->postulacion->id}", $cuerpo($documentos + ['skill-'.$habilidad->id => true]))
            ->assertOk()->assertJsonPath('data.evaluacion.cumplimiento', 100);

        $this->actingAs($this->evaluador)->getJson("/api/evaluaciones/{$this->postulacion->id}")
            ->assertOk()
            ->assertJsonPath('data.skills.0.ponderacion', 20)
            ->assertJsonPath('data.skills.0.marca', true);
    }
}
