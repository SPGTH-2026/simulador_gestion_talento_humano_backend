<?php

namespace Tests\Feature;

use App\Models\Parametro;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MientrasEditable;
use Tests\Concerns\RondasCorreccionAyudas;
use Tests\TestCase;

/**
 * Una evaluación finalizada vuelve a «en proceso» cuando cambia un documento requerido (para que Selección no use un
 * resultado sobre archivos antiguos). El evaluador ve el motivo hasta que vuelve a guardar la evaluación.
 */
class ReaperturaEvaluacionTest extends TestCase
{
    use RefreshDatabase;
    use MientrasEditable;
    use RondasCorreccionAyudas;

    public function test_cambiar_un_documento_reabre_la_evaluacion_finalizada_y_el_evaluador_ve_el_motivo(): void
    {
        $this->postulacion->convocatoria->forceFill([
            'estado_id' => Parametro::porTemaYCodigo('estado_convocatoria', 'publicada')->id,
            'fecha_apertura' => now('America/Bogota')->subDay()->toDateString(),
            'fecha_cierre' => now('America/Bogota')->addDay()->toDateString(),
        ])->save();

        $this->actingAs($this->evaluador)->putJson("/api/evaluaciones/{$this->postulacion->id}", [
            'estado' => 'finalizada',
            'criterios' => [['criterio' => 'Evaluación', 'puntaje' => 80, 'ponderacion' => 1]],
            'perfil' => ['doc-'.$this->hoja => true, 'doc-'.$this->identidad => true],
        ])->assertOk()->assertJsonPath('data.evaluacion.estado', 'finalizada');

        // El aspirante reemplaza un documento requerido (enviada la postulación no se edita: se simula con ella retirada).
        $this->mientrasEditable($this->postulacion, fn () => $this->responder('hoja_de_vida', enviar: false));

        $this->actingAs($this->evaluador)->getJson("/api/evaluaciones/{$this->postulacion->id}")
            ->assertOk()
            ->assertJsonPath('data.evaluacion.estado', 'en_proceso')
            ->assertJsonPath('data.evaluacion.reabierta.motivo', fn ($m) => str_contains($m, 'cambió'));
    }

    public function test_guardar_la_evaluacion_quita_el_aviso_de_reapertura(): void
    {
        $this->postulacion->convocatoria->forceFill([
            'estado_id' => Parametro::porTemaYCodigo('estado_convocatoria', 'publicada')->id,
            'fecha_apertura' => now('America/Bogota')->subDay()->toDateString(),
            'fecha_cierre' => now('America/Bogota')->addDay()->toDateString(),
        ])->save();

        $this->actingAs($this->evaluador)->putJson("/api/evaluaciones/{$this->postulacion->id}", [
            'estado' => 'finalizada',
            'criterios' => [['criterio' => 'Evaluación', 'puntaje' => 80, 'ponderacion' => 1]],
            'perfil' => ['doc-'.$this->hoja => true, 'doc-'.$this->identidad => true],
        ])->assertOk();

        $this->mientrasEditable($this->postulacion, fn () => $this->responder('hoja_de_vida', enviar: false));

        // El evaluador revisa y vuelve a guardar la evaluación (como borrador, con criterios distintos).
        $this->actingAs($this->evaluador)->putJson("/api/evaluaciones/{$this->postulacion->id}", [
            'estado' => 'en_proceso',
            'criterios' => [['criterio' => 'Evaluación', 'puntaje' => 70, 'ponderacion' => 1]],
            'perfil' => ['doc-'.$this->hoja => true, 'doc-'.$this->identidad => true],
        ])->assertOk();

        $this->actingAs($this->evaluador)->getJson("/api/evaluaciones/{$this->postulacion->id}")
            ->assertOk()
            ->assertJsonPath('data.evaluacion.reabierta', null);
    }
}
