<?php

namespace Tests\Feature;

use App\Models\ConvocatoriaSkill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\PostulacionRetiradaEditableAyudas;
use Tests\TestCase;

class PostulacionRetiradaEditarTest extends TestCase
{
    use RefreshDatabase;
    use PostulacionRetiradaEditableAyudas;

    public function test_retirada_sube_documento_requerido_dentro_de_la_ventana_sin_cambiar_estado(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->conDocumentos($this->convocatoriaAbierta($ficha, $instructor), ['hoja_de_vida' => true]);
        $postulacion = $this->postulacionRetirada($convocatoria, $aspirante);
        $antes = $this->estadoDe($postulacion);

        $this->actingAs($aspirante)
            ->postJson("/api/postulaciones/{$postulacion->id}/documentos", [
                'codigo' => 'hoja_de_vida',
                'archivo' => $this->pdf(),
            ])
            ->assertCreated();

        $this->assertSame($antes, $this->estadoDe($postulacion));
    }

    public function test_retirada_declara_skill_dentro_de_la_ventana(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->convocatoriaAbierta($ficha, $instructor);
        $postulacion = $this->postulacionRetirada($convocatoria, $aspirante);
        $skill = ConvocatoriaSkill::create([
            'convocatoria_id' => $convocatoria->id,
            'nombre' => 'Excel avanzado',
        ]);

        $this->actingAs($aspirante)
            ->postJson("/api/postulaciones/{$postulacion->id}/skills", ['convocatoria_skill_id' => $skill->id])
            ->assertCreated();
    }

    public function test_retirada_cambia_el_sexo_sin_cambiar_estado(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->convocatoriaAbierta($ficha, $instructor);
        $postulacion = $this->postulacionRetirada($convocatoria, $aspirante);
        $antes = $this->estadoDe($postulacion);

        $this->actingAs($aspirante)
            ->putJson('/api/perfil/sexo', ['sexo' => 'mujer'])
            ->assertOk()
            ->assertJsonPath('data.sexo.codigo', 'mujer');

        $this->assertSame($antes, $this->estadoDe($postulacion));
    }

    // ── Fuera de la ventana ────────────────────────────────────────────

    public function test_retirada_con_convocatoria_cerrada_da_422_y_el_get_explica_por_que(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->convocatoriaCerrada($ficha, $instructor);
        $postulacion = $this->postulacionRetirada($convocatoria, $aspirante);
        $motivo = 'La convocatoria ya cerró el '.$convocatoria->fecha_cierre->format('d/m/Y')
            .'. Tu postulación retirada queda solo para consulta.';

        $this->actingAs($aspirante)
            ->postJson("/api/postulaciones/{$postulacion->id}/documentos", [
                'codigo' => 'hoja_de_vida',
                'archivo' => $this->pdf(),
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.documentos.0', $motivo);

        $this->actingAs($aspirante)
            ->getJson("/api/postulaciones/{$postulacion->id}/documentos")
            ->assertOk()
            ->assertJsonPath('data.editable', false)
            ->assertJsonPath('data.motivo_edicion', $motivo);
    }

    public function test_retirada_con_convocatoria_en_proceso_da_422(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->conDocumentos($this->convocatoriaAbierta($ficha, $instructor, [
            'estado_id' => $this->parametroId('estado_convocatoria', 'en_proceso'),
        ]), ['hoja_de_vida' => true]);
        $postulacion = $this->postulacionRetirada($convocatoria, $aspirante);

        $this->actingAs($aspirante)
            ->postJson("/api/postulaciones/{$postulacion->id}/documentos", [
                'codigo' => 'hoja_de_vida',
                'archivo' => $this->pdf(),
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.documentos.0', 'La convocatoria no está publicada.');
    }

    public function test_retirada_ajena_devuelve_404(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $duenio = $this->aspirante($ficha);
        $otro = $this->aspirante($ficha);
        $convocatoria = $this->conDocumentos($this->convocatoriaAbierta($ficha, $instructor), ['hoja_de_vida' => true]);
        $postulacion = $this->postulacionRetirada($convocatoria, $duenio);

        $this->actingAs($otro)
            ->postJson("/api/postulaciones/{$postulacion->id}/documentos", [
                'codigo' => 'hoja_de_vida',
                'archivo' => $this->pdf(),
            ])
            ->assertNotFound()
            ->assertJsonPath('message', 'Postulación no encontrada.');
    }

    // ── Un obligatorio cargado no se elimina (RF-DOC-006, acuerdo del 4-nov) ──

}
