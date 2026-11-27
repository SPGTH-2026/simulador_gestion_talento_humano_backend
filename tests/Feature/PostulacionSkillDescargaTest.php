<?php

namespace Tests\Feature;

use App\Models\PostulacionSkill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\PostulacionSkillAyudas;
use Tests\TestCase;

class PostulacionSkillDescargaTest extends TestCase
{
    use RefreshDatabase;
    use PostulacionSkillAyudas;

    public function test_se_puede_declarar_skills_en_postulacion_retirada_dentro_de_la_ventana(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->convocatoriaAbierta($ficha, $instructor);
        $postulacion = $this->crearPostulacion($convocatoria, $aspirante, [
            'estado_id' => $this->parametroId('estado_postulacion', 'retirada'),
            'fecha_retiro' => now(),
        ]);
        $skill = $this->skillDeConvocatoria($convocatoria);

        $this->actingAs($aspirante)
            ->postJson("/api/postulaciones/{$postulacion->id}/skills", ['convocatoria_skill_id' => $skill->id])
            ->assertCreated();

        $this->assertSame(
            $this->parametroId('estado_postulacion', 'retirada'),
            $postulacion->fresh()->estado_id,
        );
    }

    public function test_revisor_puede_descargar_el_certificado(): void
    {
        [$aspirante, $postulacion, $convocatoria] = $this->base();
        $convocatoriaSkill = $this->skillDeConvocatoria($convocatoria, 'Excel');

        $this->actingAs($aspirante)
            ->postJson("/api/postulaciones/{$postulacion->id}/skills", ['convocatoria_skill_id' => $convocatoriaSkill->id])
            ->assertCreated();
        $skill = PostulacionSkill::sole();

        $this->actingAs($aspirante)->withHeaders(['Accept' => 'application/json'])
            ->post("/api/postulaciones/{$postulacion->id}/skills/{$skill->id}/certificado", [
                'archivo' => $this->pdf('excel.pdf'),
            ])->assertOk();

        $this->enviada($postulacion);
        $instructor = $this->instructor($aspirante->ficha);

        $this->actingAs($instructor)
            ->get("/api/revision/postulaciones/{$postulacion->id}/skills/{$skill->id}/certificado/archivo")
            ->assertOk();

        // Un instructor de otra ficha no puede: 404.
        $otraFicha = $this->ficha('9999999');
        $ajeno = $this->instructor($otraFicha);

        $this->actingAs($ajeno)
            ->get("/api/revision/postulaciones/{$postulacion->id}/skills/{$skill->id}/certificado/archivo")
            ->assertNotFound();
    }

    // ── Visor PDF (?inline=1) ─────────────────────────────────────────

    public function test_el_certificado_lo_pide_en_linea_el_dueno_y_sigue_descargandose_sin_inline(): void
    {
        [$aspirante, $postulacion, $convocatoria] = $this->base();
        $convocatoriaSkill = $this->skillDeConvocatoria($convocatoria, 'Excel');
        $this->actingAs($aspirante)
            ->postJson("/api/postulaciones/{$postulacion->id}/skills", ['convocatoria_skill_id' => $convocatoriaSkill->id])
            ->assertCreated();
        $skill = PostulacionSkill::sole();

        $this->actingAs($aspirante)->withHeaders(['Accept' => 'application/json'])
            ->post("/api/postulaciones/{$postulacion->id}/skills/{$skill->id}/certificado", [
                'archivo' => $this->pdf('excel.pdf'),
            ])->assertOk();

        $enLinea = $this->actingAs($aspirante)
            ->get("/api/postulaciones/{$postulacion->id}/skills/{$skill->id}/certificado/archivo?inline=1")
            ->assertOk();
        $this->assertStringStartsWith('inline;', (string) $enLinea->headers->get('content-disposition'));

        $descarga = $this->actingAs($aspirante)
            ->get("/api/postulaciones/{$postulacion->id}/skills/{$skill->id}/certificado/archivo")
            ->assertOk();
        $this->assertStringStartsWith('attachment;', (string) $descarga->headers->get('content-disposition'));
    }

