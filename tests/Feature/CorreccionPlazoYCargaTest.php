<?php

namespace Tests\Feature;

use App\Models\Parametro;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\RondasCorreccionAyudas;
use Tests\TestCase;

/**
 * Plazo de la ronda de corrección: un borrador no lo reinicia, la carga del aspirante durante la ronda sigue abierta
 * aunque la convocatoria pase a «en proceso», y la corrección se lee directo desde la postulación (FE-BUG-03).
 */
class CorreccionPlazoYCargaTest extends TestCase
{
    use RefreshDatabase;
    use RondasCorreccionAyudas;

    /** Un borrador (en_proceso) guardado durante la ronda no cambia el plazo de la ronda. */
    public function test_guardar_borrador_no_reinicia_el_plazo_de_la_ronda(): void
    {
        $this->pedir($this->evaluador)->assertOk();
        $limite = $this->rondas()->sole()->fecha_limite->timestamp;

        $this->actingAs($this->evaluador)->putJson("/api/evaluaciones/{$this->postulacion->id}", [
            'estado' => 'en_proceso',
            'observaciones' => 'Sigo revisando.',
            'criterios' => [['criterio' => 'Evaluación', 'puntaje' => 0, 'ponderacion' => 1]],
            'perfil' => ['doc-'.$this->hoja => false, 'doc-'.$this->identidad => true],
        ])->assertOk();

        $this->assertCount(1, $this->rondas());
        $this->assertSame($limite, $this->rondas()->sole()->fecha_limite->timestamp);
    }

    /** Con la convocatoria en proceso, el aspirante sigue pudiendo cargar el documento marcado durante la ronda vigente. */
    public function test_con_la_convocatoria_en_proceso_el_aspirante_puede_cargar_durante_la_ronda_vigente(): void
    {
        $this->postulacion->convocatoria->forceFill([
            'estado_id' => Parametro::porTemaYCodigo('estado_convocatoria', 'en_proceso')->id,
        ])->save();

        $this->pedir($this->evaluador)->assertOk();
        $limite = $this->rondas()->sole()->fecha_limite->timestamp;

        $this->responder('hoja_de_vida', enviar: false);

        $this->assertSame($limite, $this->rondas()->sole()->fecha_limite->timestamp);
        $this->actingAs($this->aspirante)->getJson("/api/postulaciones/{$this->postulacion->id}/correccion")
            ->assertOk()
            ->assertJsonPath('data.correccion.ronda_actual.estado', 'vigente');
    }

    /** La corrección se lee desde la postulación; otro usuario recibe 404 y sin corrección pedida la respuesta es null. */
    public function test_la_correccion_se_lee_desde_la_postulacion_propia(): void
    {
        $this->actingAs($this->aspirante)->getJson("/api/postulaciones/{$this->postulacion->id}/correccion")
            ->assertOk()
            ->assertJsonPath('data', null);

        $this->pedir($this->evaluador)->assertOk();

        $this->actingAs($this->aspirante)->getJson("/api/postulaciones/{$this->postulacion->id}/correccion")
            ->assertOk()
            ->assertJsonPath('data.correccion.ronda_actual.estado', 'vigente');

        // Otro aspirante no ve la corrección de esta postulación.
        $otroAspirante = $this->usuario(\App\Enums\Role::Aspirante);
        $this->actingAs($otroAspirante)->getJson("/api/postulaciones/{$this->postulacion->id}/correccion")
            ->assertStatus(404);
    }
}
