<?php

namespace App\Http\Controllers\Api;

use App\Enums\ConvocatoriaEstado;
use App\Http\Controllers\Api\Concerns\RankingSeleccion;
use App\Http\Controllers\Api\Concerns\ResultadoAdicional;
use App\Http\Controllers\Api\Concerns\ResultadosDocumentales;
use App\Http\Controllers\Controller;
use App\Models\Convocatoria;
use App\Models\Parametro;
use App\Models\Postulacion;
use App\Models\SeleccionDecision;
use App\Models\SeleccionDecisionEvento;
use App\Models\SeleccionFinalistaLista;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Api\Concerns\DecisionesDeSeleccion;

/**
 * Selección, etapa 6: decisiones internas, control de cupos e historial.
 *
 * Una decisión (seleccionado / no_seleccionado) solo se registra con la convocatoria
 * en_proceso, sobre un finalista de la lista confirmada VIGENTE (la versión que el
 * cliente consultó, sin cambios pendientes de revisión) y con justificación.
 *
 * Cupos: Convocatoria::vacantes es el total original y no se modifica. Los ocupados son
 * las decisiones vigentes `seleccionado`; una decisión sigue ocupando su cupo hasta que
 * se rectifique de forma explícita, aunque su finalista pierda vigencia. Rectificar a
 * `no_seleccionado` (liberar) está permitido con la convocatoria en_proceso aunque el
 * candidato ya no figure en la lista vigente, para poder devolver el cupo sin volver a
 * declarar elegible al candidato.
 *
 * Concurrencia: el guardado toma el bloqueo de la fila de la convocatoria, el mismo de la
 * configuración, las notas y los finalistas, y dentro de él relee estado, lista y cupos.
 * Evaluación y Documentos NO toman ese bloqueo: un cambio suyo durante la operación se ve
 * en la siguiente lectura (la lista pasa a requerir revisión), no antes de guardar.
 *
 * Los ids llegan como enteros para que el 403 se resuelva antes que el 404.
 */
class DecisionSeleccionController extends Controller
{
    use DecisionesDeSeleccion;

    use RankingSeleccion;
    use ResultadoAdicional;
    use ResultadosDocumentales;

    private const POR_PAGINA = 20;