    public function test_el_revisor_tambien_puede_abrir_el_certificado_en_linea(): void
    {
        [$aspirante, $postulacion, $convocatoria] = $this->base();
        $convocatoriaSkill = $this->skillDeConvocatoria($convocatoria, 'Excel');
        $this->actingAs($aspirante)
            ->postJson("/api/postulaciones/{$postulacion->id}/skills", ['convocatoria_skill_id' => $convocatoriaSkill->id])
            ->assertCreated();
        $skill = PostulacionSkill::sole();

        $this->actingAs($aspirante)->withHeaders(['Accept' => 'application/json'])
            ->post("/api/postulaciones/{$postulacion->id}/skills/{$skill->id}/certificado", [
                'archivo' => $this->pdf('excel.pdf'),
            ])->assertOk();

        $this->enviada($postulacion);
        $instructor = $this->instructor($aspirante->ficha);

        $enLinea = $this->actingAs($instructor)
            ->get("/api/revision/postulaciones/{$postulacion->id}/skills/{$skill->id}/certificado/archivo?inline=1")
            ->assertOk();

        $this->assertStringStartsWith('inline;', (string) $enLinea->headers->get('content-disposition'));
    }

    public function test_descargar_un_certificado_ausente_devuelve_404(): void
    {
        [$aspirante, $postulacion, $convocatoria] = $this->base();
        $convocatoriaSkill = $this->skillDeConvocatoria($convocatoria, 'Excel');

        $this->actingAs($aspirante)
            ->postJson("/api/postulaciones/{$postulacion->id}/skills", ['convocatoria_skill_id' => $convocatoriaSkill->id])
            ->assertCreated();
        $skill = PostulacionSkill::sole();

        $this->actingAs($aspirante)->withHeaders(['Accept' => 'application/json'])
            ->post("/api/postulaciones/{$postulacion->id}/skills/{$skill->id}/certificado", [
                'archivo' => $this->pdf('excel.pdf'),
            ])->assertOk();

        Storage::disk('local')->delete(PostulacionSkill::sole()->certificado_ruta);

        $this->actingAs($aspirante)
            ->getJson("/api/postulaciones/{$postulacion->id}/skills/{$skill->id}/certificado/archivo")
            ->assertNotFound()
            ->assertJsonPath('message', 'Certificado no encontrado.');
    }

    public function test_borrar_la_skill_de_la_convocatoria_conserva_la_declarada(): void
    {
        [$aspirante, $postulacion, $convocatoria] = $this->base();
        $convocatoriaSkill = $this->skillDeConvocatoria($convocatoria, 'Excel avanzado');

        $this->actingAs($aspirante)
            ->postJson("/api/postulaciones/{$postulacion->id}/skills", [
                'convocatoria_skill_id' => $convocatoriaSkill->id,
            ])
            ->assertCreated();

        $skill = PostulacionSkill::sole();

        // La convocatoria borra su skill: la fila de la postulación se CONSERVA
        // (SET NULL) y mantiene el nombre ya copiado.
        $convocatoriaSkill->delete();

        $this->assertDatabaseHas('postulacion_skills', [
            'id' => $skill->id,
            'convocatoria_skill_id' => null,
            'nombre' => 'Excel avanzado',
        ]);
    }

    public function test_una_fila_heredada_sigue_legible_y_su_certificado_lo_abren_dueno_y_revisor(): void
    {
        [$aspirante, $postulacion] = $this->base();

        // El dueño sube y el revisor lee en el mismo recorrido: una postulación retirada es editable y la ven los revisores.
        $postulacion->update(['estado_id' => $this->parametroId('estado_postulacion', 'retirada')]);
        $delCatalogo = PostulacionSkill::create([
            'postulacion_id' => $postulacion->id,
            'convocatoria_skill_id' => null,
            'parametro_id' => $this->parametroId('skill_catalogo', 'excel'),
            'nombre' => 'Excel',
        ]);
        $propia = PostulacionSkill::create([
            'postulacion_id' => $postulacion->id,
            'convocatoria_skill_id' => null,
            'parametro_id' => null,
            'nombre' => 'Liderazgo previo',
        ]);

        $this->actingAs($aspirante)
            ->getJson("/api/postulaciones/{$postulacion->id}/documentos")
            ->assertOk()
            ->assertJsonFragment(['nombre' => 'Excel'])
            ->assertJsonFragment(['nombre' => 'Liderazgo previo']);

        $instructor = $this->instructor($aspirante->ficha);

        foreach ([$delCatalogo, $propia] as $skill) {
            $this->actingAs($aspirante)->withHeaders(['Accept' => 'application/json'])
                ->post("/api/postulaciones/{$postulacion->id}/skills/{$skill->id}/certificado", [
                    'archivo' => $this->pdf('heredada.pdf'),
                ])->assertOk();

            $this->actingAs($aspirante)
                ->get("/api/postulaciones/{$postulacion->id}/skills/{$skill->id}/certificado/archivo?inline=1")
                ->assertOk();

            $this->actingAs($instructor)
                ->get("/api/revision/postulaciones/{$postulacion->id}/skills/{$skill->id}/certificado/archivo?inline=1")
                ->assertOk();
        }
    }

}
