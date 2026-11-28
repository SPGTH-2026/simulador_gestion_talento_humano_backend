<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\RondasCorreccionAyudas;
use Tests\TestCase;

/** Un documento con checklist aporta la parte de sus ítems marcados como «Cumple» (no todo su peso); los ítems mandan. */
class CumplimientoChecklistDocumentoTest extends TestCase
{
    use RefreshDatabase;
    use RondasCorreccionAyudas;

    public function test_el_documento_aporta_la_parte_de_su_checklist_que_cumple(): void
    {
        // Hoja de vida 50 % con 2 ítems; documento de identidad 50 % sin checklist.
        $convocatoria = $this->postulacion->convocatoria;
        $convocatoria->documentos()->updateExistingPivot($this->hoja, ['ponderacion' => 50, 'items' => "Datos completos\nFirmada"]);
        $convocatoria->documentos()->updateExistingPivot($this->identidad, ['ponderacion' => 50]);

        $cuerpo = fn (array $perfil) => [
            'estado' => 'en_proceso',
            'criterios' => [['criterio' => 'Evaluación', 'puntaje' => 0, 'ponderacion' => 1]],
            'perfil' => $perfil,
        ];

        // Hoja cumple y solo 1 de 2 ítems: 25 % (la mitad de 50) + identidad 50 % = 75 %.
        $this->actingAs($this->evaluador)->putJson("/api/evaluaciones/{$this->postulacion->id}", $cuerpo([
            'doc-'.$this->hoja => true,
            'docitem-'.$this->hoja.'-0' => true,
            'docitem-'.$this->hoja.'-1' => false,
            'doc-'.$this->identidad => true,
        ]))->assertOk()->assertJsonPath('data.evaluacion.cumplimiento', 75);

        // Los dos ítems cumplen: 100 %.
        $this->actingAs($this->evaluador)->putJson("/api/evaluaciones/{$this->postulacion->id}", $cuerpo([
            'doc-'.$this->hoja => true,
            'docitem-'.$this->hoja.'-0' => true,
            'docitem-'.$this->hoja.'-1' => true,
            'doc-'.$this->identidad => true,
        ]))->assertOk()->assertJsonPath('data.evaluacion.cumplimiento', 100);

        // Ningún ítem cumple: el documento aporta 0 y solo cuenta la identidad.
        $this->actingAs($this->evaluador)->putJson("/api/evaluaciones/{$this->postulacion->id}", $cuerpo([
            'doc-'.$this->hoja => false,
            'docitem-'.$this->hoja.'-0' => false,
            'docitem-'.$this->hoja.'-1' => false,
            'doc-'.$this->identidad => true,
        ]))->assertOk()->assertJsonPath('data.evaluacion.cumplimiento', 50);

        // Los ítems mandan: aunque el documento llegue como «No cumple», con ítems cumplidos aporta su parte.
        $this->actingAs($this->evaluador)->putJson("/api/evaluaciones/{$this->postulacion->id}", $cuerpo([
            'doc-'.$this->hoja => false,
            'docitem-'.$this->hoja.'-0' => true,
            'docitem-'.$this->hoja.'-1' => true,
            'doc-'.$this->identidad => true,
        ]))->assertOk()->assertJsonPath('data.evaluacion.cumplimiento', 100);
    }
}
