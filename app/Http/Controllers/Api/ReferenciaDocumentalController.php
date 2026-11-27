<?php

namespace App\Http\Controllers\Api;

use App\Enums\PostulacionEstado;
use App\Enums\Role;
use App\Http\Controllers\Api\Concerns\ResultadosDocumentales;
use App\Http\Controllers\Controller;
use App\Models\Evaluacion;
use App\Models\Parametro;
use App\Models\Postulacion;
use App\Models\ReferenciaDocumental;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Selección, etapa 2: designación de la evaluación documental de referencia.
 *
 * Cuando una postulación tiene varias evaluaciones finalizadas, el Instructor
 * (o el Superadministrador) elige cuál vale y deja constancia de por qué. Cada
 * designación es un evento; la vigente es la última, mientras la evaluación
 * elegida no cambie (ver ResultadosDocumentales::ultimasReferencias).
 *
 * Los ids llegan como enteros y no como modelos enlazados: así la autorización
 * (403) se resuelve antes de revelar si un recurso existe (404).
 */
class ReferenciaDocumentalController extends Controller
{
    use ResultadosDocumentales;

    public function show(Request $request, int $convocatoria, int $postulacion): JsonResponse
    {
        $postulacion = $this->postulacionDelCandidato($request, $convocatoria, $postulacion, soloPostulada: true);

        return response()->json(['data' => $this->presentar($postulacion)]);
    }

    public function store(Request $request, int $convocatoria, int $postulacion): JsonResponse
    {
        $this->postulacionDelCandidato($request, $convocatoria, $postulacion);

        $datos = $request->validate([
            'evaluacion_id' => ['required', 'integer'],
            'justificacion' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        $actor = $request->user();

        DB::transaction(function () use ($postulacion, $datos, $actor) {
            // Serializa las designaciones de la misma postulación y vuelve a validar con la fila bloqueada.
            $fila = Postulacion::query()->whereKey($postulacion)->lockForUpdate()->first();

            $postulada = Parametro::porTemaYCodigo('estado_postulacion', PostulacionEstado::Postulada->value);
            if (! $fila || (int) $fila->estado_id !== (int) $postulada->id) {
                throw ValidationException::withMessages([
                    'postulacion' => 'La postulación ya no está en estado postulada.',
                ]);
            }

            // La evaluación debe ser de esta postulación; si no, se responde como inexistente.
            $evaluacion = Evaluacion::query()
                ->where('postulacion_id', $postulacion)
                ->whereKey($datos['evaluacion_id'])
                ->lockForUpdate()
                ->first();
            abort_if($evaluacion === null, 404, 'Evaluación no encontrada.');

            if ($evaluacion->estado !== 'finalizada' || $evaluacion->pasa_seleccion === null) {
                throw ValidationException::withMessages([
                    'evaluacion_id' => 'La evaluación elegida ya no está finalizada con resultado definido.',
                ]);
            }

            $elegibles = $this->soloElegibles(Evaluacion::query()->where('postulacion_id', $postulacion))->count();
            $ultima = $this->ultimasReferencias(collect([$postulacion]))->get($postulacion);
            $invalidada = $ultima !== null && ! $ultima['vigente'];

            // Con una sola elegible el resultado es automático, salvo que haya
            // que reconfirmar una referencia que dejó de ser vigente.
            if ($elegibles < 2 && ! $invalidada) {
                throw ValidationException::withMessages([
                    'evaluacion_id' => 'La postulación no necesita referencia: tiene un único resultado vigente.',
                ]);
            }

            ReferenciaDocumental::create([
                'postulacion_id' => $postulacion,
                'evaluacion_id' => $evaluacion->id,
                'evaluador_id' => $evaluacion->evaluador_id,
                'designado_por' => $actor->id,
                'justificacion' => $datos['justificacion'],
                'cumplimiento' => $evaluacion->cumplimiento,
                'pasa_seleccion' => $evaluacion->pasa_seleccion,
                'evaluacion_actualizada_en' => $evaluacion->updated_at,
                // La vigencia se mide por revisión (la evaluación está bloqueada en esta transacción: no cambia a media).
                'evaluacion_revision' => $evaluacion->revision,
            ]);
        });

        return response()->json(['data' => $this->presentar(Postulacion::query()->findOrFail($postulacion))], 201);
    }

    /** Autoriza (rol + permiso por ruta) y resuelve la postulación dentro de la convocatoria y del alcance. */
    private function postulacionDelCandidato(Request $request, int $convocatoria, int $postulacion, bool $soloPostulada = false): Postulacion
    {
        $actor = $request->user();

        // seleccion:decidir lo tienen también aprendices; aquí solo decide Instructor o Superadministrador.
        abort_unless($this->veDetalleDeReferencia($actor), 403);

        $this->convocatoriaEnAlcance($actor, $convocatoria);

        // En la consulta solo figuran las postuladas. Al designar, una postulación de la
        // convocatoria que ya no lo está se informa como error de validación (ver store).
        $consulta = Postulacion::query()
            ->with('user:id,name')
            ->where('convocatoria_id', $convocatoria);

        if ($soloPostulada) {
            $postulada = Parametro::porTemaYCodigo('estado_postulacion', PostulacionEstado::Postulada->value);
            $consulta->where('estado_id', $postulada->id);
        }

        return $consulta->find($postulacion) ?? abort(404, 'Candidato no encontrado.');
    }

    private function presentar(Postulacion $postulacion): array
    {
        $postulacion->loadMissing('user:id,name');

        $elegibles = $this->soloElegibles(Evaluacion::query()->where('postulacion_id', $postulacion->id))
            ->with('evaluador:id,name')
            ->orderBy('id')
            ->get(['id', 'evaluador_id', 'cumplimiento', 'pasa_seleccion', 'updated_at']);

        $ultima = $this->ultimasReferencias(collect([$postulacion->id]))->get($postulacion->id);
        $ref = $ultima['ref'] ?? null;
        $ref?->load('evaluador:id,name', 'designador:id,name');

        [$estado, $cumplimiento] = $this->clasificarEstadoDocumental(
            $elegibles->count(),
            $elegibles->where('pasa_seleccion', true)->count(),
            $elegibles->count() === 1 ? $elegibles->first()->cumplimiento : null,
            $ref,
            $ultima['vigente'] ?? false,
        );

        return [
            'postulacion_id' => $postulacion->id,
            'aspirante' => $postulacion->user->name,
            'estado_documental' => $estado,
            'cumplimiento' => $cumplimiento,
            'evaluaciones' => $elegibles->map(fn (Evaluacion $e) => [
                'id' => $e->id,
                'evaluador' => $e->evaluador?->name,
                'cumplimiento' => $e->cumplimiento,
                'resultado' => $e->pasa_seleccion ? 'habilitado' : 'no_habilitado',
                'actualizada_en' => $e->updated_at?->toIso8601String(),
            ])->values(),
            'referencia' => $ref === null ? null : [
                'id' => $ref->id,
                'evaluacion_id' => $ref->evaluacion_id,
                'evaluador' => $ref->evaluador?->name,
                'cumplimiento' => $ref->cumplimiento,
                'resultado' => $ref->pasa_seleccion ? 'habilitado' : 'no_habilitado',
                'justificacion' => $ref->justificacion,
                'designada_por' => $ref->designador?->name,
                'designada_en' => $ref->created_at?->toIso8601String(),
                'vigente' => $ultima['vigente'],
            ],
        ];
    }
}
