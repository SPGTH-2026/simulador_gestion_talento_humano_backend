<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Models\Evaluacion;
use App\Models\Parametro;
use App\Models\Postulacion;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

trait PresentaEvaluacion
{
    /**
     * El Instructor/super_admin administra el proceso: cuando una postulación
     * tiene varias evaluaciones, trabaja sobre la más representativa. Primero
     * prioriza la que realizó un aprendiz con subrol evaluador (es el trabajo
     * que el admin/super_admin supervisa); luego el estado (finalizada, en
     * corrección, borrador) y por último la más reciente.
     *
     * @param  Collection<int, Evaluacion>  $evaluaciones
     */
    private function seleccionarEvaluacionVisible($evaluaciones): ?Evaluacion
    {
        return $evaluaciones
            ->sortByDesc(fn (Evaluacion $e) => [
                $e->evaluador?->subrole === 'evaluador' ? 1 : 0,
                match ($e->estado) {
                    'finalizada' => 3,
                    'correccion_solicitada' => 2,
                    default => 1,
                },
                $e->updated_at?->getTimestamp() ?? 0,
                $e->id,
            ])
            ->first();
    }

    private function presentar(Postulacion $p, ?Evaluacion $e, ?User $actor = null): array
    {
        // Solo cuentan los documentos OBLIGATORIOS y APLICABLES que el
        // aspirante ya cargó (misma regla que el cálculo del 60 %). No existe
        // un estado de validación en la base: "cargado" es lo único que se
        // puede afirmar. La libreta militar de una mujer no aplica.
        $sexoMujer = $p->user?->perfilAspirante?->sexo?->codigo === 'mujer';

        $requeridos = ($p->convocatoria?->documentos ?? collect())
            ->filter(fn (Parametro $d) => (bool) $d->pivot->obligatorio
                && ! ($d->codigo === Parametro::DOC_LIBRETA_MILITAR && $sexoMujer));

        $docsRequeridos = $requeridos->count();
        $docsCargados = $p->documentos
            ->whereIn('parametro_id', $requeridos->pluck('id'))
            ->count();

        return [
            'id' => $p->id,
            'estado' => $p->estado?->nombre ?? $p->estado?->codigo,
            'aspirante' => [
                'id' => $p->user_id,
                'name' => $p->user?->name,
                'email' => $p->user?->email,
            ],
            'convocatoria' => [
                'id' => $p->convocatoria?->id,
                'titulo' => $p->convocatoria?->nombre,
                'estado' => $p->convocatoria?->estado?->codigo,
            ],
            'documentos' => [
                'total' => $docsRequeridos,
                'cargados' => $docsCargados,
            ],
            'skills' => $p->convocatoria?->skills?->map(function ($cs) use ($p) {
                $declarada = $p->skills->firstWhere('convocatoria_skill_id', $cs->id);

                return [
                    'id' => $cs->id,
                    'nombre' => $cs->nombre,
                    'declarada' => $declarada !== null,
                    'certificada' => $declarada?->tieneCertificado() ?? false,
                ];
            })->values() ?? [],
            'evaluacion' => $e ? [
                'id' => $e->id,
                'evaluador_id' => $e->evaluador_id,
                'evaluador' => $e->evaluador?->name ?? $e->evaluador?->email,
                'es_propia' => $actor !== null && (int) $e->evaluador_id === (int) $actor->id,
                'puntaje_total' => $e->puntaje_total,
                'estado' => $e->estado,
                'observaciones' => $e->observaciones,
                'cumplimiento' => $e->cumplimiento,
                'pasa_seleccion' => $e->pasa_seleccion,
                'fecha' => $e->created_at?->toDateString(),
                'correccion_enviada_at' => $e->correccion_enviada_at?->toIso8601String(),
                'fecha_limite_correccion' => $e->fecha_limite_correccion?->toIso8601String(),
                'correccion_estado' => $e->estadoCorreccion($p),
                'perfil' => $e->perfil,
                'documentos_actualizados' => $this->documentosActualizados($p, $e),
                'criterios' => $e->relationLoaded('detalles')
                    ? $e->detalles->map(fn ($d) => [
                        'criterio' => $d->criterio,
                        'puntaje' => $d->puntaje,
                        'ponderacion' => $d->ponderacion,
                    ])
                    : [],
            ] : null,
        ];
    }

    /**
     * Identificadores "doc-{parametro_id}" de los documentos requeridos cuyo
     * archivo se subió o reemplazó DESPUÉS de la última revisión del evaluador.
     *
     * @return array<int, string>
     */
    private function documentosActualizados(?Postulacion $p, ?Evaluacion $e): array
    {
        if (! $p || ! $e || ! $e->updated_at) {
            return [];
        }

        $evalUpdated = Carbon::parse($e->updated_at);

        return $p->documentos
            ->whereNotNull('parametro_id')
            ->filter(fn ($doc) => $doc->updated_at && Carbon::parse($doc->updated_at)->gt($evalUpdated))
            ->map(fn ($doc) => 'doc-'.$doc->parametro_id)
            ->values()
            ->all();
    }
}
