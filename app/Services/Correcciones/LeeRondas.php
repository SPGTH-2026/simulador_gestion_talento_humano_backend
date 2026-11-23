<?php

namespace App\Services\Correcciones;

use App\Models\CorreccionAutorizacion;
use App\Models\CorreccionPostulacion;
use App\Models\Evaluacion;
use App\Models\Postulacion;
use Illuminate\Support\Collection;

/**
 * Lectura de las rondas de una postulación: guardadas más las solicitudes antiguas aún sin convertir (de solo
 * lectura), solapes, autorización sin usar y qué documentos marcados ya se reemplazaron.
 */
trait LeeRondas
{
    /** Rondas efectivas: guardadas más las antiguas aún sin convertir, en orden cronológico. */
    public function rondas(Postulacion $postulacion): Collection
    {
        $guardadas = CorreccionPostulacion::query()
            ->where('postulacion_id', $postulacion->id)
            ->orderBy('solicitada_at')->orderBy('id')
            ->get();

        $conRonda = $guardadas->pluck('evaluacion_id')->filter()->all();

        $legadas = $this->legadas($postulacion)->reject(fn (CorreccionPostulacion $r) => in_array($r->evaluacion_id, $conRonda, true));

        $siguiente = (int) $guardadas->where('tipo', 'ordinaria')->max('numero');
        foreach ($legadas->sortBy('solicitada_at') as $virtual) {
            $virtual->numero = ++$siguiente;
        }

        return $guardadas->concat($legadas)->sortBy(fn ($r) => [$r->solicitada_at?->getTimestamp() ?? 0, $r->id ?? PHP_INT_MAX])->values();
    }

    /**
     * Solicitudes antiguas identificables: evaluaciones en «corrección solicitada». Se leen como rondas ordinarias SIN
     * guardar. El plazo es el original; si nunca tuvo, queda «sin plazo registrado».
     */
    public function legadas(Postulacion $postulacion): Collection
    {
        return Evaluacion::query()
            ->where('postulacion_id', $postulacion->id)
            ->where('estado', 'correccion_solicitada')
            ->get()
            ->map(function (Evaluacion $e) use ($postulacion) {
                $marcados = collect($e->perfil ?? [])
                    ->filter(fn ($v, $k) => $v === false && str_starts_with((string) $k, 'doc-'))
                    ->keys()->map(fn ($k) => (int) substr((string) $k, 4))->sort()->values()->all();

                $ronda = new CorreccionPostulacion;
                $ronda->forceFill([
                    'postulacion_id' => $postulacion->id,
                    'tipo' => 'ordinaria',
                    'solicitada_por' => $e->evaluador_id,
                    'evaluacion_id' => $e->id,
                    'documentos_marcados' => $marcados,
                    'observaciones' => $e->observaciones,
                    'solicitada_at' => $e->fecha_limite_correccion
                        ? $e->fecha_limite_correccion->copy()->subHours(self::HORAS_PLAZO)
                        : $e->updated_at,
                    'fecha_limite' => $e->fecha_limite_correccion,
                    'respondida_at' => $e->correccion_enviada_at,
                    'documentos_respondidos' => $e->correccion_enviada_at ? $marcados : null,
                    'origen' => 'legado',
                    'evaluacion_legada_id' => $e->id,
                ]);
                $ronda->exists = false;

                return $ronda;
            });
    }

    /** ¿La postulación tiene solicitudes antiguas simultáneas (de varios evaluadores) pendientes de revisión? */
    public function tieneSolapes(Postulacion $postulacion): bool
    {
        return $this->rondas($postulacion)->filter(fn (CorreccionPostulacion $r) => ! $r->exists)->count() > 1;
    }

    private function exigirSinSolapes(Postulacion $postulacion): void
    {
        if ($this->tieneSolapes($postulacion)) {
            $this->rechazar('Esta postulación tiene solicitudes de corrección antiguas simultáneas de varios evaluadores que el administrador debe revisar antes de convertirlas. Por ahora no se pueden pedir, responder ni autorizar correcciones aquí.');
        }
    }

    /** Autorización sin usar, si la hay. */
    public function autorizacionLibre(Postulacion $postulacion): ?CorreccionAutorizacion
    {
        return CorreccionAutorizacion::query()
            ->where('postulacion_id', $postulacion->id)->where('sin_usar', 1)->first();
    }

    /** La ronda vigente (guardada o antigua) de la postulación, si la hay. */
    public function rondaVigente(Postulacion $postulacion): ?CorreccionPostulacion
    {
        return $this->rondas($postulacion)->first(fn (CorreccionPostulacion $r) => $r->estado() === 'vigente');
    }

    /** Documentos marcados que se reemplazaron DESPUÉS de la solicitud: un archivo que ya existía antes no cuenta. */
    public function reemplazados(Postulacion $postulacion, CorreccionPostulacion $r, array $marcados): array
    {
        if ($r->respondida_at) {
            return array_map('intval', $r->documentos_respondidos ?? $marcados);
        }

        if (! $r->solicitada_at) {
            return [];
        }

        $documentos = $postulacion->documentos()->whereNotNull('parametro_id')->whereIn('parametro_id', $marcados)->get();

        // Ronda nueva: se compara el archivo actual con el que había al pedir la corrección. Un archivo que ya existía
        // antes NO cuenta como corregido.
        if ($r->documentos_base !== null) {
            $base = collect($r->documentos_base);

            return $documentos
                ->filter(fn ($doc) => $doc->ruta !== null && (string) $doc->ruta !== (string) $base->get((string) $doc->parametro_id))
                ->pluck('parametro_id')->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();
        }

        // Solicitud antigua (sin base): documentos actualizados después de la solicitud.
        return $documentos
            ->filter(fn ($doc) => $doc->updated_at && $doc->updated_at->gt($r->solicitada_at))
            ->pluck('parametro_id')->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();
    }
}
