<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cabecera Server-Timing con el tiempo de la aplicación, el de la base de datos
 * y el número de consultas de la petición. Se activa con SERVER_TIMING=true.
 * No expone SQL: solo cifras agregadas.
 */
class ServerTiming
{
    private static bool $registrado = false;

    private static int $consultas = 0;

    private static float $msBd = 0.0;

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('app.server_timing')) {
            return $next($request);
        }

        if (! self::$registrado) {
            DB::listen(function ($consulta) {
                self::$consultas++;
                self::$msBd += $consulta->time;
            });
            self::$registrado = true;
        }

        self::$consultas = 0;
        self::$msBd = 0.0;

        $inicio = microtime(true);
        $respuesta = $next($request);
        $msApp = (microtime(true) - $inicio) * 1000;

        $respuesta->headers->set('Server-Timing', sprintf(
            'app;dur=%.1f, db;dur=%.1f;desc="%d consultas"',
            $msApp,
            self::$msBd,
            self::$consultas,
        ));

        return $respuesta;
    }
}