    public function index(Request $request, int $convocatoria): JsonResponse
    {
        $convocatoria = $this->convocatoriaEnAlcance($request->user(), $convocatoria);

        $lista = $this->ultimaLista($convocatoria->id);

        $decisiones = SeleccionDecision::query()
            ->with(['decididor:id,name', 'postulacion.user:id,name'])
            ->where('convocatoria_id', $convocatoria->id)
            ->get()
            ->keyBy('postulacion_id');

        // El Ranking vivo se calcula UNA vez: sirve para detectar la revisión de la lista y para decir cómo está hoy cada
        // fila. Solo hace falta si hay lista o alguna decisión que explicar.
        $ranking = ($lista !== null || $decisiones->isNotEmpty()) ? $this->construirRanking($convocatoria) : null;
        $revisar = $lista === null
            ? collect()
            : collect($this->revisionDeLista($lista, $ranking))->keyBy('postulacion_id');
        $sustentos = $this->sustentosDeDecisiones($convocatoria, $decisiones);
        // Indexado una sola vez: así cada fila se resuelve sin recorrer la colección.
        $indice = $ranking === null ? null : [
            'candidatos' => $ranking['candidatos']->keyBy('id'),
            'listos' => $ranking['listos']->keyBy('id'),
        ];

        // Finalistas de la lista vigente + decisiones existentes aunque su candidato ya no esté en ella.
        $filas = collect();
        foreach ($lista?->miembros ?? [] as $m) {
            $filas->put($m->postulacion_id, [
                'postulacion_id' => $m->postulacion_id,
                'aspirante' => $m->postulacion?->user?->name,
                'posicion' => $m->posicion,
                'en_lista' => true,
                // Lo que conservó la lista vigente al confirmarse: solo posición y resultado (el desglose no se congela).
                'confirmada' => [
                    'lista_version' => $lista->version,
                    'posicion' => $m->posicion,
                    'resultado' => $m->resultado === null ? null : (string) $m->resultado,
                ],
            ]);
        }
        foreach ($decisiones as $id => $d) {
            if (! $filas->has($id)) {
                $filas->put($id, ['postulacion_id' => $id, 'aspirante' => $d->postulacion?->user?->name, 'posicion' => null, 'en_lista' => false, 'confirmada' => null]);
            }
        }

        $filas = $filas->map(function (array $fila) use ($decisiones, $revisar, $indice, $sustentos) {
            $d = $decisiones->get($fila['postulacion_id']);
            // Con decisión, se avisa si el candidato ya no está en la lista vigente o esta cambió.
            $motivoRevision = $d === null ? null : (! $fila['en_lista'] ? 'fuera_de_lista' : ($revisar->get($fila['postulacion_id'])['motivo'] ?? null));

            return $fila + [
                'estado' => $d?->resultado ?? 'pendiente',
                'decision' => $d === null ? null : $this->presentarDecision($d),
                'requiere_revision' => $motivoRevision !== null,
                'motivo_revision' => $motivoRevision,
                // Cómo está hoy según el Ranking vivo (es otra cosa que lo confirmado: puede haber cambiado).
                'actual' => $this->situacionActual($indice, $fila['postulacion_id']),
                // Lo que sustentó la decisión: la fila de la versión de lista registrada en ella, si existe.
                'sustento' => $d === null ? null : $sustentos[$fila['postulacion_id']],
            ];
        })->sortBy(fn (array $f) => sprintf('%05d-%010d', $f['posicion'] ?? 99999, $f['postulacion_id']))->values();

        $total = $filas->count();
        $ultimaPagina = max(1, (int) ceil($total / self::POR_PAGINA));
        $pagina = min(max(1, (int) $request->query('page', 1)), $ultimaPagina);

        return response()->json([
            'data' => $filas->slice(($pagina - 1) * self::POR_PAGINA, self::POR_PAGINA)->values(),
            'meta' => [
                'current_page' => $pagina,
                'last_page' => $ultimaPagina,
                'per_page' => self::POR_PAGINA,
                'total' => $total,
                'estado_convocatoria' => $convocatoria->estado()->value('codigo'),
                'cupos' => $this->cupos($convocatoria),
                'pendientes' => $filas->where('estado', 'pendiente')->count(),
                'lista' => $lista === null ? null : [
                    'version' => $lista->version,
                    'total' => $lista->miembros->count(),
                    'requiere_revision' => $revisar->isNotEmpty(),
                    'modo' => $lista->modo,
                    'confirmada_por' => $lista->confirmador?->name,
                    'confirmada_en' => $lista->created_at?->toIso8601String(),
                ],
            ],
        ]);
    }

