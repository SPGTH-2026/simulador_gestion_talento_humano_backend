<?php

namespace App\Http\Controllers\Api;

use App\Enums\ConvocatoriaEstado;
use App\Http\Controllers\Api\Concerns\RankingSeleccion;
use App\Http\Controllers\Api\Concerns\ResultadoAdicional;
use App\Http\Controllers\Api\Concerns\ResultadosDocumentales;
use App\Http\Controllers\Controller;
use App\Models\Convocatoria;
use App\Models\Parametro;
use App\Models\SeleccionFinalistaLista;
use App\Models\SeleccionRankingParametro;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Selección, etapa 5: ranking de candidatos listos y objetivo de finalistas.
 *
 * El ranking se ordena completo (todos los listos) y después se pagina, de modo que
 * la posición es la misma en cualquier página. Notas iguales comparten posición
 * (1, 2, 2, 4). El orden entre empatados solo sirve para presentar.
 *
 * Los ids llegan como enteros para que el 403 se resuelva antes que el 404.
 */
class RankingSeleccionController extends Controller
{
    use RankingSeleccion;
    use ResultadoAdicional;
    use ResultadosDocumentales;

    private const POR_PAGINA = 20;

    public function show(Request $request, int $convocatoria): JsonResponse
    {
        $convocatoria = $this->convocatoriaEnAlcance($request->user(), $convocatoria);
        $ranking = $this->construirRanking($convocatoria);

        $listos = $ranking['listos'];
        $total = $listos->count();
        $ultimaPagina = max(1, (int) ceil($total / self::POR_PAGINA));
        $pagina = min(max(1, (int) $request->query('page', 1)), $ultimaPagina);

        $lista = SeleccionFinalistaLista::query()
            ->with('miembros:id,lista_id,postulacion_id,huella')
            ->where('convocatoria_id', $convocatoria->id)
            ->orderByDesc('version')
            ->first();
        $enLista = $lista?->miembros->pluck('postulacion_id')->all() ?? [];
        $revisar = $lista ? $this->revisionDeLista($lista, $ranking) : [];

        return response()->json([
            'data' => $listos->slice(($pagina - 1) * self::POR_PAGINA, self::POR_PAGINA)->map(fn (array $c) => [
                'postulacion_id' => $c['id'],
                'aspirante' => $c['aspirante'],
                // Sin actividades no hay nota, posición ni sugerencia automática (null).
                'posicion' => $c['posicion'],
                'resultado_adicional' => $c['resultado'] === null ? null : $this->formatear($c['resultado']),
                'cumplimiento_documental' => $c['cumplimiento_documental'],
                'sugerencia' => $c['sugerencia'],
                'finalista' => in_array($c['id'], $enLista, true),
            ])->values(),
            'meta' => [
                'current_page' => $pagina,
                'last_page' => $ultimaPagina,
                'per_page' => self::POR_PAGINA,
                'total' => $total,
                'modo' => $ranking['modo'],
                'version' => $ranking['version'],
                'vacantes' => $convocatoria->vacantes,
                'postuladas' => $ranking['candidatos']->count(),
                'listos' => $total,
                'no_listos' => $ranking['candidatos']->count() - $total,
                'objetivo' => [
                    'valor' => $ranking['objetivo'],
                    'configurado' => $ranking['objetivo_configurado'] !== null,
                    'efectivo' => $ranking['objetivo_efectivo'],
                ],
                'sugeridos' => count($ranking['sugeridos']),
                // Grupo empatado que cruza el corte: su resolución queda pendiente y exige justificación.
                'empate_en_corte' => $ranking['empate'],
                'lista_confirmada' => $lista === null ? null : [
                    'version' => $lista->version,
                    'confirmada_en' => $lista->created_at?->toIso8601String(),
                    'total' => count($enLista),
                    'requiere_revision' => $revisar !== [],
                ],
            ],
        ]);
    }

    /** Fija (o restablece con null) el objetivo de finalistas; la sugerencia por defecto es 2 x vacantes. */
    public function objetivo(Request $request, int $convocatoria): JsonResponse
    {
        $this->convocatoriaEnAlcance($request->user(), $convocatoria);

        $datos = $request->validate([
            'objetivo' => ['present', 'nullable', 'integer', 'between:1,500'],
        ], [
            'objetivo.between' => 'El objetivo debe ser un entero entre 1 y 500.',
            'objetivo.present' => 'Envía el objetivo (o null para volver a 2 x vacantes).',
        ]);
        $actor = $request->user();

        DB::transaction(function () use ($convocatoria, $datos, $actor) {
            // Mismo bloqueo que las notas y la confirmación de finalistas.
            $fila = Convocatoria::query()->whereKey($convocatoria)->lockForUpdate()->first();
            $enProceso = (int) Parametro::porTemaYCodigo('estado_convocatoria', ConvocatoriaEstado::EnProceso->value)->id;

            if (! $fila || (int) $fila->estado_id !== $enProceso) {
                throw ValidationException::withMessages([
                    'convocatoria' => 'El objetivo solo se puede cambiar con la convocatoria en proceso.',
                ]);
            }

            SeleccionRankingParametro::query()->updateOrCreate(
                ['convocatoria_id' => $fila->id],
                ['objetivo_finalistas' => $datos['objetivo'], 'actualizado_por' => $actor->id],
            );
        });

        $convocatoria = Convocatoria::query()->findOrFail($convocatoria);
        $ranking = $this->construirRanking($convocatoria);

        return response()->json(['data' => [
            'objetivo' => $ranking['objetivo'],
            'configurado' => $ranking['objetivo_configurado'] !== null,
            'efectivo' => $ranking['objetivo_efectivo'],
            'vacantes' => $convocatoria->vacantes,
            'version' => $ranking['version'],
        ]]);
    }
}
