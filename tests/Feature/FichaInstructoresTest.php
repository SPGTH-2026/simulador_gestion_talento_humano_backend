<?php

namespace Tests\Feature;

use App\Enums\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FichaCrudAyudas;
use Tests\TestCase;

class FichaInstructoresTest extends TestCase
{
    use RefreshDatabase;
    use FichaCrudAyudas;

    public function test_el_super_admin_asigna_un_instructor_a_la_ficha(): void
    {
        $ficha = $this->ficha('3173334');
        $instructor = $this->usuario(Role::Instructor);

        $this->actingAs($this->superAdmin())
            ->postJson("/api/fichas/{$ficha->id}/instructores", ['usuario_id' => $instructor->id])
            ->assertOk()
            ->assertJsonPath('data.instructores.0.id', $instructor->id);

        $this->assertDatabaseHas('ficha_instructor', [
            'ficha_id' => $ficha->id,
            'usuario_id' => $instructor->id,
        ]);

        // La asignación también amplía el alcance del instructor.
        $this->assertContains($ficha->id, $instructor->fresh()->fichaIds());
    }

    public function test_el_super_admin_quita_un_instructor_de_la_ficha(): void
    {
        $ficha = $this->ficha('3173334');
        $otra = $this->ficha('3173335');
        $instructor = $this->usuario(Role::Instructor);
        $ficha->instructores()->attach($instructor->id);
        // El instructor debe quedar en otra ficha: quitarlo de esta no lo deja sin ficha.
        $otra->instructores()->attach($instructor->id);

        $this->actingAs($this->superAdmin())
            ->deleteJson("/api/fichas/{$ficha->id}/instructores", ['usuario_id' => $instructor->id])
            ->assertOk()
            ->assertJsonCount(0, 'data.instructores');

        $this->assertDatabaseMissing('ficha_instructor', [
            'ficha_id' => $ficha->id,
            'usuario_id' => $instructor->id,
        ]);
    }

    public function test_no_se_puede_asignar_un_usuario_que_no_es_instructor(): void
    {
        $ficha = $this->ficha('3173334');
        $aspirante = $this->usuario(Role::Aspirante);

        $this->actingAs($this->superAdmin())
            ->postJson("/api/fichas/{$ficha->id}/instructores", ['usuario_id' => $aspirante->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('usuario_id');
    }

    public function test_asignar_instructor_exige_un_usuario_existente(): void
    {
        $ficha = $this->ficha('3173334');

        $this->actingAs($this->superAdmin())
            ->postJson("/api/fichas/{$ficha->id}/instructores", ['usuario_id' => 999999])
            ->assertStatus(422)
            ->assertJsonValidationErrors('usuario_id');
    }


    public function test_no_quita_al_instructor_de_su_unica_ficha(): void
    {
        $ficha = $this->ficha('3173334');
        $instructor = $this->usuario(Role::Instructor);
        $ficha->instructores()->attach($instructor->id);

        $this->actingAs($this->superAdmin())
            ->deleteJson("/api/fichas/{$ficha->id}/instructores", ['usuario_id' => $instructor->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('usuario_id');

        $this->assertDatabaseHas('ficha_instructor', ['ficha_id' => $ficha->id, 'usuario_id' => $instructor->id]);
    }
}
