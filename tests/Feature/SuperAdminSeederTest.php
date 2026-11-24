<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Database\Seeders\SuperAdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El super admin sale de las variables de entorno: SUPER_ADMIN_EMAIL y SUPER_ADMIN_PASSWORD. Cualquier otra cuenta con ese
 * rol se inhabilita.
 */
class SuperAdminSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        putenv('SUPER_ADMIN_EMAIL');
        putenv('SUPER_ADMIN_PASSWORD');

        parent::tearDown();
    }

    public function test_da_el_rol_a_la_cuenta_de_entorno_e_inhabilita_las_demas(): void
    {
        $viejo = User::factory()->create(['email' => 'viejo@test.com', 'role' => Role::SuperAdmin, 'active' => true]);
        putenv('SUPER_ADMIN_EMAIL=spgth2026@gmail.com');
        putenv('SUPER_ADMIN_PASSWORD=Clave-segura-123');

        (new SuperAdminSeeder)->run();

        $admin = User::where('email', 'spgth2026@gmail.com')->first();
        $this->assertNotNull($admin);
        $this->assertSame(Role::SuperAdmin, $admin->role);
        $this->assertTrue((bool) $admin->active);

        $this->assertFalse((bool) $viejo->fresh()->active);
        $this->assertSame(1, User::where('role', Role::SuperAdmin->value)->where('active', true)->count());
    }
}
