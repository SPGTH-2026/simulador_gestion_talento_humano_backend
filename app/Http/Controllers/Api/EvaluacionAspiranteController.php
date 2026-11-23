<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Models\Evaluacion;
use App\Models\Postulacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Http\Controllers\Api\Concerns\PresentaEvaluacion;
use App\Http\Controllers\Api\Concerns\ValidaEvaluacion;
use App\Http\Controllers\Controller;

class EvaluacionAspiranteController extends Controller
{
    use PresentaEvaluacion;
    use ValidaEvaluacion;

    public function misEvaluaciones(Request $request): JsonResponse
    {
        $actor = $request->user();

        $query = Evaluacion::query()->with([
            'detalles',
            'evaluador:id,name,email',
            'postulacion.convocatoria:id,nombre,cargo,estado_id',
            'postulacion.estado:id,codigo,nombre',
            'postulacion.user:id,name,email',
            'postulacion.documentos',
        ]);

        // El aspirante ve las evaluaciones sobre SUS postulaciones. El instructor
        // y el admin ven TODAS las evaluaciones calificadas de su alcance.
        if ($actor->role === Role::Aspirante) {
            // Un borrador en_proceso no es resultado: el aspirante solo ve evaluaciones finalizadas o con corrección pedida.
            $query->whereHas('postulacion', fn ($q) => $q->where('user_id', $actor->id))
                ->whereIn('estado', ['finalizada', 'correccion_solicitada']);
        } elseif ($actor->role === Role::Instructor) {
            $query->whereHas('postulacion.convocatoria', fn ($q) => $q->whereIn('ficha_id', $actor->fichaIds()));
        } elseif ($actor->role === Role::Aprendiz) {
            // El evaluador (y el aprendiz general) ve SOLO las evaluaciones que
            // él mismo realizó, no las de toda la ficha.
            $query->where('evaluador_id', $actor->id);
        }
        // super_admin ve todas.

        // Filtros opcionales (los usa el aspirante para pedir solo la corrección de una postulación).
        if ($request->filled('postulacion_id')) {
            $query->where('postulacion_id', (int) $request->input('postulacion_id'));
        }
        if ($request->filled('estado')) {
            $query->where('estado', (string) $request->input('estado'));
        }

        $evaluaciones = $query->orderByDesc('id')->get();
        $esAspirante = $actor->role === Role::Aspirante;

        return response()->json([
            'data' => $evaluaciones->map(fn (Evaluacion $e) => [
                'id' => $e->id,
                'postulacion_id' => $e->postulacion_id,
                'puntaje_total' => $e->puntaje_total,
                'estado' => $e->estado,
                'observaciones' => $e->observaciones,
                'cumplimiento' => $e->cumplimiento,
                'pasa_seleccion' => $e->pasa_seleccion,
                'fecha' => $e->created_at?->toDateString(),
                'correccion_enviada_at' => $e->correccion_enviada_at?->toIso8601String(),
                'fecha_limite_correccion' => $e->fecha_limite_correccion?->toIso8601String(),
                'correccion_estado' => $e->estadoCorreccion($e->postulacion),
                'perfil' => $e->perfil,
                'documentos_actualizados' => $this->documentosActualizados($e->postulacion, $e),
                'aspirante' => [
                    'id' => $e->postulacion->user?->id,
                    'name' => $e->postulacion->user?->name,
                    'email' => $e->postulacion->user?->email,
                ],
                'convocatoria' => [
                    'id' => $e->postulacion->convocatoria?->id,
                    'titulo' => $e->postulacion->convocatoria?->nombre,
                ],
                // Confidencialidad: el aspirante no ve quién lo evaluó (ni su correo).
                'evaluador' => $esAspirante ? null : [
                    'id' => $e->evaluador?->id,
                    'name' => $e->evaluador?->name,
                    'email' => $e->evaluador?->email,
                ],
                'criterios' => $e->detalles->map(fn ($d) => [
                    'criterio' => $d->criterio,
                    'puntaje' => $d->puntaje,
                    'ponderacion' => $d->ponderacion,
                ]),
            ]),
        ]);
    }

    /**
     * El aspirante envía la corrección: confirma que reemplazó TODOS los documentos
     * que el evaluador marcó con "Corregir". Solo el dueño de la postulación; los
     * demás reciben 404. Si faltan documentos, responde 422 y no guarda nada.
     * Queda registrado el momento del envío (correccion_enviada_at). No se toca
     * updated_at: así los reemplazos ya hechos siguen contando como actualizados.
     */
    public function enviarCorreccion(Request $request, Postulacion $postulacion): JsonResponse
    {
        if ($postulacion->user_id !== $request->user()->id) {
            abort(404, 'Postulación no encontrada.');
        }

        $evaluacion = Evaluacion::query()
            ->where('postulacion_id', $postulacion->id)
            ->where('estado', 'correccion_solicitada')
            ->latest('id')
            ->first();

        if (! $evaluacion) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'correccion' => 'No hay una corrección solicitada para esta postulación.',
            ]);
        }

        // Ya enviada: se responde igual (idempotente), aunque el plazo haya pasado después.
        if ($evaluacion->correccion_enviada_at) {
            return response()->json([
                'data' => ['correccion_enviada_at' => $evaluacion->correccion_enviada_at->toIso8601String()],
            ]);
        }

        // Plazo vencido: el aspirante ya no puede enviar la corrección.
        if ($evaluacion->fecha_limite_correccion && now()->gt($evaluacion->fecha_limite_correccion)) {
            $vencio = $evaluacion->fecha_limite_correccion->setTimezone('America/Bogota')->format('d/m/Y');

            throw \Illuminate\Validation\ValidationException::withMessages([
                'correccion' => "El plazo para corregir venció el {$vencio}. Ya no puedes enviar la corrección.",
            ]);
        }

        // Documentos que el evaluador marcó con "No" (perfil = false).
        $marcados = collect($evaluacion->perfil ?? [])
            ->filter(fn ($valor, $clave) => $valor === false && str_starts_with((string) $clave, 'doc-'))
            ->keys()
            ->values();

        $reemplazados = collect($this->documentosActualizados($postulacion, $evaluacion));
        $faltan = $marcados->diff($reemplazados)->values();

        if ($faltan->isNotEmpty()) {
            return response()->json([
                'message' => 'Aún falta reemplazar documentos marcados para corregir.',
                'errors' => ['documentos' => ['Reemplaza todos los documentos marcados con «Corregir» antes de enviar.']],
                'faltantes' => $faltan->map(fn ($clave) => (int) substr($clave, 4))->sort()->values()->all(),
            ], 422);
        }

        $ahora = now();
        $evaluacion->timestamps = false;
        $evaluacion->forceFill(['correccion_enviada_at' => $ahora])->save();

        return response()->json([
            'data' => ['correccion_enviada_at' => $ahora->toIso8601String()],
        ]);
    }
}
