<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Enums\PostulacionEstado;
use App\Models\Convocatoria;
use App\Models\Parametro;
use App\Models\Postulacion;
use App\Models\SeleccionCalificacion;
use App\Models\SeleccionConfiguracion;
use App\Models\SeleccionFinalistaLista;
use App\Models\SeleccionRankingParametro;
use App\Services\DocumentosDePostulacion;
use Illuminate\Support\Collection;

/**
 * Cálculo del ranking de Selección para una convocatoria.
 *
 * Se calcula UNA vez sobre todas las postulaciones postuladas con un número fijo
 * de consultas (postulaciones, evaluaciones, referencias, notas, configuración y
 * objetivo), ordena a todos los listos y recién después se pagina. Nada se
 * recalcula por fila ni se cargan documentos, observaciones o historiales.
 *
 * Listo = postulada + habilitado documentalmente + obligatorios aplicables completos
 * + todas las actividades activas calificadas (con sin_actividades basta lo
 * documental; con la configuración pendiente nadie está listo).
 *
 * Requiere ResultadosDocumentales y ResultadoAdicional en la clase que lo usa.
 */
trait RankingSeleccion
{
    /**
     * @return array{
     *   modo: string, configuracion: ?SeleccionConfiguracion, candidatos: Collection, listos: Collection,
     *   objetivo: int, objetivo_configurado: ?int, objetivo_efectivo: int,
     *   sugeridos: array<int, int>, empate: ?array, version: string
     * }
     */
    protected function construirRanking(Convocatoria $convocatoria): array
    {
        $postulada = Parametro::porTemaYCodigo('estado_postulacion', PostulacionEstado::Postulada->value);

        $postulaciones = Postulacion::query()
            ->select('id', 'user_id')
            ->with('user:id,name')
            ->where('convocatoria_id', $convocatoria->id)
            ->where('estado_id', $postulada->id)
            // Los usuarios inhabilitados no entran al ranking ni consumen cupos.
            ->whereHas('user', fn ($u) => $u->where('active', true))
            ->orderBy('id')
            ->get();

        // Una sola regla de elegibilidad: la misma que explica los bloqueos en el listado de candidatos.
        ['modo' => $modo, 'configuracion' => $configuracion, 'candidatos' => $candidatos] = $this->evaluarCandidatos($convocatoria, $postulaciones);

        // Orden de presentación: resultado de mayor a menor y, solo para presentar, por id.
        // El id nunca decide quién pasa: los empates comparten posición.
        $listos = $candidatos->where('listo', true)->values();
        if ($modo === 'con_actividades') {
            $listos = $listos->sort(fn (array $a, array $b) => [$b['resultado'], $a['id']] <=> [$a['resultado'], $b['id']])->values();
        }

        $anterior = null;
        $listos = $listos->map(function (array $c, int $indice) use ($modo, &$anterior) {
            if ($modo === 'con_actividades') {
                $c['posicion'] = $anterior !== null && $anterior['resultado'] === $c['resultado'] ? $anterior['posicion'] : $indice + 1;
                $anterior = $c;
            } else {
                $c['posicion'] = null;
            }

            return $c;
        });

        $configurado = SeleccionRankingParametro::query()->where('convocatoria_id', $convocatoria->id)->value('objetivo_finalistas');
        $objetivo = $configurado !== null ? (int) $configurado : 2 * (int) $convocatoria->vacantes;
        $efectivo = min($objetivo, $listos->count());

        [$sugeridos, $empate] = $this->sugerirFinalistas($modo, $listos, $efectivo);
        $listos = $listos->map(function (array $c) use ($modo, $sugeridos, $empate) {
            $c['sugerencia'] = match (true) {
                $modo !== 'con_actividades' => null,
                in_array($c['id'], $sugeridos, true) => 'sugerido',
                $empate !== null && in_array($c['id'], $empate['postulaciones'], true) => 'empate_en_corte',
                default => 'no_sugerido',
            };

            return $c;
        });

        return [
            'modo' => $modo,
            'configuracion' => $configuracion,
            'candidatos' => $candidatos,
            'listos' => $listos,
            'objetivo' => $objetivo,
            'objetivo_configurado' => $configurado !== null ? (int) $configurado : null,
            'objetivo_efectivo' => $efectivo,
            'sugeridos' => $sugeridos,
            'empate' => $empate,
            // Huella de todo lo que puede cambiar la lista o la sugerencia: se compara al confirmar.
            'version' => sha1(json_encode([
                $modo, $configuracion?->id, $configuracion?->updated_at?->getTimestamp(), $objetivo, $efectivo,
                $listos->pluck('huella')->all(),
            ])),
        ];
    }

