<?php

namespace Tests\Feature;

use App\Models\PostulacionDocumento;
use App\Models\PostulacionSkill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\PortafolioAyudas;
use Tests\TestCase;

class PortafolioListadoTest extends TestCase
{
    use RefreshDatabase;
    use PortafolioAyudas;

    public function test_listado_trae_el_conteo_de_postulaciones(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $otro = $this->aspirante($ficha);
        $convocatoria = $this->crearConvocatoria($ficha, $instructor);
        $this->crearPostulacion($convocatoria, $aspirante);

        $respuesta = $this->actingAs($this->superAdmin())
            ->getJson('/api/usuarios?role=aspirante')
            ->assertOk();

        $porId = collect($respuesta->json('data'))->keyBy('id');

        $this->assertSame(1, $porId[$aspirante->id]['postulaciones_count']);
        $this->assertSame(0, $porId[$otro->id]['postulaciones_count']);
        // La tabla del portafolio pinta la última actividad con esto.
        $this->assertArrayHasKey('updated_at', $porId[$aspirante->id]);
    }

    public function test_con_postulaciones_1_filtra_a_quien_no_se_ha_postulado(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $otro = $this->aspirante($ficha);
        $convocatoria = $this->crearConvocatoria($ficha, $instructor);
        $this->crearPostulacion($convocatoria, $aspirante);

        $respuesta = $this->actingAs($this->superAdmin())
            ->getJson('/api/usuarios?role=aspirante&con_postulaciones=1')
            ->assertOk();

        $ids = collect($respuesta->json('data'))->pluck('id');

        $this->assertTrue($ids->contains($aspirante->id));
        $this->assertFalse($ids->contains($otro->id));
    }

    public function test_el_listado_no_filtra_por_ficha_para_el_super_admin(): void
    {
        $fichaA = $this->ficha('3173334');
        $fichaB = $this->ficha('2661234');
        $instructorA = $this->instructor($fichaA);
        $instructorB = $this->instructor($fichaB);
        $aspiranteA = $this->aspirante($fichaA);
        $aspiranteB = $this->aspirante($fichaB);
        $this->crearPostulacion($this->crearConvocatoria($fichaA, $instructorA), $aspiranteA);
        $this->crearPostulacion($this->crearConvocatoria($fichaB, $instructorB), $aspiranteB);

        $respuesta = $this->actingAs($this->superAdmin())
            ->getJson('/api/usuarios?role=aspirante&con_postulaciones=1')
            ->assertOk();

        $ids = collect($respuesta->json('data'))->pluck('id');

        $this->assertTrue($ids->contains($aspiranteA->id));
        $this->assertTrue($ids->contains($aspiranteB->id));
    }

    // ── Detalle (GET /api/portafolios/{user}) ──────────────────────────

    public function test_super_admin_abre_el_portafolio_con_el_resumen_de_cada_postulacion(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->conDocumentos($this->crearConvocatoria($ficha, $instructor), [
            'hoja_de_vida' => true,
            'certificados_academicos' => true,
        ]);
        $postulacion = $this->crearPostulacion($convocatoria, $aspirante);
        $this->subir($postulacion, 'hoja_de_vida');

        $respuesta = $this->actingAs($this->superAdmin())
            ->getJson("/api/portafolios/{$aspirante->id}")
            ->assertOk();

        $respuesta->assertJsonPath('data.usuario.id', $aspirante->id)
            ->assertJsonPath('data.usuario.ficha.codigo', $ficha->codigo);

        $p = $respuesta->json('data.postulaciones.0');

        $this->assertSame($postulacion->id, $p['id']);
        $this->assertSame('postulada', $p['estado']['codigo']);
        $this->assertSame($convocatoria->codigo, $p['convocatoria']['codigo']);
        $this->assertTrue($p['puede_ver_documentos']);
        // Dos obligatorios y solo uno subido -> incompleto.
        $this->assertFalse($p['completo']);
        $this->assertSame(2, $p['documentos']['requeridos']);
        $this->assertSame(1, $p['documentos']['subidos']);
        $this->assertSame(0, $p['skills']['total']);
        $this->assertSame(0, $p['skills']['con_certificado']);
    }

    public function test_el_resumen_cuenta_las_skills_y_sus_certificados(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->crearConvocatoria($ficha, $instructor);
        $postulacion = $this->crearPostulacion($convocatoria, $aspirante);

        PostulacionSkill::create([
            'postulacion_id' => $postulacion->id,
            'nombre' => 'Power BI',
            'certificado_nombre_original' => 'certificado.pdf',
            'certificado_ruta' => 'skills/certificado.pdf',
            'certificado_disco' => 'local',
            'certificado_mime' => 'application/pdf',
            'certificado_tamano_bytes' => 256,
        ]);
        PostulacionSkill::create(['postulacion_id' => $postulacion->id, 'nombre' => 'Excel']);

        $p = $this->actingAs($this->superAdmin())
            ->getJson("/api/portafolios/{$aspirante->id}")
            ->assertOk()
            ->json('data.postulaciones.0');

        $this->assertSame(2, $p['skills']['total']);
        $this->assertSame(1, $p['skills']['con_certificado']);
    }

    public function test_un_borrador_no_se_marca_como_documentos_disponibles(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->crearConvocatoria($ficha, $instructor);
        $this->crearPostulacion($convocatoria, $aspirante, [
            'estado_id' => $this->parametroId('estado_postulacion', 'borrador'),
        ]);

        $p = $this->actingAs($this->superAdmin())
            ->getJson("/api/portafolios/{$aspirante->id}")
            ->assertOk()
            ->json('data.postulaciones.0');

        $this->assertFalse($p['puede_ver_documentos']);
    }

}
