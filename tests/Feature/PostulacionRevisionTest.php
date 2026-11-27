<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\ConvocatoriaSkill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\PostulacionDocumentoAyudas;
use Tests\TestCase;

class PostulacionRevisionTest extends TestCase
{
    use RefreshDatabase;
    use PostulacionDocumentoAyudas;

    /** La postulación que ve quien revisa ya fue enviada: un borrador no entra a revisión. */
    private function comoEnviada(): array
    {
        return ['estado_id' => $this->parametroId('estado_postulacion', 'postulada')];
    }

    public function test_instructor_de_la_misma_ficha_puede_leer(): void
    {
        [$aspirante, $postulacion] = $this->baseConDocumentos(['hoja_de_vida' => true], $this->comoEnviada());
        $instructor = $this->instructor($aspirante->ficha);

        $this->actingAs($instructor)
            ->getJson("/api/revision/postulaciones/{$postulacion->id}/documentos")
            ->assertOk()
            ->assertJsonPath('data.editable', false)
            ->assertJsonPath('data.postulacion.id', $postulacion->id);
    }

    public function test_evaluador_aprendiz_tambien_puede_leer(): void
    {
        [$aspirante, $postulacion] = $this->baseConDocumentos([], $this->comoEnviada());
        $evaluador = $this->usuario(Role::Aprendiz, ['subrole' => 'evaluador', 'ficha_id' => $aspirante->ficha_id]);

        $this->actingAs($evaluador)
            ->getJson("/api/revision/postulaciones/{$postulacion->id}/documentos")
            ->assertOk();
    }

    public function test_instructor_de_otra_ficha_recibe_404(): void
    {
        [$aspirante, $postulacion] = $this->baseConDocumentos([], $this->comoEnviada());
        $otraFicha = $this->ficha('9999999');
        $instructor = $this->instructor($otraFicha);

        $this->actingAs($instructor)
            ->getJson("/api/revision/postulaciones/{$postulacion->id}/documentos")
            ->assertNotFound();
    }

    public function test_aspirante_no_puede_usar_el_endpoint_de_revision(): void
    {
        [$aspirante, $postulacion] = $this->baseConDocumentos([], $this->comoEnviada());

        // El aspirante no tiene 'documentos:ver': 403 en el middleware.
        $this->actingAs($aspirante)
            ->getJson("/api/revision/postulaciones/{$postulacion->id}/documentos")
            ->assertForbidden();
    }

    public function test_seleccionador_no_puede_usar_el_endpoint_de_revision(): void
    {
        [$aspirante, $postulacion] = $this->baseConDocumentos([], $this->comoEnviada());
        $seleccionador = $this->usuario(Role::Aprendiz, ['subrole' => 'seleccionador', 'ficha_id' => $aspirante->ficha_id]);

        $this->actingAs($seleccionador)
            ->getJson("/api/revision/postulaciones/{$postulacion->id}/documentos")
            ->assertForbidden();
    }

    public function test_revision_incluye_convocatoria_y_skills(): void
    {
        [$aspirante, $postulacion] = $this->baseConDocumentos(['hoja_de_vida' => true], $this->comoEnviada());
        $convocatoria = $postulacion->convocatoria;
        $skill = ConvocatoriaSkill::create(['convocatoria_id' => $convocatoria->id, 'nombre' => 'SAP HR']);

        $instructor = $this->instructor($aspirante->ficha);

        $respuesta = $this->actingAs($instructor)
            ->getJson("/api/revision/postulaciones/{$postulacion->id}/documentos")
            ->assertOk();

        $respuesta->assertJsonPath('data.convocatoria.id', $convocatoria->id);
        $respuesta->assertJsonPath('data.convocatoria.nombre', $convocatoria->nombre);
        $respuesta->assertJsonPath('data.convocatoria.codigo', $convocatoria->codigo);
        $respuesta->assertJsonPath('data.skills_convocatoria', [
            ['id' => $skill->id, 'nombre' => 'SAP HR'],
        ]);
    }

    public function test_evaluador_de_otra_ficha_recibe_404(): void
    {
        [$aspirante, $postulacion] = $this->baseConDocumentos([], $this->comoEnviada());
        $otraFicha = $this->ficha('9999999');
        $evaluador = $this->usuario(Role::Aprendiz, ['subrole' => 'evaluador', 'ficha_id' => $otraFicha->id]);

        $this->assertNotContains($aspirante->ficha_id, $evaluador->fichaIds());

        $this->actingAs($evaluador)
            ->getJson("/api/revision/postulaciones/{$postulacion->id}/documentos")
            ->assertNotFound();
    }

    public function test_aspirante_con_ficha_id_no_entra_a_revision(): void
    {
        [$aspirante, $postulacion] = $this->baseConDocumentos([], $this->comoEnviada());

        // Su ficha coincide con la de la postulación, pero no tiene
        // 'documentos:ver': el middleware responde 403 antes del alcance.
        $this->assertNotNull($aspirante->ficha_id);
        $this->assertContains($aspirante->ficha_id, $aspirante->fichaIds());

        $this->actingAs($aspirante)
            ->getJson("/api/revision/postulaciones/{$postulacion->id}/documentos")
            ->assertForbidden();
    }

}
