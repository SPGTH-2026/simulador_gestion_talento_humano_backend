<?php

namespace Tests\Feature;

use App\Models\Convocatoria;
use App\Models\ConvocatoriaSkill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\PostulacionDocumentoAyudas;
use Tests\TestCase;

class PostulacionEstadoDocumentosTest extends TestCase
{
    use RefreshDatabase;
    use PostulacionDocumentoAyudas;

    public function test_estado_incluye_convocatoria_y_skills_de_la_convocatoria(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->conDocumentos(
            $this->convocatoriaAbierta($ficha, $instructor, ['nombre' => 'Analista de datos']),
            ['hoja_de_vida' => true],
        );
        $skillA = ConvocatoriaSkill::create(['convocatoria_id' => $convocatoria->id, 'nombre' => 'Excel avanzado']);
        $skillB = ConvocatoriaSkill::create(['convocatoria_id' => $convocatoria->id, 'nombre' => 'Power BI']);
        // Enviada: el test también la lee como revisor, y un borrador no entra a revisión.
        $postulacion = $this->crearPostulacion($convocatoria, $aspirante, ['estado_id' => $this->parametroId('estado_postulacion', 'postulada')]);

        $respuesta = $this->actingAs($aspirante)
            ->getJson("/api/postulaciones/{$postulacion->id}/documentos")
            ->assertOk();

        $respuesta->assertJsonPath('data.convocatoria.id', $convocatoria->id);
        $respuesta->assertJsonPath('data.convocatoria.nombre', 'Analista de datos');
        // El código es un dato de gestión: el dueño de la postulación no lo recibe.
        $respuesta->assertJsonPath('data.convocatoria.codigo', null);

        // El revisor (que sí gestiona) sí lo recibe.
        $this->actingAs($instructor)
            ->getJson("/api/revision/postulaciones/{$postulacion->id}/documentos")
            ->assertOk()
            ->assertJsonPath('data.convocatoria.codigo', $convocatoria->codigo);

        // Los ids son los de convocatoria_skills: los mismos que acepta
        // convocatoria_skill_id en POST /skills.
        $respuesta->assertJsonPath('data.skills_convocatoria', [
            ['id' => $skillA->id, 'nombre' => 'Excel avanzado'],
            ['id' => $skillB->id, 'nombre' => 'Power BI'],
        ]);
    }

    public function test_estado_sin_skills_de_la_convocatoria_devuelve_lista_vacia(): void
    {
        [$aspirante, $postulacion] = $this->baseConDocumentos(['hoja_de_vida' => true]);

        $this->actingAs($aspirante)
            ->getJson("/api/postulaciones/{$postulacion->id}/documentos")
            ->assertOk()
            ->assertJsonPath('data.skills_convocatoria', []);
    }

    public function test_requiere_sexo_false_sin_libreta_aunque_no_haya_sexo(): void
    {
        // Convocatoria SIN libreta militar y sin sexo registrado: no se pide sexo.
        [$aspirante, $postulacion] = $this->baseConDocumentos(['hoja_de_vida' => true]);

        $this->assertDatabaseMissing('perfiles_aspirante', ['user_id' => $aspirante->id]);

        $this->actingAs($aspirante)
            ->getJson("/api/postulaciones/{$postulacion->id}/documentos")
            ->assertOk()
            ->assertJsonPath('data.requiere_sexo', false)
            ->assertJsonPath('data.sexo', null);
    }

    // ── Revisores (documentos:ver) ─────────────────────────────────────

}
