<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class MakeAdmin extends Command
{
    protected $signature = 'app:make-admin
                           {email : Correo del super admin}
                           {--password= : Contraseña (si no se pasa y es un usuario nuevo, se genera una)}';

    protected $description = 'Crea el super admin o promueve un usuario existente. Idempotente.';

    public function handle(): int
    {
        $email = strtolower(trim($this->argument('email')));

        $user = User::firstWhere('email', $email);
        $creado = false;

        if (! $user) {
            $password = $this->option('password') ?: Str::password(16);

            // forceCreate: role y active no son asignables en masa.
            $user = User::forceCreate([
                'name' => $email,
                'email' => $email,
                'password' => $password,
                'role' => Role::SuperAdmin,
                'subrole' => null,
                'active' => true,
                'email_verified_at' => now(),
            ]);
            $creado = true;

            // La contraseña generada solo se ve una vez; en los logs no aparece.
            if (! $this->option('password')) {
                $this->components->info("Contraseña generada (guárdala; no se mostrará de nuevo): {$password}");
            }
        } else {
            $user->forceFill([
                'role' => Role::SuperAdmin,
                'subrole' => null,
                'active' => true,
                'email_verified_at' => $user->email_verified_at ?? now(),
            ])->save();
        }

        $this->components->info(($creado ? 'Super admin creado: ' : 'Usuario promovido a super admin: ') . $user->email);

        return self::SUCCESS;
    }
}