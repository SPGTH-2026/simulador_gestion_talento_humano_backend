<?php

namespace Database\Seeders;

use App\Models\Ficha;
use Illuminate\Database\Seeder;

class FichaSeeder extends Seeder
{
    public function run(): void
    {
        // Ficha base del proyecto. El seeder SOLO la crea si no existe
        // (firstOrCreate) y NUNCA borra fichas: el instructor podrá crear más
        // fichas desde la aplicación y un despliegue no debe eliminarlas.
        Ficha::firstOrCreate(
            ['codigo' => '3173334'],
            ['nombre_programa' => 'ADSO DESARROLLADORES']
        );
    }
}