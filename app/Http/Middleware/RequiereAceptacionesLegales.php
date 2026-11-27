<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Las rutas de textos legales y aceptaciones responden 404 mientras la bandera legal.aceptaciones_activas esté apagada. */
class RequiereAceptacionesLegales
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(config('legal.aceptaciones_activas'), 404);

        return $next($request);
    }
}
