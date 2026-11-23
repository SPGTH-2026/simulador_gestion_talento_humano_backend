<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RolePermissionsTest extends TestCase
{
    use RefreshDatabase;

    private function usuario(Role $role, ?string $subrole = null): User
    {
        return User::factory()->create([
            'role' => $role,
            'subrole' => $subrole,
            'email_verified_at' => now(),
        ]);
    }

    public function test_super_admin_tiene_todos_los_permisos(): void
    {
        $u = $this->usuario(Role::SuperAdmin);
        $todos = config('permissions.all');

        $this->assertSame($todos, $u->permissions());
        $this->assertCount(15, $todos);
    }

    public function test_instructor_lo_tiene_todo_menos_los_exclusivos(): void
    {
        $u = $this->usuario(Role::Instructor);

        // Usuarios:gestionar ahora es del instructor (ver config/permissions.php);
        $this->assertContains('usuarios:gestionar', $u->permissions());

        // Todo lo demas si.
        $this->assertSame(
            array_values(array_diff(config('permissions.all'), config('permissions.super_admin_only'))),
            $u->permissions()
        );
    }

    public function test_general_es_el_subrol_mas_amplio_de_aprendiz(): void
    {
        // Ojo: segun config/permissions.php el subrol 'general' es el mas amplio
        // (incluye seleccion:decidir). El mas restringido es 'evaluador'.
        $general = $this->usuario(Role::Aprendiz, 'general');
        $evaluador = $this->usuario(Role::Aprendiz, 'evaluador');

        $this->assertContains('seleccion:decidir', $general->permissions());
        $this->assertGreaterThan(
            count($evaluador->permissions()),
            count($general->permissions())
        );

        // Ningun aprendiz tiene usuarios:gestionar.
        foreach ([$general, $evaluador] as $u) {
            $this->assertNotContains('usuarios:gestionar', $u->permissions());
        }
    }

    public function test_aspirante_es_el_mas_restringido(): void
    {
        $u = $this->usuario(Role::Aspirante);

        $this->assertNotContains('usuarios:gestionar', $u->permissions());
        $this->assertNotContains('seleccion:decidir', $u->permissions());
    }

    public function test_seeder_marca_las_cuentas_de_demo_como_verificadas(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertGreaterThan(0, User::whereNotNull('email_verified_at')->count());
        $this->assertSame(15, count(User::where('email', 'admin@test.com')->first()->permissions()));
    }
}
