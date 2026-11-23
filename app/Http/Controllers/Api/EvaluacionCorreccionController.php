<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Api\Concerns\ValidaEvaluacion;
use App\Http\Controllers\Controller;
use App\Models\Postulacion;
use App\Services\RondasCorreccion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Autorización excepcional de una ronda de corrección.
 *
 * La dan el Instructor (postulaciones de sus fichas) y el Superadmin (todas), con una justificación de 10 a 500
 * caracteres. Procede tras un vencimiento sin respuesta o al agotarse las dos rondas ordinarias; solo puede haber una
 * autorización sin usar por postulación. Después, el evaluador pide la ronda indicando los documentos y la observación.
 */
class EvaluacionCorreccionController extends Controller
{
    use ValidaEvaluacion;

    public function autorizarReapertura(Request $request, Postulacion $postulacion, RondasCorreccion $rondas): JsonResponse
    {
        // Mismo alcance que el resto de Evaluación: fuera de las fichas del usuario se responde 404.
        $this->autorizar($request, $postulacion);

        if (! in_array($request->user()->role, [Role::Instructor, Role::SuperAdmin], true)) {
            abort(403, 'Solo el Instructor o el Superadmin pueden autorizar una ronda excepcional.');
        }

        $datos = $request->validate(['justificacion' => ['required', 'string']]);

        DB::transaction(fn () => $rondas->autorizar($postulacion, $request->user(), $datos['justificacion']));

        return response()->json(['data' => ['correccion' => $rondas->resumen($postulacion->fresh(), $request->user())]], 201);
    }
}
