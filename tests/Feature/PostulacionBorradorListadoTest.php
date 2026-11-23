<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\PostulacionBorradorAyudas;
use Tests\TestCase;

class PostulacionBorradorListadoTest extends TestCase
{
    use RefreshDatabase;
    use PostulacionBorradorAyudas;

    public function test_un_borrador_no_entra_a_revision(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $revisor = $this->revisor($ficha);
        $convocatoria = $this->convocatoriaAbierta($ficha, $instructor);
        $postulacion = $this->crearPostulacion($convocatoria, $aspirante);

        $this->actingAs($revisor)
            ->getJson("/api/revision/postulaciones/{$postulacion->id}/documentos")
            ->assertNotFound()
            ->assertJsonPath('message', 'Postulación no encontrada.');
    }

    // ── Visibilidad: listado propio, mi_postulacion y conteos ──────────

    public function test_el_listado_de_mis_postulaciones_incluye_el_borrador(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->convocatoriaAbierta($ficha, $instructor);
        $postulacion = $this->crearPostulacion($convocatoria, $aspirante);

        $this->actingAs($aspirante)
            ->getJson('/api/postulaciones')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $postulacion->id)
            ->assertJsonPath('data.0.estado.codigo', 'borrador');
    }

    public function test_mi_postulacion_expone_el_borrador(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->convocatoriaAbierta($ficha, $instructor);
        $postulacion = $this->crearPostulacion($convocatoria, $aspirante);

        $this->actingAs($aspirante)
            ->getJson("/api/convocatorias/{$convocatoria->id}")
            ->assertOk()
            ->assertJsonPath('data.mi_postulacion.id', $postulacion->id)
            ->assertJsonPath('data.mi_postulacion.estado.codigo', 'borrador');
    }

    public function test_el_borrador_no_cuenta_como_postulante(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->convocatoriaAbierta($ficha, $instructor);
        $postulacion = $this->crearPostulacion($convocatoria, $aspirante);

        $this->actingAs($instructor)
            ->getJson("/api/convocatorias/{$convocatoria->id}")
            ->assertOk()
            ->assertJsonPath('data.postulantes', 0);

        // Tras enviar, sí cuenta.
        $this->actingAs($aspirante)
            ->postJson("/api/postulaciones/{$postulacion->id}/enviar")
            ->assertOk();

        $this->actingAs($instructor)
            ->getJson("/api/convocatorias/{$convocatoria->id}")
            ->assertOk()
            ->assertJsonPath('data.postulantes', 1);
    }

}
