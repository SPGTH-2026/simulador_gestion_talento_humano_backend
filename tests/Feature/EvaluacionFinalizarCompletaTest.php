<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\RondasCorreccionAyudas;
use Tests\TestCase;

/**
 * Para finalizar no puede quedar nada sin calificar (documentos, ítems de checklist y habilidades declaradas), y si algo quedó
 * en «No cumple» las observaciones lo explican. Un borrador sí puede quedar a medias.
 */
class EvaluacionFinalizarCompletaTest extends TestCase
{
    use RefreshDatabase;
    use RondasCorreccionAyudas;

    private function guardar(string $estado, array $perfil, ?string $observaciones = null)
    {
        return $this->actingAs($this->evaluador)->putJson("/api/evaluaciones/{$this->postulacion->id}", array_filter([
            'estado' => $estado,
            'observaciones' => $observaciones,
            'criterios' => [['criterio' => 'Evaluación', 'puntaje' => 0, 'ponderacion' => 1]],
            'perfil' => $perfil,
        ], fn ($valor) => $valor !== null));
    }

    public function test_un_borrador_puede_quedar_a_medias(): void
    {
        $this->guardar('en_proceso', ['doc-'.$this->hoja => true])->assertOk();
    }

    public function test_no_se_finaliza_con_un_documento_sin_calificar(): void
    {
        $this->guardar('finalizada', ['doc-'.$this->hoja => true])
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($mensaje) => str_contains($mensaje, 'faltan por calificar'));
    }

    public function test_no_se_finaliza_con_items_del_checklist_sin_calificar(): void
    {
        $this->postulacion->convocatoria->documentos()->updateExistingPivot($this->hoja, ['items' => "Datos completos\nFirmada\nCon fecha"]);

        $this->guardar('finalizada', [
            'docitem-'.$this->hoja.'-0' => true,
            'docitem-'.$this->hoja.'-1' => true,
            'doc-'.$this->identidad => true,
        ])->assertStatus(422)->assertJsonPath('message', fn ($mensaje) => str_contains($mensaje, '1 ítem'));

        $this->guardar('finalizada', [
            'docitem-'.$this->hoja.'-0' => true,
            'docitem-'.$this->hoja.'-1' => true,
            'docitem-'.$this->hoja.'-2' => true,
            'doc-'.$this->identidad => true,
        ])->assertOk()->assertJsonPath('data.evaluacion.cumplimiento', 100);
    }

    public function test_una_habilidad_declarada_se_exige_y_una_no_declarada_no(): void
    {
        $convocatoria = $this->postulacion->convocatoria;
        $declarada = $convocatoria->skills()->create(['nombre' => 'Excel']);
        $convocatoria->skills()->create(['nombre' => 'Word']);
        $this->postulacion->skills()->create(['convocatoria_skill_id' => $declarada->id, 'nombre' => 'Excel']);
        $documentos = ['doc-'.$this->hoja => true, 'doc-'.$this->identidad => true];

        $this->guardar('finalizada', $documentos)
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($mensaje) => str_contains($mensaje, 'Excel') && ! str_contains($mensaje, 'Word'));

        $this->guardar('finalizada', $documentos + ['skill-'.$declarada->id => true])->assertOk();
    }

    public function test_con_un_no_cumple_las_observaciones_son_obligatorias(): void
    {
        $perfil = ['doc-'.$this->hoja => true, 'doc-'.$this->identidad => false];

        $this->guardar('finalizada', $perfil)
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($mensaje) => str_contains($mensaje, 'observaciones'));

        $this->guardar('finalizada', $perfil, 'Falta el documento de identidad legible.')->assertOk();
    }
}
