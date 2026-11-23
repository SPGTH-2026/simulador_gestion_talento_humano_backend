<?php

namespace App\Http\Controllers\Api;

use App\Enums\ConvocatoriaEstado;
use App\Enums\PostulacionEstado;
use App\Http\Controllers\Api\Concerns\IdentificadorConvocatoria;
use App\Http\Controllers\Api\Concerns\RankingSeleccion;
use App\Http\Controllers\Api\Concerns\ResultadoAdicional;
use App\Http\Controllers\Api\Concerns\ResultadosDocumentales;
use App\Http\Controllers\Controller;
use App\Models\Convocatoria;
use App\Models\Evaluacion;
use App\Models\Parametro;
use App\Models\Postulacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * Módulo de Selección y Preselección, etapa 1 (solo lectura).
 *
 * Lista las convocatorias del alcance del actor y los candidatos (postulaciones
 * enviadas) de cada una, con el estado documental que ya calculó Evaluación.
 * No decide ni modifica nada.
 */
class SeleccionController extends Controller
{
    use IdentificadorConvocatoria;
    use RankingSeleccion;
    use ResultadoAdicional;
    use ResultadosDocumentales;

    private const POR_PAGINA = 20;

    // Máximo de convocatorias del bloque «Requiere tu atención» de Inicio.
    private const LIMITE_ATENCION = 4;

