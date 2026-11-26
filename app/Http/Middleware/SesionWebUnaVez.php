<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Routing\Router;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sesión web para las rutas de acceso con Google que viven en routes/api.php, iniciada UNA sola vez por petición.
 *
 * Con statefulApi(), Sanctum ya abre la sesión (cookies cifradas, sesión y CSRF) cuando la petición viene del front. Si además
 * corría el grupo 'web', la cookie se descifraba dos veces: la segunda fallaba, la sesión arrancaba con un id nuevo y el
 * manejador de base de datos intentaba un UPDATE sobre una fila inexistente, así que no se guardaba nada (el state del inicio
 * se perdía y el callback lo rechazaba como 'ausente'). Aquí:
 *  - petición del front (Sanctum ya la atendió): se usa esa sesión tal cual;
 *  - cualquier otra (la vuelta desde Google, una navegación directa): corre el grupo 'web' completo, CSRF incluido.
 */
class SesionWebUnaVez
{
    public function __construct(private readonly Router $router) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->attributes->get('sanctum') === true && $request->hasSession()) {
            return $next($request);
        }

        return (new Pipeline(app()))
            ->send($request)
            ->through($this->router->getMiddlewareGroups()['web'] ?? [])
            ->then($next);
    }
}
