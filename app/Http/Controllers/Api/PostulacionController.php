<?php

namespace App\Http\Controllers\Api;

use App\Enums\PostulacionEstado;
use App\Enums\Role;
use App\Http\Controllers\Api\Concerns\IdentificadorConvocatoria;
use App\Http\Controllers\Controller;
use App\Models\Convocatoria;
use App\Models\Postulacion;
use App\Services\DocumentosDePostulacion;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Http\Controllers\Api\Concerns\PresentaPostulaciones;

/**
 * API del módulo de postulaciones (Paso 2: el aspirante se postula).
 *
 * Reglas de negocio:
 *  - Solo el rol Aspirante postula. El aprendiz no llega (lo corta el permiso
 *    'convocatorias:postular'); el instructor y el super admin pasan el permiso
 *    pero el controlador responde 403.
 *  - La ficha no viene del formulario: el alcance sale de $user->fichaIds().
 *    Una convocatoria fuera del alcance, inexistente o no publicada -> 404.
 *  - La ventana la decide Convocatoria::ventanaPostulacion() (publicada y
 *    fecha_apertura <= hoy <= fecha_cierre, en America/Bogota, ambas inclusive).
 *  - El estado se resuelve SIEMPRE por codigo (nunca por id).
 *  - La postulacion NACE en borrador: el POST crea/retoma el borrador y solo
 *    enviar() la pasa a postulada, tras validar los documentos obligatorios.
 *    Un borrador NO cuenta como postulante (conteos y revision lo ignoran).
 *
 * Paso 3: "mis postulaciones" (GET), descarte/retiro y reapertura (PATCH), y
 * envio (POST .../enviar). El listado filtra SIEMPRE por el user_id del
 * autenticado (cualquier rol ve solo lo suyo), y el PATCH aisla a las ajenas con
 * 404. Descartar/retirar solo exige que la convocatoria siga publicada; reabrir
 * y enviar usan la misma ventana que el POST (exigirVentanaAbierta()).
 */
class PostulacionController extends Controller
{
    use PresentaPostulaciones;

    use IdentificadorConvocatoria;

    /**
     * "Mis postulaciones": listado paginado de las postulaciones del autenticado.
     * SIEMPRE se filtra por su user_id, sea cual sea el rol: un instructor o un
     * super administrador ven las suyas (normalmente vacias), nunca las de otros.
     * No se filtra por el estado de la convocatoria: se listan aunque ya este
     * en_proceso o cerrada. Cada fila trae el resumen embebido de su convocatoria.
     */
    public function index(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ], $this->mensajes(), $this->atributos());

        $listado = Postulacion::query()
            ->with(['estado', 'convocatoria.estado'])
            ->where('user_id', $request->user()->id)
            // Las que la persona descartó (retirada y con la convocatoria cerrada) ya no aparecen en su lista.
            ->whereNull('descartada_at')
            ->orderByDesc('fecha_postulacion')
            ->orderByDesc('id')
            ->paginate($datos['per_page'] ?? 20, ['*'], 'page', $datos['page'] ?? 1);

        // `completo` para toda la página en lote (2 consultas), así el inicio del
        // aspirante no necesita una petición de documentos por cada borrador.
        $completos = app(DocumentosDePostulacion::class)->completosPara($listado->getCollection());

        return response()->json([
            'data' => $listado->getCollection()
                ->map(fn (Postulacion $p) => $this->presentar($p, $request->user()) + [
                    'completo' => $completos[$p->id] ?? false,
                ])
                ->values(),
            'meta' => [
                'current_page' => $listado->currentPage(),
                'last_page' => $listado->lastPage(),
                'per_page' => $listado->perPage(),
                'total' => $listado->total(),
            ],
        ]);
    }

    /**
     * El aspirante autenticado crea (o retoma) el BORRADOR de su postulación a
     * una convocatoria:
     *  - sin postulación previa: crea la fila en estado 'borrador' -> 201;
     *  - con un 'borrador': devuelve la misma fila -> 200;
     *  - con una 'retirada': la reabre en 'borrador' -> 200;
     *  - con una 'postulada': 422.
     *
     * Ya no se envía aquí: la transición a 'postulada' la hace enviar(), que
     * valida los documentos obligatorios.
     */
    public function store(Request $request, Convocatoria $convocatoria): JsonResponse
    {
        $user = $request->user();

        // 1) Solo los aspirantes se postulan. Instructor y super admin pasan el
        //    middleware 'can:' pero no son autorizados aquí.
        if ($user->role !== Role::Aspirante) {
            abort(403, 'Solo los aspirantes pueden postularse a las convocatorias.');
        }

        // 2) Aislamiento por ficha: fuera del alcance (u otra ficha) -> 404.
        if (! in_array($convocatoria->ficha_id, $user->fichaIds(), true)) {
            abort(404, 'Convocatoria no encontrada.');
        }

        // 3) Ventana. No publicada -> 404 (no se revela su existencia).
        $this->exigirVentanaAbierta($convocatoria);

        // 4) Postulación previa de ESTE aspirante a ESTA convocatoria.
        $previa = Postulacion::query()
            ->where('convocatoria_id', $convocatoria->id)
            ->where('user_id', $user->id)
            ->first();

        if ($previa !== null) {
            if ($previa->estado?->codigo === PostulacionEstado::Postulada->value) {
                throw $this->yaPostulado();
            }

            // Retirada -> reabrir la MISMA fila en borrador con un UPDATE
            // condicionado. Si otra petición se adelantó (0 filas), ya no está
            // retirada: 422. Un borrador simplemente se retoma tal cual.
            if ($previa->estado?->codigo === PostulacionEstado::Retirada->value) {
                $afectadas = Postulacion::query()
                    ->whereKey($previa->id)
                    ->where('estado_id', $this->parametroId('estado_postulacion', PostulacionEstado::Retirada->value))
                    ->update([
                        'estado_id' => $this->parametroId('estado_postulacion', PostulacionEstado::Borrador->value),
                        'fecha_retiro' => null,
                    ]);

                if ($afectadas === 0) {
                    throw $this->yaPostulado();
                }

            }

            return response()->json([
                'data' => $this->presentar($previa->fresh(['estado', 'convocatoria.estado']), $request->user()),
            ]);
        }

        // 5) Alta nueva en BORRADOR. El UNIQUE(convocatoria_id, user_id) es el
        //    respaldo ante envíos dobles o simultáneos: se captura y se responde
        //    422, nunca 500.
        try {
            $nueva = new Postulacion();
            $nueva->forceFill([
                'convocatoria_id' => $convocatoria->id,
                'user_id' => $user->id,
                'estado_id' => $this->parametroId('estado_postulacion', PostulacionEstado::Borrador->value),
                'fecha_postulacion' => now(),
            ])->save();
        } catch (QueryException $e) {
            if (! $this->esDuplicado($e)) {
                throw $e;
            }

            throw $this->yaPostulado();
        }

        return response()->json([
            'data' => $this->presentar($nueva->load(['estado', 'convocatoria.estado']), $request->user()),
        ], 201);
    }

}
