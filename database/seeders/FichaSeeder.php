<?php

namespace Database\Seeders;

use App\Models\Ficha;
use App\Models\User;
use Illuminate\Database\Seeder;

class FichaSeeder extends Seeder
{
    public function run(): void
    {
        // Ficha única del proyecto. firstOrCreate hace el seeder idempotente.
        $ficha = Ficha::firstOrCreate(
            ['codigo' => '3173334'],
            ['nombre_programa' => 'ADSO DESARROLLADORES']
        );

        // Los usuarios demo quedan ligados a la única ficha para que la app
        // funcione en desarrollo con datos reales.
        if ($instructor = User::firstWhere('email', 'instructor@test.com')) {
            $instructor->fichas()->syncWithoutDetaching([$ficha->id]);
        }

        foreach (['general@test.com', 'evaluador@test.com', 'seleccionador@test.com', 'revisor@test.com', 'gestor@test.com'] as $email) {
            if ($aprendiz = User::firstWhere('email', $email)) {
                $aprendiz->forceFill(['ficha_id' => $ficha->id])->save();
            }
        }
    }
}