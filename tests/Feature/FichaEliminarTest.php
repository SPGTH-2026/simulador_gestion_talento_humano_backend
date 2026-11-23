<?php

namespace Tests\Feature;

use App\Enums\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FichaCrudAyudas;
use Tests\TestCase;

class FichaEliminarTest extends TestCase
{
    use RefreshDatabase;
    use FichaCrudAyudas;

    public function test_no_se_puede_eliminar_una_ficha_con_convocatorias(): void
    {
        $ficha = $this->ficha('3173334');
        $this->crearConvocatoria($ficha, $this->superAdmin());

        $this->actingAs($this->superAdmin())
            ->deleteJson("/api/fichas/{$ficha->id}")
            ->assertStatus(422)
            ->assertJsonValidationErrors('ficha');

        $this->assertDatabaseHas('fichas', ['id' => $ficha->id]);
    }

    public function test_no_se_puede_eliminar_una_ficha_con_personas(): void
    {
        $ficha = $this->ficha('3173334');
        $this->usuario(Role::Aspirante, ['ficha_id' => $ficha->id]);

        $this->actingAs($this->superAdmin())
            ->deleteJson("/api/fichas/{$ficha->id}")
            ->assertStatus(422)
            ->assertJsonValidationErrors('ficha');

        $this->assertDatabaseHas('fichas', ['id' => $ficha->id]);
    }

    public function test_el_super_admin_elimina_una_ficha_vacia(): void
    {
        $ficha = $this->ficha('3173334');

        $this->actingAs($this->superAdmin())
            ->deleteJson("/api/fichas/{$ficha->id}")
            ->assertOk()
            ->assertJsonPath('data.eliminada', true);

        $this->assertDatabaseMissing('fichas', ['id' => $ficha->id]);
    }

    public function test_el_instructor_elimina_la_ficha_que_creo(): void
    {
        $instructor = $this->instructor();
        $ficha = $this->ficha('3173334', ['creado_por' => $instructor->id]);

        $this->actingAs($instructor)
            ->deleteJson("/api/fichas/{$ficha->id}")
            ->assertOk();

        $this->assertDatabaseMissing('fichas', ['id' => $ficha->id]);
    }

    public function test_el_instructor_no_elimina_una_ficha_ajena(): void
    {
        $ficha = $this->ficha('3173334');
        $instructor = $this->instructor($ficha);

        $this->actingAs($instructor)
            ->deleteJson("/api/fichas/{$ficha->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('fichas', ['id' => $ficha->id]);
    }

    // ── Instructores de la ficha (solo super admin) ─────────────────────

    public function test_el_instructor_no_puede_asignar_instructores(): void
    {
        $ficha = $this->ficha('3173334');
        $instructor = $this->instructor($ficha);
        $otro = $this->usuario(Role::Instructor);

        $this->actingAs($instructor)
            ->postJson("/api/fichas/{$ficha->id}/instructores", ['usuario_id' => $otro->id])
            ->assertForbidden();

        $this->assertDatabaseMissing('ficha_instructor', [
            'ficha_id' => $ficha->id,
            'usuario_id' => $otro->id,
        ]);
    }

}
