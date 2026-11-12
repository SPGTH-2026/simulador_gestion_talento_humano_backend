<?php

namespace App\Http\Controllers\Api;

use App\Enums\ConvocatoriaEstado;
use App\Enums\PostulacionEstado;
use App\Http\Controllers\Api\Concerns\IdentificadorConvocatoria;
use App\Http\Controllers\Api\Concerns\ResultadosDocumentales;
use App\Http\Controllers\Controller;
use App\Models\Convocatoria;
use App\Models\Evaluacion;
use App\Models\Parametro;
use App\Models\Postulacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
    use ResultadosDocumentales;

    private const POR_PAGINA = 20;

    /** Convocatorias de las fichas del actor (sin borradores: aún no reciben candidatos). */
    public function convocatorias(Request $request): JsonResponse
    {
        $actor = $request->user();
        // Se evalúa una sola vez por petición; sin acceso el código ni se consulta.
        $veCodigo = $this->veIdentificadorConvocatoria($actor);
        $borrador = Parametro::porTemaYCodigo('estado_convocatoria', ConvocatoriaEstado::Borrador->value);

        $pagina = Convocatoria::query()
            ->select(array_merge(
                ['id', 'nombre', 'cargo', 'estado_id', 'vacantes'],
                $veCodigo ? ['codigo'] : [],
            ))
            ->with('estado:id,codigo,nombre')
            ->whereIn('ficha_id', $actor->fichaIds())
            ->where('estado_id', '!=', $borrador->id)
            ->orderByDesc('id')
            ->paginate(self::POR_PAGINA);

        return response()->json([
            'data' => $pagina->getCollection()->map(fn (Convocatoria $c) => [
                'id' => $c->id,
                'codigo' => $veCodigo ? $c->codigo : null,
                'nombre' => $c->nombre,
                'cargo' => $c->cargo,
                'estado' => ['codigo' => $c->estado->codigo, 'nombre' => $c->estado->nombre],
                'vacantes' => $c->vacantes,
            ])->values(),
            'meta' => $this->meta($pagina),
        ]);
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