    public function update(Request $request, int $convocatoria, int $postulacion): JsonResponse
    {
        $convocatoria = $this->convocatoriaEnAlcance($request->user(), $convocatoria);
        Postulacion::query()->where('convocatoria_id', $convocatoria->id)->whereKey($postulacion)->exists() || abort(404, 'Candidato no encontrado.');

        $datos = $request->validate([
            'resultado' => ['required', 'string', 'in:'.implode(',', SeleccionDecision::RESULTADOS)],
            'lista_version' => ['nullable', 'integer', 'min:1'],
            'justificacion' => ['required', 'string', 'min:10', 'max:500'],
        ], [
            'resultado.in' => 'El resultado debe ser seleccionado o no_seleccionado.',
            'justificacion.required' => 'La justificación es obligatoria para registrar o rectificar una decisión.',
            'justificacion.min' => 'La justificación debe tener al menos 10 caracteres.',
        ]);

        $actor = $request->user();
        $resultado = $datos['resultado'];
        $motivo = $datos['justificacion'];
        $estado = 200;

        $decision = DB::transaction(function () use ($convocatoria, $postulacion, $datos, $resultado, $motivo, $actor, &$estado) {
            $fila = Convocatoria::query()->whereKey($convocatoria->id)->lockForUpdate()->first();
            $enProceso = (int) Parametro::porTemaYCodigo('estado_convocatoria', ConvocatoriaEstado::EnProceso->value)->id;
            if (! $fila || (int) $fila->estado_id !== $enProceso) {
                $this->rechazar('convocatoria', 'Las decisiones solo se pueden registrar o rectificar con la convocatoria en proceso.');
            }

            Postulacion::query()->where('convocatoria_id', $fila->id)->whereKey($postulacion)->whereHas('user', fn ($u) => $u->where('active', true))->lockForUpdate()->firstOrFail();
            $existente = SeleccionDecision::query()->where('postulacion_id', $postulacion)->lockForUpdate()->first();

            // Reenvío idéntico: devuelve lo existente sin crear otro evento.
            if ($existente !== null && $existente->resultado === $resultado && $existente->motivo === $motivo) {
                return $existente;
            }

            // Liberar un cupo es la transición real seleccionado -> no_seleccionado: nunca consume cupo, así que
            // se permite aunque la lista haya cambiado, requiera revisión o el candidato ya no figure en ella.
            // Cualquier otro cambio (incluida editar el motivo de un no_seleccionado) exige una lista vigente.
            $liberacion = $existente?->resultado === 'seleccionado' && $resultado === 'no_seleccionado';

            if ($liberacion) {
                // La versión que consta es la vigente en el servidor, bajo el bloqueo; la que envíe el cliente se ignora.
                $versionSustento = SeleccionFinalistaLista::query()->where('convocatoria_id', $fila->id)->max('version');
            } else {
                $versionSustento = $this->exigirListaVigente($fila, $datos['lista_version'] ?? null, $postulacion);
            }

            if ($resultado === 'seleccionado' && $existente?->resultado !== 'seleccionado') {
                $cupos = $this->cupos($fila);
                if ($cupos['cupos_disponibles'] <= 0) {
                    $this->conflicto('sin_cupos', 'No quedan cupos disponibles para seleccionar a este candidato.', ['cupos' => $cupos]);
                }
            }

            $anterior = $existente?->resultado;
            if ($existente === null) {
                $existente = SeleccionDecision::create([
                    'convocatoria_id' => $fila->id,
                    'postulacion_id' => $postulacion,
                    'resultado' => $resultado,
                    'motivo' => $motivo,
                    'lista_version' => $versionSustento,
                    'decidido_por' => $actor->id,
                ]);
                $estado = 201;
            } else {
                $existente->update([
                    'resultado' => $resultado,
                    'motivo' => $motivo,
                    'lista_version' => $versionSustento,
                    'decidido_por' => $actor->id,
                ]);
            }

            SeleccionDecisionEvento::create([
                'decision_id' => $existente->id,
                'resultado_anterior' => $anterior,
                'resultado_nuevo' => $resultado,
                'motivo' => $motivo,
                'lista_version' => $versionSustento,
                'actor_id' => $actor->id,
            ]);

            return $existente;
        });

        $decision->load('decididor:id,name', 'postulacion.user:id,name');

        return response()->json(['data' => [
            'postulacion_id' => $decision->postulacion_id,
            'aspirante' => $decision->postulacion?->user?->name,
            'estado' => $decision->resultado,
            'decision' => $this->presentarDecision($decision),
            'cupos' => $this->cupos($convocatoria->refresh()),
        ]], $estado);
    }

    /** Historial paginado de una decisión (solo los campos necesarios, sin consultas por evento). */
    public function historial(Request $request, int $convocatoria, int $postulacion): JsonResponse
    {
        $convocatoria = $this->convocatoriaEnAlcance($request->user(), $convocatoria);
        Postulacion::query()->where('convocatoria_id', $convocatoria->id)->whereKey($postulacion)->exists() || abort(404, 'Candidato no encontrado.');

        $eventos = SeleccionDecisionEvento::query()
            ->with('actor:id,name')
            ->whereIn('decision_id', SeleccionDecision::query()->select('id')->where('postulacion_id', $postulacion))
            ->orderByDesc('id')
            ->paginate(self::POR_PAGINA);

        return response()->json([
            'data' => $eventos->getCollection()->map(fn (SeleccionDecisionEvento $e) => [
                'resultado_anterior' => $e->resultado_anterior,
                'resultado_nuevo' => $e->resultado_nuevo,
                'motivo' => $e->motivo,
                'lista_version' => $e->lista_version,
                'registrado_por' => $e->actor?->name,
                'registrado_en' => $e->created_at?->toIso8601String(),
            ])->values(),
            'meta' => [
                'current_page' => $eventos->currentPage(),
                'last_page' => $eventos->lastPage(),
                'per_page' => $eventos->perPage(),
                'total' => $eventos->total(),
            ],
        ]);
    }

}
