<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Models\Convocatoria;
use App\Models\Evaluacion;
use App\Models\ReferenciaDocumental;
use App\Models\User;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Collection;

/**
 * Reglas compartidas por el listado de candidatos y la referencia documental:
 * alcance por ficha, qué evaluación es elegible, cuándo una referencia sigue
 * vigente y cómo se clasifica el estado documental.
 */
trait ResultadosDocumentales
{
    /** La convocatoria si existe y está en las fichas del actor; si no, 404 (sin revelar cuál). */
    protected function convocatoriaEnAlcance(User $actor, int $id): Convocatoria
    {
        $convocatoria = Convocatoria::query()->find($id);

        if (! $convocatoria || ! in_array((int) $convocatoria->ficha_id, $actor->fichaIds(), true)) {
            abort(404, 'Convocatoria no encontrada.');
        }

        return $convocatoria;
    }

    /** Contrato de Evaluación: finalizada y con resultado definido. */
    protected function soloElegibles(Builder $query): Builder
    {
        return $query->where('estado', 'finalizada')->whereNotNull('pasa_seleccion');
    }

    /**
     * Última designación de cada postulación y si sigue vigente. Dos consultas
     * para todo el conjunto de postulaciones, sin importar cuántas sean.
     *
     * Vigente = la evaluación elegida existe, sigue elegible y conserva el
     * mismo cumplimiento, resultado y updated_at que tenía al designarla.
     * No basta con updated_at: la invalidación por cambio documental pasa la
     * evaluación a en_proceso con los timestamps desactivados, y eso se detecta
     * por el estado. Una reedición normal cambia updated_at.
     *
     * @param  Collection<int, int>  $postulacionIds
     * @return Collection<int, array{ref: ReferenciaDocumental, vigente: bool}> indexada por postulacion_id
     */
    protected function ultimasReferencias(Collection $postulacionIds): Collection
    {
        $ultimas = ReferenciaDocumental::query()
            ->whereIn('id', ReferenciaDocumental::query()
                ->selectRaw('MAX(id)')
                ->whereIn('postulacion_id', $postulacionIds)
                ->groupBy('postulacion_id'))
            ->get()
            ->keyBy('postulacion_id');

        $evaluaciones = Evaluacion::query()
            ->whereIn('id', $ultimas->pluck('evaluacion_id')->filter()->values())
            ->get(['id', 'estado', 'cumplimiento', 'pasa_seleccion', 'updated_at'])
            ->keyBy('id');

        return $ultimas->map(fn (ReferenciaDocumental $ref) => [
            'ref' => $ref,
            'vigente' => $this->referenciaVigente($ref, $evaluaciones->get($ref->evaluacion_id)),
        ]);
    }

    protected function referenciaVigente(ReferenciaDocumental $ref, ?Evaluacion $evaluacion): bool
    {
        return $evaluacion !== null
            && $evaluacion->estado === 'finalizada'
            && $evaluacion->pasa_seleccion !== null
            && $evaluacion->cumplimiento === $ref->cumplimiento
            && $evaluacion->pasa_seleccion === $ref->pasa_seleccion
            && $evaluacion->updated_at?->getTimestamp() === $ref->evaluacion_actualizada_en?->getTimestamp();
    }

    /**
     * Única clasificación documental de Selección.
     *
     * Con referencia (vigente o no) manda la referencia: vigente decide el
     * resultado elegido; invalidada obliga a revisar de nuevo, aunque quede otra
     * evaluación finalizada (no se vuelve a una referencia anterior por descarte).
     * Sin referencia: 0 resultados pendiente, 1 el automático, varios revisión.
     *
     * @return array{0: string, 1: ?int} estado y cumplimiento a mostrar (null = sin dato)
     */
    protected function clasificarEstadoDocumental(
        int $elegibles,
        int $habilitadas,
        ?int $cumplimientoUnico,
        ?ReferenciaDocumental $ref,
        bool $vigente,
    ): array {
        if ($ref !== null) {
            return $vigente
                ? [$ref->pasa_seleccion ? 'habilitado' : 'no_habilitado', $ref->cumplimiento]
                : ['requiere_revision', null];
        }

        if ($elegibles === 0) {
            return ['pendiente', null];
        }

        if ($elegibles > 1) {
            return ['requiere_revision', null];
        }

        return [$habilitadas === 1 ? 'habilitado' : 'no_habilitado', $cumplimientoUnico];
    }
}
