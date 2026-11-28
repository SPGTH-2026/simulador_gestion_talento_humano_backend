<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Models\Evaluacion;
use App\Models\Postulacion;
use App\Services\Evaluacion\PuntajeDeEvaluacion;
use App\Services\RondasCorreccion;
use Illuminate\Support\Facades\DB;
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
                'correccion' => app(RondasCorreccion::class)->resumen($e->postulacion, $actor),
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
     * Corrección de MI postulación (FE-BUG-03): la ronda actual y su historial, directamente por la postulación. Así la
     * pantalla de documentos no tiene que bajar todo el listado de evaluaciones. Sin corrección pedida responde data null.
     */
    public function correccionPropia(Request $request, Postulacion $postulacion, RondasCorreccion $rondas): JsonResponse
    {
        if ($postulacion->user_id !== $request->user()->id) {
            abort(404, 'Postulación no encontrada.');
        }

        $resumen = $rondas->resumen($postulacion, $request->user());

        if ($resumen['ronda_actual'] === null) {
            return response()->json(['data' => null]);
        }

        $evaluacionId = $rondas->rondas($postulacion)->last()?->evaluacion_id;

        return response()->json([
            'data' => [
                'correccion' => $resumen,
                // Por documento (id del parámetro), los puntos de su checklist que el evaluador marcó «No cumple».
                'items_no_cumplidos' => $this->itemsNoCumplidos($postulacion, $evaluacionId ? Evaluacion::find($evaluacionId) : null),
            ],
        ]);
    }

    /**
     * Los ítems del checklist que el evaluador marcó «No cumple», agrupados por documento. Solo se llama para la corrección que el
     * aspirante tiene pedida: un borrador del evaluador no llega hasta aquí.
     *
     * @return array<string, list<string>>
     */
    private function itemsNoCumplidos(Postulacion $postulacion, ?Evaluacion $evaluacion): array
    {
        if ($evaluacion === null) {
            return [];
        }

        $perfil = (array) $evaluacion->perfil;
        $puntaje = app(PuntajeDeEvaluacion::class);
        $postulacion->loadMissing('convocatoria.documentos');
        $salida = [];

        foreach ($postulacion->convocatoria->documentos as $documento) {
            $fallan = [];
            foreach ($puntaje->itemsDeDocumento($postulacion, (int) $documento->id) as $n => $texto) {
                if (($perfil["docitem-{$documento->id}-{$n}"] ?? null) === false) {
                    $fallan[] = $texto;
                }
            }

            if ($fallan !== []) {
                $salida[(string) $documento->id] = $fallan;
            }
        }

        return $salida;
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

        // La respuesta se registra en la ronda de corrección, bajo el bloqueo de la postulación: allí se comprueba que siga
        // vigente y que todos los documentos marcados se hayan reemplazado DESPUÉS de la solicitud.
        [$ronda, $faltan] = DB::transaction(fn () => app(RondasCorreccion::class)->responder($postulacion));

        if ($faltan !== []) {
            return response()->json([
                'message' => 'Aún falta reemplazar documentos marcados para corregir.',
                'errors' => ['documentos' => ['Reemplaza todos los documentos marcados con «Corregir» antes de enviar.']],
                'faltantes' => collect($faltan)->sort()->values()->all(),
            ], 422);
        }

        // Copia de compatibilidad en la evaluación de origen (sin tocar updated_at).
        if ($ronda->evaluacion_id && ($origen = Evaluacion::find($ronda->evaluacion_id))) {
            $origen->timestamps = false;
            $origen->forceFill(['correccion_enviada_at' => $ronda->respondida_at])->save();
        }

        return response()->json([
            'data' => ['correccion_enviada_at' => $ronda->respondida_at->toIso8601String()],
        ]);
    }
}