    /**
     * LA regla de elegibilidad de Selección, compartida por el Ranking y por el listado de candidatos.
     *
     * Para las postulaciones dadas (todas de la convocatoria y en estado postulada) calcula, en un número fijo de consultas,
     * qué le impide a cada una estar lista. «Listo» es exactamente «sin bloqueos»: nadie puede ser listo con un bloqueo ni
     * tener un bloqueo siendo listo, así que las dos pantallas no pueden discrepar.
     *
     * Bloqueos (solo los que el servidor calcula; sin archivos, rutas, observaciones ni nombres de evaluadores):
     *  - configuracion_pendiente: la convocatoria aún no define sus actividades.
     *  - documentos_obligatorios: faltan obligatorios aplicables (faltantes: código y nombre del documento del catálogo).
     *  - habilitacion_pendiente: aún no hay un resultado documental finalizado.
     *  - no_habilitado: el resultado documental no habilita.
     *  - referencia_requerida / referencia_invalidada: hay que designar o volver a designar la referencia. Solo se distinguen
     *    con `$detalleReferencia` (quien puede decidir, que es quien ve ese detalle); sin él, revision_documental.
     *  - notas_pendientes: con actividades, faltan notas (actividades: las que no tienen nota).
     *
     * @param  Collection<int, Postulacion>  $postulaciones  Eloquent, de la convocatoria, con user cargado
     * @return array{modo: string, configuracion: ?SeleccionConfiguracion, candidatos: Collection}
     */
    protected function evaluarCandidatos(Convocatoria $convocatoria, Collection $postulaciones, bool $detalleReferencia = false): array
    {
        $configuracion = SeleccionConfiguracion::query()->where('convocatoria_id', $convocatoria->id)->first();
        $ids = $postulaciones->pluck('id');

        $documentales = $this->estadosDocumentales($ids);
        // Documentos obligatorios aplicables que faltan (misma regla que el envío), en bloque para todas las postulaciones.
        // Una postulación incompleta no es candidata lista aunque su evaluación diga «Cumple»: es el caso de las
        // evaluaciones anteriores a RF-DOC-006, hechas cuando se podía eliminar un obligatorio.
        $faltantes = app(DocumentosDePostulacion::class)->faltantesDeConvocatoria($convocatoria, $postulaciones);
        $calificaciones = SeleccionCalificacion::query()
            ->where('convocatoria_id', $convocatoria->id)
            ->whereIn('postulacion_id', $ids)
            ->get(['postulacion_id', 'actividad', 'nota', 'updated_at'])
            ->groupBy('postulacion_id');

        $modo = match (true) {
            $configuracion === null => 'pendiente_de_configurar',
            $configuracion->entrevista_activa || $configuracion->prueba_tecnica_activa => 'con_actividades',
            default => 'sin_actividades',
        };

        $candidatos = $postulaciones->map(function (Postulacion $p) use ($documentales, $faltantes, $calificaciones, $configuracion, $modo, $detalleReferencia) {
            $doc = $documentales->get($p->id);
            $notas = $calificaciones->get($p->id, collect())->keyBy('actividad');
            $centesimas = $notas->map(fn ($n) => $this->centesimas((string) $n->nota))->all();
            $resultado = $modo === 'con_actividades' ? $this->resultadoPonderado($configuracion, $centesimas) : null;
            $faltan = $faltantes[$p->id] ?? [];

            $bloqueos = [];
            if ($modo === 'pendiente_de_configurar') {
                $bloqueos[] = ['codigo' => 'configuracion_pendiente'];
            }
            if ($faltan !== []) {
                $bloqueos[] = ['codigo' => 'documentos_obligatorios', 'faltantes' => $faltan];
            }
            $documental = match ($doc['estado']) {
                'pendiente' => 'habilitacion_pendiente',
                'no_habilitado' => 'no_habilitado',
                'requiere_revision' => ! $detalleReferencia ? 'revision_documental' : ($doc['ref_id'] !== null ? 'referencia_invalidada' : 'referencia_requerida'),
                default => null,
            };
            if ($documental !== null) {
                $bloqueos[] = ['codigo' => $documental];
            }
            if ($modo === 'con_actividades' && $resultado === null) {
                $bloqueos[] = [
                    'codigo' => 'notas_pendientes',
                    'actividades' => array_values(array_filter(
                        array_keys(array_filter(['entrevista' => $configuracion->entrevista_activa, 'prueba_tecnica' => $configuracion->prueba_tecnica_activa])),
                        fn (string $a) => ! $notas->has($a),
                    )),
                ];
            }

            return [
                'id' => $p->id,
                'aspirante' => $p->user->name,
                'estado_documental' => $doc['estado'],
                'cumplimiento_documental' => $doc['cumplimiento'],
                'documentos_completos' => $faltan === [],
                'resultado' => $resultado,
                'listo' => $bloqueos === [],
                'bloqueos' => $bloqueos,
                // Cambia si cambia la habilitación, la referencia, el resultado o cualquier nota (valor o fecha de edición).
                'huella' => sha1(json_encode([
                    $p->id, $doc['estado'], $doc['cumplimiento'], $doc['ref_id'], $doc['vigente'], $resultado,
                    $notas->sortKeys()->map(fn ($n) => [$n->actividad, (string) $n->nota, $n->updated_at?->getTimestamp()])->values()->all(),
                ])),
            ];
        })->values();

        return ['modo' => $modo, 'configuracion' => $configuracion, 'candidatos' => $candidatos];
    }

