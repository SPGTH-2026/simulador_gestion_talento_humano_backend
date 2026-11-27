<?php

namespace Tests\Feature;

use App\Enums\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\UserManagementAyudas;
use Tests\TestCase;

class UserManagementRolesTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementAyudas;

    public function test_super_admin_puede_hacer_instructor(): void
    {
        $admin = $this->usuario(Role::SuperAdmin);
        $aspirante = $this->usuario(Role::Aspirante, null, ['ficha_id' => \App\Models\Ficha::create(['codigo' => '3173334', 'nombre_programa' => 'ADSO'])->id]);
        $ficha = \App\Models\Ficha::create(['codigo' => '3173335', 'nombre_programa' => 'ADSO']);

        // Un instructor vive en al menos una ficha: se asignan en la misma acción.
        $this->actingAs($admin)->patchJson("/api/usuarios/{$aspirante->id}", [
            'role' => 'instructor',
            'active' => true,
            'fichas' => [$ficha->id],
        ])->assertOk()->assertJsonPath('data.role', 'instructor');

        $this->assertSame([$ficha->id], $aspirante->fresh()->fichas()->pluck('fichas.id')->all());
    }

    public function test_convertir_en_instructor_con_una_ficha_no_avisa_que_cree_la_suya(): void
    {
        $admin = $this->usuario(Role::SuperAdmin);
        $ficha = \App\Models\Ficha::create(['codigo' => '3173335', 'nombre_programa' => 'ADSO']);
        $aspirante = $this->usuario(Role::Aspirante, null, ['ficha_id' => $ficha->id]);

        $this->actingAs($admin)->patchJson("/api/usuarios/{$aspirante->id}", [
            'role' => 'instructor',
            'active' => true,
            'fichas' => [$ficha->id],
        ])->assertOk();

        $this->assertDatabaseMissing('notificaciones', ['user_id' => $aspirante->id, 'tipo' => 'instructor_crear_ficha']);
    }

    public function test_super_admin_puede_crear_instructor_sin_ficha_y_el_instructor_crea_la_suya(): void
    {
        $admin = $this->usuario(Role::SuperAdmin);
        $aspirante = $this->usuario(Role::Aspirante, null, ['ficha_id' => \App\Models\Ficha::create(['codigo' => '3173334', 'nombre_programa' => 'ADSO'])->id]);

        $this->actingAs($admin)->patchJson("/api/usuarios/{$aspirante->id}", [
            'role' => 'instructor',
            'active' => true,
        ])->assertOk()->assertJsonPath('data.role', 'instructor');

        $instructor = $aspirante->fresh();
        $this->assertSame([], $instructor->fichas()->pluck('fichas.id')->all());
        // Sin fichas, el instructor recibe el aviso para crear la suya.
        $this->assertDatabaseHas('notificaciones', ['user_id' => $aspirante->id, 'tipo' => 'instructor_crear_ficha']);

        // Su propia ficha: la registra él y queda a su nombre, así que ya la administra.
        $this->actingAs($instructor)->postJson('/api/fichas', [
            'codigo' => '9000001',
            'nombre_programa' => 'Mi programa',
            'estado' => 'activa',
        ])->assertCreated();
        $this->assertContains(
            \App\Models\Ficha::where('codigo', '9000001')->value('id'),
            $instructor->fresh()->fichaIds(),
        );
    }

    public function test_nadie_modifica_su_propio_usuario(): void
    {
        $admin = $this->usuario(Role::SuperAdmin);

        $this->actingAs($admin)->patchJson("/api/usuarios/{$admin->id}", [
            'role' => 'super_admin',
            'active' => true,
        ])->assertStatus(422)->assertJsonValidationErrors('role');
    }

    // ── Alcance entre instructores ─────────────────────────────────────

    public function test_instructor_no_lista_aspirantes_ni_aprendices_de_ficha_ajena(): void
    {
        [$a, , $fichaA, $fichaB] = $this->dosInstructores();
        $aprendizA = $this->usuario(Role::Aprendiz, 'general', ['ficha_id' => $fichaA->id]);
        $aspiranteA = $this->usuario(Role::Aspirante, null, ['ficha_id' => $fichaA->id]);
        $aprendizB = $this->usuario(Role::Aprendiz, 'general', ['ficha_id' => $fichaB->id]);
        $aspiranteB = $this->usuario(Role::Aspirante, null, ['ficha_id' => $fichaB->id]);
        $libre = $this->usuario(Role::Aspirante);

        $ids = collect($this->actingAs($a)->getJson('/api/usuarios')->json('data'))->pluck('id')->all();

        $this->assertContains($aprendizA->id, $ids);
        $this->assertContains($aspiranteA->id, $ids);
        // Un aspirante sin ficha no existe para el instructor: no aparece en su lista.
        $this->assertNotContains($libre->id, $ids);
        $this->assertNotContains($aprendizB->id, $ids);
        $this->assertNotContains($aspiranteB->id, $ids);

        $porRol = collect($this->actingAs($a)->getJson('/api/usuarios?role=aspirante')->json('data'))->pluck('id')->all();
        $this->assertContains($aspiranteA->id, $porRol);
        $this->assertNotContains($aspiranteB->id, $porRol);
    }

}
