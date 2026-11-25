<?php

namespace Tests\Feature;

use App\Models\PostulacionSkill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\RondasCorreccionAyudas;
use Tests\TestCase;

/** El evaluador ve el certificado que subió el aspirante para una habilidad declarada. */
class CertificadoHabilidadRevisionTest extends TestCase
{
    use RefreshDatabase;
    use RondasCorreccionAyudas;

    public function test_el_evaluador_abre_el_certificado_de_una_habilidad_declarada(): void
    {
        Storage::fake('local');

        $habilidad = $this->postulacion->convocatoria->skills()->create(['nombre' => 'Excel', 'ponderacion' => 10]);
        $declarada = PostulacionSkill::create([
            'postulacion_id' => $this->postulacion->id,
            'convocatoria_skill_id' => $habilidad->id,
            'nombre' => 'Excel',
            'certificado_nombre_original' => 'excel.pdf',
            'certificado_ruta' => "postulaciones/test/skill-{$this->postulacion->id}.pdf",
            'certificado_disco' => 'local',
            'certificado_mime' => 'application/pdf',
            'certificado_tamano_bytes' => 40,
        ]);
        Storage::disk('local')->put($declarada->certificado_ruta, '%PDF-1.4 prueba');

        // La evaluación lista la habilidad con el id de la declaración y que tiene certificado.
        $this->actingAs($this->evaluador)->getJson("/api/evaluaciones/{$this->postulacion->id}")
            ->assertOk()
            ->assertJsonPath('data.skills.0.postulacion_skill_id', $declarada->id)
            ->assertJsonPath('data.skills.0.certificada', true);

        $this->actingAs($this->evaluador)
            ->get("/api/revision/postulaciones/{$this->postulacion->id}/skills/{$declarada->id}/certificado/archivo")
            ->assertOk();
    }
}