    /**
     * Sugerencia de finalistas: los primeros `$efectivo` listos. Si un empate cruza el
     * corte, el grupo empatado completo queda pendiente (no se sugiere ninguno) y se informa.
     *
     * @return array{0: array<int, int>, 1: ?array}
     */
    private function sugerirFinalistas(string $modo, Collection $listos, int $efectivo): array
    {
        if ($modo !== 'con_actividades' || $efectivo === 0) {
            return [[], null];
        }

        $lista = $listos->values();
        $corte = $lista[$efectivo - 1]['resultado'];
        $siguiente = $lista[$efectivo]['resultado'] ?? null;

        if ($siguiente === null || $siguiente !== $corte) {
            return [$lista->take($efectivo)->pluck('id')->all(), null];
        }

        $grupo = $lista->where('resultado', $corte);

        return [
            $lista->filter(fn (array $c) => $c['resultado'] > $corte)->pluck('id')->all(),
            [
                'posicion' => $grupo->first()['posicion'],
                'resultado' => $this->formatear($corte),
                'postulaciones' => $grupo->pluck('id')->all(),
            ],
        ];
    }

    /**
     * Finalistas de una versión que ya no coinciden con lo confirmado: dejaron de ser
     * elegibles o cambió su resultado, su nota o su habilitación documental.
     *
     * @return array<int, array{postulacion_id: int, motivo: string}>
     */
    protected function revisionDeLista(SeleccionFinalistaLista $lista, array $ranking): array
    {
        $actuales = $ranking['candidatos']->keyBy('id');
        $revisar = [];

        foreach ($lista->miembros as $miembro) {
            $actual = $actuales->get($miembro->postulacion_id);

            if ($actual === null || ! $actual['listo']) {
                $revisar[] = ['postulacion_id' => $miembro->postulacion_id, 'motivo' => 'ya_no_elegible'];
            } elseif ($actual['huella'] !== $miembro->huella) {
                $revisar[] = ['postulacion_id' => $miembro->postulacion_id, 'motivo' => 'cambio_resultado'];
            }
        }

        return $revisar;
    }
}
