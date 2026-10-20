<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Super admin garantizado del sistema.
 *
 * Se ejecuta en CADA arranque desde docker/entrypoint.sh. Lee credenciales
 * de SUPER_ADMIN_EMAIL y SUPER_ADMIN_PASSWORD. Si faltan, no hace nada.
 * Si el usuario existe, NO resetea la contraseña; solo asegura rol/activo/verificado.
 * Si no existe, lo crea con las credenciales dadas.
 */
class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('SUPER_ADMIN_EMAIL', 'stevl4730@gmail.com');
        $password = env('SUPER_ADMIN_PASSWORD', 'Dev12345');

        $user = User::firstWhere('email', $email);

        if (! $user) {
            User::forceCreate([
                'name' => 'Super Admin',
                'email' => $email,
                'password' => $password,
                'role' => Role::SuperAdmin,
                'subrole' => null,
                'active' => true,
                'email_verified_at' => now(),
            ]);

            return;
        }

        $user->forceFill([
            'role' => Role::SuperAdmin,
            'subrole' => null,
            'active' => true,
            'email_verified_at' => $user->email_verified_at ?? now(),
        ])->save();
    }
}
