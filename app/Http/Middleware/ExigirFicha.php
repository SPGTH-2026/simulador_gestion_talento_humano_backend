<?php

namespace App\Http\Middleware;

use App\Enums\Role;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * La ficha es obligatoria para el aspirante: sin ficha no usa las rutas de negocio. El frontend ya lo redirige a
 * /completar-ficha; esto lo garantiza también en la API.
 *
 * Quedan libres el catálogo (parametros) y el perfil (sexo), que el aspirante necesita antes de asociarse a la ficha.
 */
class ExigirFicha
{
    private const RUTAS_LIBRES = ['api/parametros', 'api/parametros/*', 'api/perfil', 'api/perfil/*'];

    public function handle(Request $request, Closure $next): Response
    {
        $usuario = $request->user();

        if ($request->is(self::RUTAS_LIBRES)) {
            return $next($request);
        }

        if ($usuario !== null && $usuario->role === Role::Aspirante && $usuario->ficha_id === null) {
            return response()->json([
                'message' => 'Antes debes asociarte a una ficha.',
                'code' => 'ficha_requerida',
            ], 403);
        }

        return $next($request);
    }
}
