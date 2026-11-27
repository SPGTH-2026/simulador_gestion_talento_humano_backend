<?php

namespace Database\Seeders;

use App\Models\Departamento;
use App\Models\Municipio;
use Illuminate\Database\Seeder;

/**
 * Departamentos y municipios de Colombia desde database/data/divipola.json (DANE, DIVIPOLA: 33 departamentos y 1.122
 * municipios, islas y áreas no municipalizadas). Idempotente: se puede correr en cada arranque sin duplicar nada.
 */
class UbicacionSeeder extends Seeder
{
    public function run(): void
    {
        $datos = json_decode(file_get_contents(database_path('data/divipola.json')), true, 512, JSON_THROW_ON_ERROR);

        // El código es la clave: upsert actualiza el nombre si el DANE lo corrige y no duplica nunca.
        Departamento::upsert($datos['departamentos'], ['codigo'], ['nombre']);

        foreach (array_chunk($datos['municipios'], 300) as $lote) {
            Municipio::upsert($lote, ['codigo'], ['departamento_codigo', 'nombre', 'tipo']);
        }
    }
}
