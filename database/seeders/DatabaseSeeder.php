<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        // Producción no se siembra: las cuentas de demo nunca deben existir en un
        // despliegue real. Allí el super admin se crea con `php artisan app:make-admin`.
        if (app()->environment('production')) {
            $this->components->info('DatabaseSeeder omitido en producción. Usa app:make-admin.');

            return;
        }

        // Contraseña solo para desarrollo. Se puede cambiar con SEED_PASSWORD en el .env local.
        // No hace falta Hash::make: el cast 'password' => 'hashed' del modelo la hashea solo.
        $password = env('SEED_PASSWORD', 'Dev12345');

        $usuarios = [
            [
                'name' => 'Super Admin Demo',
                'email' => 'admin@test.com',
                'role' => Role::SuperAdmin->value,
                'subrole' => null,
            ],
            [
                'name' => 'Instructor Demo',
                'email' => 'instructor@test.com',
                'role' => Role::Instructor->value,
                'subrole' => null,
            ],
            [
                'name' => 'Aprendiz General Demo',
                'email' => 'general@test.com',
                'role' => Role::Aprendiz->value,
                'subrole' => 'general',
            ],
            [
                'name' => 'Aprendiz Evaluador Demo',
                'email' => 'evaluador@test.com',
                'role' => Role::Aprendiz->value,
                'subrole' => 'evaluador',
            ],
            [
                'name' => 'Aprendiz Seleccionador Demo',
                'email' => 'seleccionador@test.com',
                'role' => Role::Aprendiz->value,
                'subrole' => 'seleccionador',
            ],
            [
                'name' => 'Aprendiz Revisor Documental Demo',
                'email' => 'revisor@test.com',
                'role' => Role::Aprendiz->value,
                'subrole' => 'revisor_documental',
            ],
            [
                'name' => 'Aprendiz Gestor Convocatorias Demo',
                'email' => 'gestor@test.com',
                'role' => Role::Aprendiz->value,
                'subrole' => 'gestor_convocatorias',
            ],
            [
                'name' => 'Aspirante Demo',
                'email' => 'aspirante@test.com',
                'role' => Role::Aspirante->value,
                'subrole' => null,
            ],
        ];

        foreach ($usuarios as $datos) {
            // Si ya existe, no lo duplica (el seeder se puede correr varias veces).
            // Solo se le completa email_verified_at si falta, para que las cuentas de
            // demo sirvan tras exigir 'verified' en las rutas de negocio.
            if ($existente = User::firstWhere('email', $datos['email'])) {
                if (! $existente->email_verified_at) {
                    $existente->forceFill(['email_verified_at' => now()])->save();
                }

                continue;
            }

            // forceCreate: role, subrole y active no son asignables en masa.
            User::forceCreate($datos + [
                'active' => true,
                'password' => $password,
                // Cuentas de demo: se marcan verificadas para poder usar la app
                // sin pasar por el correo (que en local solo escribe en el log).
                'email_verified_at' => now(),
            ]);
        }

        // Las fichas de demo se crean después: necesitan que los usuarios existan
        // para ligar al instructor por el pivot y a los aprendices por ficha_id.
        $this->call(FichaSeeder::class);
    }
}
