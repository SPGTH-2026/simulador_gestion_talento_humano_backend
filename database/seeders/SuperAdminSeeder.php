<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;

/**
 * Super admin garantizado del sistema.
 *
 * Se ejecuta en CADA arranque desde docker/entrypoint.sh. Lee credenciales
 * de SUPER_ADMIN_EMAIL y SUPER_ADMIN_PASSWORD. No tiene valores por defecto:
 * si alguna falta, no crea ni promueve nada (una contraseña pública sembrada
 * en cada arranque sería una puerta abierta en producción).
 * Si el usuario existe, NO resetea la contraseña; solo asegura rol/activo/verificado.
 */
class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = trim((string) env('SUPER_ADMIN_EMAIL', ''));
        $password = (string) env('SUPER_ADMIN_PASSWORD', '');

        if ($email === '' || $password === '') {
            Log::warning('SuperAdminSeeder omitido: faltan SUPER_ADMIN_EMAIL o SUPER_ADMIN_PASSWORD.');

            return;
        }

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