    /**
     * Convocatorias de las fichas del actor (sin borradores: aún no reciben candidatos).
     *
     * Parámetros opcionales:
     *  - estado: código de estado (publicada, en_proceso, cerrada). Se aplica ANTES de paginar.
     *  - convocatoria: id de una sola convocatoria del alcance (para resolver un enlace directo).
     *  - atencion=1: solo las que requieren atención del seleccionador (ver convocatoriasConAtencion). No pagina:
     *    devuelve hasta LIMITE_ATENCION y meta.hay_mas.
     * Cada fila trae `candidatos`: las postulaciones en estado postulada, la misma población del endpoint de candidatos.
     */
    public function convocatorias(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'estado' => ['nullable', 'string', Rule::in(array_diff(ConvocatoriaEstado::codigos(), [ConvocatoriaEstado::Borrador->value]))],
            'atencion' => ['nullable', 'boolean'],
            // Una sola convocatoria por id (enlaces directos fuera de la primera página): solo si es del alcance.
            'convocatoria' => ['nullable', 'integer'],
        ]);

        $actor = $request->user();
        // Se evalúa una sola vez por petición; sin acceso el código ni se consulta.
        $veCodigo = $this->veIdentificadorConvocatoria($actor);
        $borrador = Parametro::porTemaYCodigo('estado_convocatoria', ConvocatoriaEstado::Borrador->value);

        $consulta = Convocatoria::query()
            ->select(array_merge(
                ['id', 'nombre', 'cargo', 'estado_id', 'vacantes'],
                $veCodigo ? ['codigo'] : [],
            ))
            ->with('estado:id,codigo,nombre')
            ->whereIn('ficha_id', $actor->fichaIds())
            ->where('estado_id', '!=', $borrador->id)
            ->orderByDesc('id');

        if ($request->boolean('atencion')) {
            return $this->convocatoriasConAtencion($consulta, $veCodigo);
        }

        if (($datos['convocatoria'] ?? null) !== null) {
            $consulta->where('id', $datos['convocatoria']);
        }

        if (($datos['estado'] ?? null) !== null) {
            $consulta->where('estado_id', Parametro::porTemaYCodigo('estado_convocatoria', $datos['estado'])->id);
        }

        $pagina = $consulta->paginate(self::POR_PAGINA);
        $conteos = $this->conteoCandidatos($pagina->getCollection()->pluck('id'));

        return response()->json([
            'data' => $pagina->getCollection()->map(fn (Convocatoria $c) => $this->presentarConvocatoria($c, $veCodigo, $conteos))->values(),
            'meta' => $this->meta($pagina),
        ]);
    }

    /**
     * Convocatorias que requieren la atención del seleccionador: en proceso, con al menos un candidato LISTO para
     * Ranking y sin ninguna lista de finalistas confirmada. (La lista vigente es la versión más alta; una lista que
     * pide revisión sigue siendo vigente y queda fuera de este criterio.)
     *
     * "Listo" se calcula en PHP (documentos + actividades) y no tiene equivalente en SQL; para no duplicar esas reglas
     * se reutiliza construirRanking, pero solo sobre las candidatas que SQL ya redujo (en proceso, con postuladas y sin
     * lista) y con corte temprano: se detiene al hallar LIMITE_ATENCION + 1 (el extra solo sirve para meta.hay_mas).
     * Las convocatorias con la configuración pendiente nunca tienen candidatos listos: se descartan en SQL y no entran
     * (limitación conocida: necesitan atención, pero para configurarse, no para el ranking).
     */
    private function convocatoriasConAtencion($consulta, bool $veCodigo): JsonResponse
    {
        // Cacheado 60 s por usuario y alcance: es el listado más costoso (un ranking por convocatoria) y no necesita tiempo
        // real. Tras una acción puede tardar hasta 60 s en reflejarse.
        $actor = request()->user();
        $clave = 'seleccion.atencion.'.$actor->id.'.'.sha1(json_encode([$actor->fichaIds(), $veCodigo]));

        return response()->json(Cache::remember($clave, 60, fn () => $this->calcularConvocatoriasConAtencion($consulta, $veCodigo)));
    }

    /** @return array{data: array, meta: array} */
    private function calcularConvocatoriasConAtencion($consulta, bool $veCodigo): array
    {
        $enProceso = Parametro::porTemaYCodigo('estado_convocatoria', ConvocatoriaEstado::EnProceso->value);
        $postulada = Parametro::porTemaYCodigo('estado_postulacion', PostulacionEstado::Postulada->value);

        $consulta
            ->where('estado_id', $enProceso->id)
            ->whereExists(fn ($q) => $q->selectRaw('1')
                ->from('postulaciones')
                ->whereColumn('postulaciones.convocatoria_id', 'convocatorias.id')
                ->where('postulaciones.estado_id', $postulada->id))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')
                ->from('seleccion_finalistas_listas')
                ->whereColumn('seleccion_finalistas_listas.convocatoria_id', 'convocatorias.id'))
            // Sin configuración (modo «pendiente_de_configurar» de construirRanking) nadie está listo: se descarta aquí, sin
            // calcular el ranking de la convocatoria.
            ->whereExists(fn ($q) => $q->selectRaw('1')
                ->from('seleccion_configuraciones')
                ->whereColumn('seleccion_configuraciones.convocatoria_id', 'convocatorias.id'));

        $halladas = collect();
        foreach ($consulta->cursor() as $convocatoria) {
            $listos = $this->construirRanking($convocatoria)['listos']->count();
            if ($listos === 0) {
                continue;
            }
            $halladas->push([$convocatoria, $listos]);
            if ($halladas->count() > self::LIMITE_ATENCION) {
                break;
            }
        }

        $visibles = $halladas->take(self::LIMITE_ATENCION);
        $conteos = $this->conteoCandidatos($visibles->map(fn (array $h) => $h[0]->id));

        return [
            'data' => $visibles->map(fn (array $h) => $this->presentarConvocatoria($h[0], $veCodigo, $conteos) + ['listos' => $h[1]])->values()->all(),
            'meta' => ['limite' => self::LIMITE_ATENCION, 'hay_mas' => $halladas->count() > self::LIMITE_ATENCION],
        ];
    }

    /** Postulaciones en estado postulada por convocatoria, en UNA consulta agrupada (convocatoria_id => total). */
    private function conteoCandidatos(Collection $convocatoriaIds): Collection
    {
        if ($convocatoriaIds->isEmpty()) {
            return collect();
        }

        $postulada = Parametro::porTemaYCodigo('estado_postulacion', PostulacionEstado::Postulada->value);

        return Postulacion::query()
            ->selectRaw('convocatoria_id, COUNT(*) AS total')
            ->whereIn('convocatoria_id', $convocatoriaIds)
            ->where('estado_id', $postulada->id)
            ->whereHas('user', fn ($u) => $u->where('active', true))
            ->groupBy('convocatoria_id')
            ->pluck('total', 'convocatoria_id');
    }

    private function presentarConvocatoria(Convocatoria $c, bool $veCodigo, Collection $conteos): array
    {
        return [
            'id' => $c->id,
            'codigo' => $veCodigo ? $c->codigo : null,
            'nombre' => $c->nombre,
            'cargo' => $c->cargo,
            'estado' => ['codigo' => $c->estado->codigo, 'nombre' => $c->estado->nombre],
            'vacantes' => $c->vacantes,
            'candidatos' => (int) ($conteos->get($c->id) ?? 0),
        ];
    }

    /** Postulaciones enviadas de una convocatoria del alcance del actor. */
    public function candidatos(Request $request, Convocatoria $convocatoria): JsonResponse
    {
        // Fuera del alcance se responde igual que si no existiera.
        if (! in_array((int) $convocatoria->ficha_id, $request->user()->fichaIds(), true)) {
            abort(404, 'Convocatoria no encontrada.');
        }

        $postulada = Parametro::porTemaYCodigo('estado_postulacion', PostulacionEstado::Postulada->value);

        $pagina = Postulacion::query()
            ->select('id', 'user_id')
            ->with('user:id,name')
            ->where('convocatoria_id', $convocatoria->id)
            ->where('estado_id', $postulada->id)
            ->whereHas('user', fn ($u) => $u->where('active', true))
            ->orderBy('id')
            ->paginate(self::POR_PAGINA);

        // Una sola consulta agregada para toda la página, sin importar cuántos candidatos haya.
        $ids = $pagina->getCollection()->pluck('id');

        $resultados = $this->soloElegibles(Evaluacion::query()->whereIn('postulacion_id', $ids))
            ->selectRaw('postulacion_id, COUNT(*) AS total, SUM(CASE WHEN pasa_seleccion THEN 1 ELSE 0 END) AS habilitadas, MAX(cumplimiento) AS cumplimiento')
            ->groupBy('postulacion_id')
            ->get()
            ->keyBy('postulacion_id');

        // Última designación de referencia y su vigencia, en bloque para la página.
        $referencias = $this->ultimasReferencias($ids);

        return response()->json([
            'data' => $pagina->getCollection()->map(function (Postulacion $p) use ($resultados, $referencias) {
                $r = $resultados->get($p->id);
                $referencia = $referencias->get($p->id);

                [$estado, $cumplimiento] = $this->clasificarEstadoDocumental(
                    (int) ($r->total ?? 0),
                    (int) ($r->habilitadas ?? 0),
                    isset($r->cumplimiento) ? (int) $r->cumplimiento : null,
                    $referencia['ref'] ?? null,
                    $referencia['vigente'] ?? false,
                );

                return [
                    'id' => $p->id,
                    'aspirante' => $p->user->name,
                    'estado_documental' => $estado,
                    'cumplimiento' => $cumplimiento,
                ];
            })->values(),
            'meta' => $this->meta($pagina),
        ]);
    }

    private function meta($pagina): array
    {
        return [
            'current_page' => $pagina->currentPage(),
            'last_page' => $pagina->lastPage(),
            'per_page' => $pagina->perPage(),
            'total' => $pagina->total(),
        ];
    }
}
