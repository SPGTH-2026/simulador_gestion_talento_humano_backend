<?php

namespace Tests\Feature;

use App\Models\Parametro;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Concerns\RondasCorreccionAyudas;
use Tests\TestCase;

/**
 * Pruebas de regresión de los hallazgos de la auditoría del módulo de evaluación (BUG-01A, BUG-02, BUG-03).
 * El escenario y los ayudantes están en RondasCorreccionAyudas.
 */
class AuditoriaEvaluacionTest extends TestCase
{
    use RefreshDatabase;
    use RondasCorreccionAyudas;

    /**
     * BUG-01A: con la convocatoria «publicada» y sin fecha de evaluación, una ronda ya vencida no debe permitir
     * reemplazar documentos. Hoy motivoNoEditable() devuelve «editable» y el aspirante sí puede subir el archivo.
     */
    public function test_con_la_ronda_vencida_no_se_puede_reemplazar_un_documento_aunque_la_convocatoria_siga_publicada(): void
    {
        $this->postulacion->convocatoria->forceFill([
            'estado_id' => Parametro::porTemaYCodigo('estado_convocatoria', 'publicada')->id,
        ])->save();

        $this->pedir($this->evaluador)->assertOk();
        $this->vencer($this->rondas()->sole());

        $this->actingAs($this->aspirante)->withHeaders(['Accept' => 'application/json'])
            ->post("/api/postulaciones/{$this->postulacion->id}/documentos", [
                'codigo' => 'hoja_de_vida',
                'archivo' => UploadedFile::fake()->create('tarde.pdf', 100, 'application/pdf'),
            ])->assertStatus(422);
    }

    /**
     * BUG-02: si el aspirante ya respondió y el evaluador vuelve a guardar exactamente lo mismo, debe abrirse la
     * siguiente ronda; el reenvío idéntico no puede cortarse.
     */
    public function test_reenvio_identico_despues_de_la_respuesta_abre_la_siguiente_ronda(): void
    {
        $this->pedir($this->evaluador)->assertOk();
        $this->responder();

        $this->pedir($this->evaluador)->assertOk();

        $this->assertCount(2, $this->rondas());
        $this->actingAs($this->aspirante)->getJson('/api/mis-evaluaciones')
            ->assertOk()
            ->assertJsonPath('data.0.correccion_estado', 'vigente');
    }

    /**
     * BUG-01B: durante una corrección vigente, las habilidades no se pueden cambiar desde el servidor (antes sí se podían).
     */
    public function test_durante_una_correccion_vigente_no_se_pueden_cambiar_las_habilidades(): void
    {
        $this->pedir($this->evaluador)->assertOk();

        $this->actingAs($this->aspirante)->withHeaders(['Accept' => 'application/json'])
            ->postJson("/api/postulaciones/{$this->postulacion->id}/skills", ['nombre' => 'Laravel'])
            ->assertStatus(422);
    }

    /**
     * BUG-03: un aspirante desactivado no debe aparecer en el listado de evaluación del instructor.
     */
    public function test_aspirante_desactivado_no_aparece_en_el_listado_de_evaluacion(): void
    {
        $this->pedir($this->evaluador)->assertOk();
        $this->aspirante->forceFill(['active' => false])->save();

        $this->actingAs($this->instructor)->getJson('/api/evaluaciones')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }
}
