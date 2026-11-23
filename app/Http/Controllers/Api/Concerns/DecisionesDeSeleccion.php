<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Models\Convocatoria;
use App\Models\SeleccionDecision;
use App\Models\SeleccionFinalistaLista;
use App\Models\SeleccionFinalistaMiembro;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\ValidationException;

trait DecisionesDeSeleccion
{
    /**
     * La lista confirmada que respalda la decisión: debe existir, ser la versión que el cliente
     * consultó, no requerir revisión y contener al candidato. Devuelve su versión.
     */
    private function exigirListaVigente(Convocatoria $convocatoria, ?int $versionCliente, int $postulacion): int
    {
        if ($versionCliente === null) {
            $this->rechazar('lista_version', 'Envía la versión de la lista de finalistas que consultaste.');
        }

        $lista = $this->ultimaLista($convocatoria->id);
        if ($lista === null) {
            $this->conflicto('lista_no_confirmada', 'La convocatoria aún no tiene una lista de finalistas confirmada.');
        }
        if ($lista->version !== $versionCliente) {
            $this->conflicto('lista_reemplazada', 'La lista de finalistas cambió desde que la consultaste. Actualízala y revisa antes de decidir.', ['lista_version_actual' => $lista->version]);
        }

        $revisar = $this->revisionDeLista($lista, $this->construirRanking($convocatoria));
        if ($revisar !== []) {
            $this->conflicto('lista_requiere_revision', 'La lista de finalistas requiere revisión: cambió una nota o la habilitación de algún finalista.', ['revisar' => $revisar]);
        }

        if (! $lista->miembros->contains('postulacion_id', $postulacion)) {
            $this->rechazar('postulacion', 'El candidato no pertenece a la lista de finalistas confirmada.');
        }

        return $lista->version;
    }

    /**
     * Situación de un candidato según el Ranking vivo, o null si ya no es candidato (su postulación dejó de estar
     * postulada). 'posicion' y 'resultado' solo existen si está listo y hay actividades configuradas.
     *
     * @return array{listo: bool, posicion: ?int, resultado: ?string, estado_documental: string, cumplimiento_documental: mixed}|null
     */
    private function situacionActual(?array $indice, int $postulacionId): ?array
    {
        $candidato = $indice['candidatos'][$postulacionId] ?? null;
        if ($candidato === null) {
            return null;
        }
        $listo = $indice['listos'][$postulacionId] ?? null;

        return [
            'listo' => $candidato['listo'],
            'posicion' => $listo['posicion'] ?? null,
            'resultado' => $candidato['resultado'] === null ? null : $this->formatear($candidato['resultado']),
            'estado_documental' => $candidato['estado_documental'],
            'cumplimiento_documental' => $candidato['cumplimiento_documental'],
        ];
    }

    /**
     * Para cada decisión, la fila de la lista cuya versión quedó registrada en ella (posición y resultado de entonces).
     * Se resuelve en bloque (dos consultas para toda la página, sin consultas por fila). La versión registrada puede no
     * contener al candidato —al liberar un cupo se registra la versión vigente aunque ya no lo incluya—: en ese caso
     * 'pertenece' es false y no hay posición ni resultado que mostrar.
     *
     * @param  \Illuminate\Support\Collection<int, SeleccionDecision>  $decisiones  indexadas por postulacion_id
     * @return array<int, array{lista_version: ?int, confirmada_en: ?string, pertenece: bool, posicion: ?int, resultado: ?string}>
     */
    private function sustentosDeDecisiones(Convocatoria $convocatoria, $decisiones): array
    {
        if ($decisiones->isEmpty()) {
            return [];
        }

        $versiones = $decisiones->pluck('lista_version')->filter()->unique()->values();
        $listas = SeleccionFinalistaLista::query()
            ->where('convocatoria_id', $convocatoria->id)
            ->whereIn('version', $versiones)
            ->get(['id', 'version', 'created_at'])
            ->keyBy('version');
        $miembros = $listas->isEmpty() ? collect() : SeleccionFinalistaMiembro::query()
            ->whereIn('lista_id', $listas->pluck('id'))
            ->whereIn('postulacion_id', $decisiones->keys())
            ->get(['lista_id', 'postulacion_id', 'posicion', 'resultado'])
            ->keyBy(fn (SeleccionFinalistaMiembro $m) => $m->lista_id.'|'.$m->postulacion_id);

        $sustentos = [];
        foreach ($decisiones as $postulacionId => $d) {
            $lista = $d->lista_version === null ? null : $listas->get($d->lista_version);
            $miembro = $lista === null ? null : $miembros->get($lista->id.'|'.$postulacionId);
            $sustentos[$postulacionId] = [
                'lista_version' => $d->lista_version,
                'confirmada_en' => $lista?->created_at?->toIso8601String(),
                'pertenece' => $miembro !== null,
                'posicion' => $miembro?->posicion,
                'resultado' => $miembro?->resultado === null ? null : (string) $miembro->resultado,
            ];
        }

        return $sustentos;
    }

    private function ultimaLista(int $convocatoriaId): ?SeleccionFinalistaLista
    {
        return SeleccionFinalistaLista::query()
            ->with(['miembros.postulacion.user:id,name', 'confirmador:id,name'])
            ->where('convocatoria_id', $convocatoriaId)
            ->orderByDesc('version')
            ->first();
    }

    /** @return array{vacantes_totales: int, cupos_ocupados: int, cupos_disponibles: int} */
    private function cupos(Convocatoria $convocatoria): array
    {
        $totales = (int) $convocatoria->vacantes;
        $ocupados = SeleccionDecision::query()
            ->where('convocatoria_id', $convocatoria->id)
            ->where('resultado', 'seleccionado')
            ->count();

        return [
            'vacantes_totales' => $totales,
            'cupos_ocupados' => $ocupados,
            'cupos_disponibles' => max(0, $totales - $ocupados),
        ];
    }

    private function presentarDecision(SeleccionDecision $d): array
    {
        return [
            'resultado' => $d->resultado,
            'motivo' => $d->motivo,
            'lista_version' => $d->lista_version,
            'decidida_por' => $d->decididor?->name,
            'decidida_en' => $d->updated_at?->toIso8601String(),
        ];
    }

    private function rechazar(string $campo, string $mensaje): never
    {
        throw ValidationException::withMessages([$campo => $mensaje]);
    }

    /** Conflicto 409 con un código de dominio para que el cliente refresque y revise. */
    private function conflicto(string $codigo, string $mensaje, array $extra = []): never
    {
        throw new HttpResponseException(response()->json(['message' => $mensaje, 'codigo' => $codigo] + $extra, 409));
    }
}
