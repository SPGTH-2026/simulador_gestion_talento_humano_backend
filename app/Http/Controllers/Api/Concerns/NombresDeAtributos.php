<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Http\Requests\ConvocatoriaRequest;

trait NombresDeAtributos
{
    /** @return array<string, string> */
    private function atributos(): array
    {
        return ConvocatoriaRequest::atributos();
    }
}
