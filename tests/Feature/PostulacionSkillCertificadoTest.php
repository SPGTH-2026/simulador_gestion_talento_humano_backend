<?php

namespace Tests\Feature;

use App\Models\PostulacionSkill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\PostulacionSkillAyudas;
use Tests\TestCase;

class PostulacionSkillCertificadoTest extends TestCase
{
    use RefreshDatabase;
    use PostulacionSkillAyudas;

    public function test_certificado_opcional_sobre_una_skill_de_la_convocatoria(): void
    {
        [$aspirante, $postulacion, $convocatoria] = $this->base();
        $skill = $this->skillDeConvocatoria($convocatoria, 'Word');

        $this->actingAs($aspirante)
            ->postJson("/api/postulaciones/{$postulacion->id}/skills", ['convocatoria_skill_id' => $skill->id])
            ->assertCreated();

        $declarada = PostulacionSkill::sole();

        $this->actingAs($aspirante)->withHeaders(['Accept' => 'application/json'])
            ->post("/api/postulaciones/{$postulacion->id}/skills/{$declarada->id}/certificado", [
                'archivo' => $this->pdf('word.pdf'),
            ])
            ->assertOk()
            ->assertJsonPath('data.origen', 'convocatoria')
            ->assertJsonPath('data.certificado.nombre_original', 'word.pdf');
    }

    public function test_revision_muestra_el_origen_de_cada_skill(): void
    {
        [$aspirante, $postulacion, $convocatoria] = $this->base();
        $convocatoriaSkill = $this->skillDeConvocatoria($convocatoria, 'Power BI');
        $url = "/api/postulaciones/{$postulacion->id}/skills";

        $this->actingAs($aspirante)
            ->postJson($url, ['convocatoria_skill_id' => $convocatoriaSkill->id])
            ->assertCreated();

        // Las filas VIEJAS (las de antes del cambio) NO se borran: se conservan
        // con su origen y el revisor las sigue viendo.
        PostulacionSkill::create([
            'postulacion_id' => $postulacion->id,
            'parametro_id' => $this->parametroId('skill_catalogo', 'excel'),
            'nombre' => 'Excel',
        ]);
        PostulacionSkill::create([
            'postulacion_id' => $postulacion->id,
            'nombre' => 'Pintura',
        ]);

        $this->enviada($postulacion);
        $instructor = $this->instructor($aspirante->ficha);

        $skills = collect(
            $this->actingAs($instructor)
                ->getJson("/api/revision/postulaciones/{$postulacion->id}/documentos")
                ->assertOk()
                ->json('data.skills')
        )->keyBy('nombre');

        $this->assertSame('convocatoria', $skills['Power BI']['origen']);
        $this->assertSame('catalogo', $skills['Excel']['origen']);
        $this->assertSame('propia', $skills['Pintura']['origen']);

        // El dueño ve el mismo origen.
        $mias = collect(
            $this->actingAs($aspirante)
                ->getJson("/api/postulaciones/{$postulacion->id}/documentos")
                ->assertOk()
                ->json('data.skills')
        )->keyBy('nombre');

        $this->assertSame('catalogo', $mias['Excel']['origen']);
    }

    // ── Certificado opcional ───────────────────────────────────────────

    public function test_sube_y_quita_el_certificado_de_una_skill(): void
    {
        [$aspirante, $postulacion, $convocatoria] = $this->base();
        $convocatoriaSkill = $this->skillDeConvocatoria($convocatoria, 'Excel');

        $this->actingAs($aspirante)
            ->postJson("/api/postulaciones/{$postulacion->id}/skills", ['convocatoria_skill_id' => $convocatoriaSkill->id])
            ->assertCreated();

        $skill = PostulacionSkill::sole();
        $url = "/api/postulaciones/{$postulacion->id}/skills/{$skill->id}/certificado";

        $this->actingAs($aspirante)->withHeaders(['Accept' => 'application/json'])
            ->post($url, ['archivo' => $this->pdf('excel.pdf')])
            ->assertOk()
            ->assertJsonPath('data.certificado.nombre_original', 'excel.pdf');

        $ruta = PostulacionSkill::sole()->certificado_ruta;
        Storage::disk('local')->assertExists($ruta);

        $this->actingAs($aspirante)->deleteJson($url)->assertNoContent();

        Storage::disk('local')->assertMissing($ruta);
        $this->assertNull(PostulacionSkill::sole()->certificado_ruta);
    }

    public function test_borrar_una_skill_borra_su_certificado(): void
    {
        [$aspirante, $postulacion, $convocatoria] = $this->base();
        $convocatoriaSkill = $this->skillDeConvocatoria($convocatoria, 'Excel');

        $this->actingAs($aspirante)
            ->postJson("/api/postulaciones/{$postulacion->id}/skills", ['convocatoria_skill_id' => $convocatoriaSkill->id])
            ->assertCreated();
        $skill = PostulacionSkill::sole();

        $this->actingAs($aspirante)->withHeaders(['Accept' => 'application/json'])
            ->post("/api/postulaciones/{$postulacion->id}/skills/{$skill->id}/certificado", [
                'archivo' => $this->pdf(),
            ])->assertOk();

        $ruta = PostulacionSkill::sole()->certificado_ruta;

        $this->actingAs($aspirante)
            ->deleteJson("/api/postulaciones/{$postulacion->id}/skills/{$skill->id}")
            ->assertNoContent();

        Storage::disk('local')->assertMissing($ruta);
        $this->assertDatabaseCount('postulacion_skills', 0);
    }

    public function test_certificado_no_pdf_devuelve_422(): void
    {
        [$aspirante, $postulacion, $convocatoria] = $this->base();
        $convocatoriaSkill = $this->skillDeConvocatoria($convocatoria, 'Excel');

        $this->actingAs($aspirante)
            ->postJson("/api/postulaciones/{$postulacion->id}/skills", ['convocatoria_skill_id' => $convocatoriaSkill->id])
            ->assertCreated();
        $skill = PostulacionSkill::sole();

        $this->actingAs($aspirante)->withHeaders(['Accept' => 'application/json'])
            ->post("/api/postulaciones/{$postulacion->id}/skills/{$skill->id}/certificado", [
                'archivo' => UploadedFile::fake()->create('notas.txt', 10, 'text/plain'),
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.archivo.0', 'El archivo debe ser un PDF.');
    }

    // ── Acceso ─────────────────────────────────────────────────────────

    public function test_no_se_puede_declarar_skills_en_una_postulacion_ajena(): void
    {
        [$aspirante, $postulacion, $convocatoria] = $this->base();
        $skill = $this->skillDeConvocatoria($convocatoria);
        $otro = $this->aspirante($postulacion->convocatoria->ficha);

        $this->actingAs($otro)
            ->postJson("/api/postulaciones/{$postulacion->id}/skills", ['convocatoria_skill_id' => $skill->id])
            ->assertNotFound()
            ->assertJsonPath('message', 'Postulación no encontrada.');
    }

}
