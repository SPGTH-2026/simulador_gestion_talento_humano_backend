<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ConvocatoriaEstadoAyudas;
use Tests\TestCase;

class ConvocatoriaEstadoAccesoTest extends TestCase
{
    use RefreshDatabase;
    use ConvocatoriaEstadoAyudas;

    public function test_sin_autenticacion_responde_401(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->crearConvocatoria($ficha, $instructor);

        $this->patchJson('/api/convocatorias/'.$convocatoria->id.'/estado', ['estado' => 'publicada'])
            ->assertStatus(401);
    }

    public function test_aspirante_sin_permiso_responde_403(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->conEstado($ficha, $instructor, 'publicada');

        $this->actingAs($aspirante)
            ->patchJson('/api/convocatorias/'.$convocatoria->id.'/estado', ['estado' => 'en_proceso'])
            ->assertStatus(403);
    }

    public function test_fuera_de_alcance_responde_404(): void
    {
        $fichaA = $this->ficha('3173334');
        $fichaB = $this->ficha('2631620');
        $instructorA = $this->instructor($fichaA);
        $instructorB = $this->instructor($fichaB);
        $ajena = $this->crearConvocatoria($fichaB, $instructorB);

        $this->actingAs($instructorA)
            ->patchJson('/api/convocatorias/'.$ajena->id.'/estado', ['estado' => 'publicada'])
            ->assertStatus(404);
    }

    public function test_convocatoria_inexistente_responde_404(): void
    {
        $instructor = $this->instructor($this->ficha());

        $this->actingAs($instructor)
            ->patchJson('/api/convocatorias/999999/estado', ['estado' => 'publicada'])
            ->assertStatus(404);
    }

    // ── Resolución por código ──────────────────────────────────────────

    public function test_estado_numerico_responde_422(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->crearConvocatoria($ficha, $instructor);

        $this->actingAs($instructor)
            ->patchJson('/api/convocatorias/'.$convocatoria->id.'/estado', ['estado' => 2])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['estado']);

        $this->actingAs($instructor)
            ->patchJson('/api/convocatorias/'.$convocatoria->id.'/estado', ['estado' => '2'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['estado']);
    }

    public function test_estado_inexistente_responde_422(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->crearConvocatoria($ficha, $instructor);

        $this->actingAs($instructor)
            ->patchJson('/api/convocatorias/'.$convocatoria->id.'/estado', ['estado' => 'inexistente'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['estado']);
    }

    // ── Transiciones ───────────────────────────────────────────────────

}
