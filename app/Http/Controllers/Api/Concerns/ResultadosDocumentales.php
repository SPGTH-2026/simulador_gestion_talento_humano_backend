<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Enums\Role;
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

    /**
     * Quién ve el detalle de la referencia documental (designarla, saber si falta o quedó invalidada): solo Instructor y
     * Superadministrador. seleccion:decidir lo tienen también aprendices, que reciben la explicación general.
     */
    protected function veDetalleDeReferencia(User $actor): bool
    {
        return in_array($actor->role, [Role::Instructor, Role::SuperAdmin], true);
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
     * mismo cumplimiento, resultado y REVISIÓN que tenía al designarla. La
     * revisión sube con cada cambio real de la evaluación y con cada
     * invalidación por cambio documental, así que no depende del reloj: dos
     * cambios en el mismo segundo no se confunden, ni una reevaluación en el
     * segundo de la designación reactiva una referencia invalidada.
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
            ->get(['id', 'estado', 'cumplimiento', 'pasa_seleccion', 'revision'])
            ->keyBy('id');

        return $ultimas->map(fn (ReferenciaDocumental $ref) => [
            'ref' => $ref,
            'vigente' => $this->referenciaVigente($ref, $evaluaciones->get($ref->evaluacion_id)),
        ]);
    }

    /**
     * Estado documental de varias postulaciones con la misma regla, en 3 consultas
     * sin importar cuántas sean. Devuelve, por id, el estado, el cumplimiento a
     * mostrar y los datos de la referencia (id y vigencia) para detectar cambios.
     *
     * @param  Collection<int, int>  $postulacionIds
     * @return Collection<int, array{estado: string, cumplimiento: ?int, ref_id: ?int, vigente: bool}>
     */
    protected function estadosDocumentales(Collection $postulacionIds): Collection
    {
        $resultados = $this->soloElegibles(Evaluacion::query()->whereIn('postulacion_id', $postulacionIds))
            ->selectRaw('postulacion_id, COUNT(*) AS total, SUM(CASE WHEN pasa_seleccion THEN 1 ELSE 0 END) AS habilitadas, MAX(cumplimiento) AS cumplimiento')
            ->groupBy('postulacion_id')
            ->get()
            ->keyBy('postulacion_id');
        $referencias = $this->ultimasReferencias($postulacionIds);

        return $postulacionIds->mapWithKeys(function (int $id) use ($resultados, $referencias) {
            $r = $resultados->get($id);
            $ref = $referencias->get($id);
            $total = (int) ($r->total ?? 0);
            [$estado, $cumplimiento] = $this->clasificarEstadoDocumental(
                $total,
                (int) ($r->habilitadas ?? 0),
                $total === 1 ? (int) $r->cumplimiento : null,
                $ref['ref'] ?? null,
                $ref['vigente'] ?? false,
            );

            return [$id => [
                'estado' => $estado,
                'cumplimiento' => $cumplimiento,
                'ref_id' => $ref['ref']->id ?? null,
                'vigente' => $ref['vigente'] ?? false,
            ]];
        });
    }

    /**
     * Estado documental actual de UNA postulación, con la misma regla del listado
     * de candidatos y de la referencia (no recalcula el cumplimiento).
     *
     * @return array{0: string, 1: ?int} estado y cumplimiento a mostrar
     */
    protected function estadoDocumentalDe(int $postulacionId): array
    {
        $resultado = $this->soloElegibles(Evaluacion::query()->where('postulacion_id', $postulacionId))
            ->selectRaw('COUNT(*) AS total, SUM(CASE WHEN pasa_seleccion THEN 1 ELSE 0 END) AS habilitadas, MAX(cumplimiento) AS cumplimiento')
            ->first();
        $referencia = $this->ultimasReferencias(collect([$postulacionId]))->get($postulacionId);
        $total = (int) $resultado->total;

        return $this->clasificarEstadoDocumental(
            $total,
            (int) $resultado->habilitadas,
            $total === 1 ? (int) $resultado->cumplimiento : null,
            $referencia['ref'] ?? null,
            $referencia['vigente'] ?? false,
        );
    }

    protected function referenciaVigente(ReferenciaDocumental $ref, ?Evaluacion $evaluacion): bool
    {
        return $evaluacion !== null
            && $evaluacion->estado === 'finalizada'
            && $evaluacion->pasa_seleccion !== null
            && $evaluacion->cumplimiento === $ref->cumplimiento
            && $evaluacion->pasa_seleccion === $ref->pasa_seleccion
            && $evaluacion->revision === $ref->evaluacion_revision;
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
