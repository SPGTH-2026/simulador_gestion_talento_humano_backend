<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Controla las sesiones por cookie (SPA con Sanctum stateful).
 *
 * Sanctum::authenticateAccessTokensUsing solo corre para tokens Bearer. Con una sesión web, la expiración por
 * inactividad (sanctum.idle_minutes) y la desactivación de un usuario no se aplicaban nunca. Este middleware lo cubre:
 *  - un usuario inactivo pierde la sesión en la siguiente petición;
 *  - una sesión sin actividad durante más de idle_minutes se cierra.
 */
class VerificarSesionActiva
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasSession()) {
            return $next($request);
        }

        $guard = Auth::guard('web');
        $user = $guard->user();

        // Sin usuario de sesión (invitado o petición con token): lo decide la propia ruta.
        if ($user === null) {
            return $next($request);
        }

        $ahora = now()->timestamp;
        $ultimo = $request->session()->get('ultimo_acceso');
        $limite = (int) config('sanctum.idle_minutes', 15) * 60;
        $inactiva = $ultimo !== null && ($ahora - (int) $ultimo) > $limite;

        // Solo se corta con un «false» real de la base de datos (el cast boolean lo garantiza al cargar el usuario).
        $desactivado = $user->active === false;

        if ($desactivado || $inactiva) {
            $guard->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return response()->json([
                'message' => 'Tu sesión ya no es válida. Vuelve a iniciar sesión.',
            ], 401);
        }

        $request->session()->put('ultimo_acceso', $ahora);

        return $next($request);
    }
}
