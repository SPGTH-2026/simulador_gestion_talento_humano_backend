<?php

namespace Tests\Concerns;

use Database\Seeders\ParametroSeeder;

trait ParametroSeederAyudas
{
    private function ejecutarSeeder(): void
    {
        $this->seed(ParametroSeeder::class);
    }
}
