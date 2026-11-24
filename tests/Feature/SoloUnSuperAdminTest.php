<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Solo existe un super admin (la cuenta de SUPER_ADMIN_EMAIL). Ninguna ruta de la aplicación crea ni cambia uno.
 */
class SoloUnSuperAdminTest extends TestCase
{
    use RefreshDatabase;

    private function usuario(Role $rol, string $email): User
    {
        return User::factory()->create([
            'email' => $email,
            'role' => $rol,
            'active' => true,
            'email_verified_at' => now(),
        ]);
    }

    public function test_super_admin_no_puede_asignar_el_rol_super_admin(): void
    {
        $admin = $this->usuario(Role::SuperAdmin, 'spgth2026@gmail.com');
        $aprendiz = $this->usuario(Role::Aspirante, 'aspirante@test.com');

        $this->actingAs($admin)->patchJson("/api/usuarios/{$aprendiz->id}", [
            'role' => 'super_admin',
            'active' => true,
        ])->assertStatus(422)->assertJsonValidationErrors('role');

        $this->assertSame(Role::Aspirante, $aprendiz->fresh()->role);
    }

    public function test_nadie_modifica_la_cuenta_super_admin_desde_la_aplicacion(): void
    {
        $admin = $this->usuario(Role::SuperAdmin, 'spgth2026@gmail.com');
        $otroAdmin = $this->usuario(Role::SuperAdmin, 'otro@test.com');

        $this->actingAs($admin)->patchJson("/api/usuarios/{$otroAdmin->id}", [
            'role' => 'instructor',
            'active' => true,
        ])->assertStatus(422)->assertJsonValidationErrors('role');

        $this->assertSame(Role::SuperAdmin, $otroAdmin->fresh()->role);
    }

    public function test_el_comando_no_crea_un_segundo_super_admin(): void
    {
        $this->usuario(Role::SuperAdmin, 'spgth2026@gmail.com');

        $this->artisan('app:make-admin', ['email' => 'otro@test.com', '--password' => $this->clavePrueba()])
            ->assertExitCode(1);

        $this->assertDatabaseMissing('users', ['email' => 'otro@test.com']);
    }

    public function test_el_comando_reafirma_al_mismo_super_admin(): void
    {
        $this->usuario(Role::SuperAdmin, 'spgth2026@gmail.com');

        $this->artisan('app:make-admin', ['email' => 'spgth2026@gmail.com', '--password' => $this->clavePrueba()])
            ->assertExitCode(0);

        $this->assertSame(1, User::where('role', Role::SuperAdmin->value)->count());
    }
}
