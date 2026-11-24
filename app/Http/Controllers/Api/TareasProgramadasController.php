<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

/**
 * Disparador de tareas programadas para hosting sin cron (plan gratuito). Un servicio externo llama cada día y este
 * controlador ejecuta la tarea.
 *
 * Seguridad:
 *  - Solo ejecuta tareas de una lista cerrada: nunca un comando que venga en la petición.
 *  - El token va en la cabecera Authorization (no en la URL, que quedaría en los logs) y se compara en tiempo constante.
 *  - Sin token configurado (CRON_TOKEN vacío) el endpoint no existe para nadie (404).
 *  - La autenticación va antes que la búsqueda de la tarea, para no revelar qué tareas hay.
 *  - La ruta tiene límite de peticiones (throttle) en routes/api.php.
 */
class TareasProgramadasController extends Controller
{
    /** Tareas que se pueden disparar desde fuera: nombre público => comando de Artisan. */
    private const TAREAS = [
        'cerrar-vencidas' => 'convocatorias:cerrar-vencidas',
        'resumen-diario' => 'notificaciones:resumen-diario',
    ];

    public function ejecutar(Request $request, string $tarea): JsonResponse
    {
        $esperado = (string) config('tareas.cron_token', '');

        if ($esperado === '') {
            abort(404);
        }

        $recibido = (string) $request->bearerToken();

        if ($recibido === '' || ! hash_equals($esperado, $recibido)) {
            return response()->json(['message' => 'No autorizado.'], 401);
        }

        if (! array_key_exists($tarea, self::TAREAS)) {
            abort(404);
        }

        $codigo = Artisan::call(self::TAREAS[$tarea]);

        Log::info('Tarea programada ejecutada desde el disparador externo.', [
            'tarea' => $tarea,
            'codigo_salida' => $codigo,
        ]);

        return response()->json(['data' => ['tarea' => $tarea, 'ok' => $codigo === 0]]);
    }
}
