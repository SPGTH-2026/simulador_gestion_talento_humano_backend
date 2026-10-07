<?php

namespace Database\Seeders;

use App\Models\Ficha;
use Illuminate\Database\Seeder;

class FichaSeeder extends Seeder
{
    public function run(): void
    {
        // Ficha única por el momento: el proyecto arranca solo con la de
        // desarrolladores. firstOrCreate hace el seeder idempotente.
        $ficha = Ficha::firstOrCreate(
            ['codigo' => '3173334'],
            ['nombre_programa' => 'ADSO DESARROLLADORES']
        );

        // "Solo la ficha de desarrolladores": elimina cualquier otra ficha que
        // quede de seeds demo previos. Es seguro: ficha_instructor borra en
        // cascada y users.ficha_id queda NULL (nullOnDelete).
        Ficha::where('id', '!=', $ficha->id)->delete();
    }
}