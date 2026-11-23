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
        $aspirante = $this->usuario(Role::Aspirante);

        $this->actingAs($admin)->patchJson("/api/usuarios/{$aspirante->id}", [
            'role' => 'instructor',
            'active' => true,
        ])->assertOk()->assertJsonPath('data.role', 'instructor');
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
        $this->assertContains($libre->id, $ids);
        $this->assertNotContains($aprendizB->id, $ids);
        $this->assertNotContains($aspiranteB->id, $ids);

        $porRol = collect($this->actingAs($a)->getJson('/api/usuarios?role=aspirante')->json('data'))->pluck('id')->all();
        $this->assertContains($aspiranteA->id, $porRol);
        $this->assertNotContains($aspiranteB->id, $porRol);
    }

}
