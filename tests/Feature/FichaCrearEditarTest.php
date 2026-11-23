<?php

namespace Tests\Feature;

use App\Models\Ficha;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FichaCrudAyudas;
use Tests\TestCase;

class FichaCrearEditarTest extends TestCase
{
    use RefreshDatabase;
    use FichaCrudAyudas;

    public function test_el_instructor_crea_una_ficha_y_queda_dueno_de_ella(): void
    {
        $instructor = $this->instructor();

        $this->actingAs($instructor)
            ->postJson('/api/fichas', [
                'codigo' => '3173334',
                'nombre_programa' => 'Análisis y Desarrollo de Software',
            ])
            ->assertCreated()
            ->assertJsonPath('data.codigo', '3173334')
            ->assertJsonPath('data.estado', 'activa')
            ->assertJsonPath('data.creado_por.id', $instructor->id)
            ->assertJsonPath('data.puede_gestionar', true);

        // Auto-ownership: la ficha creada entra en su alcance.
        $this->assertContains(
            Ficha::where('codigo', '3173334')->firstOrFail()->id,
            $instructor->fresh()->fichaIds(),
        );
    }

    public function test_el_codigo_de_la_ficha_es_unico(): void
    {
        $this->ficha('3173334');

        $this->actingAs($this->instructor())
            ->postJson('/api/fichas', [
                'codigo' => '3173334',
                'nombre_programa' => 'Análisis y Desarrollo de Software',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('codigo');
    }

    public function test_la_ficha_no_acepta_campos_reservados(): void
    {
        $this->actingAs($this->instructor())
            ->postJson('/api/fichas', [
                'codigo' => '3173334',
                'nombre_programa' => 'Análisis y Desarrollo de Software',
                'creado_por' => 999,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('campos');
    }

    public function test_la_ficha_exige_codigo_y_nombre(): void
    {
        $this->actingAs($this->instructor())
            ->postJson('/api/fichas', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['codigo', 'nombre_programa']);
    }

    // ── Detalle y edición ──────────────────────────────────────────────

    public function test_el_detalle_de_una_ficha_fuera_de_alcance_responde_404(): void
    {
        $ajena = $this->ficha('2222222');

        $this->actingAs($this->instructor())
            ->getJson("/api/fichas/{$ajena->id}")
            ->assertNotFound();
    }

    public function test_el_instructor_actualiza_la_ficha_que_creo(): void
    {
        $instructor = $this->instructor();
        $ficha = $this->ficha('3173334', ['creado_por' => $instructor->id]);

        $this->actingAs($instructor)
            ->patchJson("/api/fichas/{$ficha->id}", [
                'nombre_programa' => 'Programación de Software',
                'estado' => 'cerrada',
            ])
            ->assertOk()
            ->assertJsonPath('data.nombre_programa', 'Programación de Software')
            ->assertJsonPath('data.estado', 'cerrada');
    }

    public function test_el_instructor_no_actualiza_una_ficha_ajena_su_alcance(): void
    {
        $ficha = $this->ficha('3173334');
        $instructor = $this->instructor($ficha);

        $this->actingAs($instructor)
            ->patchJson("/api/fichas/{$ficha->id}", ['nombre_programa' => 'Otro programa'])
            ->assertForbidden();
    }

    public function test_actualizar_una_ficha_fuera_de_alcance_responde_404(): void
    {
        $ajena = $this->ficha('2222222');

        $this->actingAs($this->instructor())
            ->patchJson("/api/fichas/{$ajena->id}", ['nombre_programa' => 'Otro programa'])
            ->assertNotFound();
    }

    public function test_una_ficha_inexistente_responde_404(): void
    {
        $this->actingAs($this->superAdmin())
            ->getJson('/api/fichas/999999')
            ->assertNotFound();
    }

    // ── Baja ───────────────────────────────────────────────────────────

}
