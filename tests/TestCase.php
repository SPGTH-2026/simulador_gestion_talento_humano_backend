<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Str;

abstract class TestCase extends BaseTestCase
{
    private ?string $clavePrueba = null;

    private ?string $clavePruebaNueva = null;

    /**
     * Contraseña de prueba. Se genera en cada ejecución: no queda escrita en el código.
     */
    protected function clavePrueba(): string
    {
        return $this->clavePrueba ??= 'Prueba'.Str::random(10).'1';
    }

    /** Segunda contraseña de prueba, distinta de la primera (para cambios de contraseña). */
    protected function clavePruebaNueva(): string
    {
        return $this->clavePruebaNueva ??= 'Nueva'.Str::random(10).'2';
    }

    /**
     * Sanctum statefulApi() solo añade el middleware de sesión si la petición
     * viene de un dominio stateful (cabecera Referer/Origin del front).
     * Sin esto, login() falla con "Session store not set on request".
     * Se replica aquí lo que envía el SPA real.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader('Referer', config('app.frontend_url'));
    }
}
