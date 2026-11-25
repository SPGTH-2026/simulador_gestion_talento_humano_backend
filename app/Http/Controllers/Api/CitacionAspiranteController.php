<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SeleccionCitacion;
use App\Services\Citaciones\PresentaCitaciones;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Las citas del propio aspirante (permiso propio:postulaciones): solo las de SUS postulaciones, de cualquier convocatoria. Primero las
 * programadas por fecha y luego las canceladas, con su motivo. No se entrega el historial interno ni quién las programó.
 */
class CitacionAspiranteController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $citas = SeleccionCitacion::query()
            ->with('postulacion.convocatoria:id,nombre')
            ->whereHas('postulacion', fn ($q) => $q->where('user_id', $request->user()->id))
            ->get()
            ->sortBy(fn (SeleccionCitacion $c) => [$c->estado === SeleccionCitacion::PROGRAMADA ? 0 : 1, $c->estado === SeleccionCitacion::PROGRAMADA ? $c->fecha_hora->getTimestamp() : -$c->updated_at->getTimestamp()])
            ->values();

        return response()->json(['data' => $citas->map(fn (SeleccionCitacion $c) => PresentaCitaciones::presentar($c) + [
            'convocatoria' => $c->postulacion->convocatoria->nombre,
        ])->all()]);
    }
}
