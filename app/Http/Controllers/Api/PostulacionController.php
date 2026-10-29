<?php

namespace App\Http\Controllers\Api;

use App\Enums\ConvocatoriaEstado;
use App\Enums\PostulacionEstado;
use App\Enums\Role;
use App\Enums\VentanaPostulacion;
use App\Http\Controllers\Api\Concerns\IdentificadorConvocatoria;
use App\Http\Controllers\Controller;
use App\Models\Convocatoria;
use App\Models\Parametro;
use App\Models\Postulacion;
use App\Models\User;
use App\Services\DocumentosDePostulacion;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

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
            ->orderByDesc('fecha_postulacion')
            ->orderByDesc('id')
            ->paginate($datos['per_page'] ?? 20, ['*'], 'page', $datos['page'] ?? 1);

        return response()->json([
            'data' => $listado->getCollection()
                ->map(fn (Postulacion $p) => $this->presentar($p, $request->user()))
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

    /**
     * Descarte, retiro, reapertura y reactivación de una postulación propia.
     *
     * El estado se identifica SIEMPRE por código (borrador / postulada /
     * retirada); un id numérico devuelve 422. Una postulación ajena (o
     * inexistente) responde 404 ANTES de leer el cuerpo. Descartar un borrador
     * o retirar una postulada solo exige que la convocatoria siga publicada.
     * Reabrir (retirada -> borrador) y reactivar (retirada -> postulada) usan
     * la misma ventana que el POST; reactivar exige además los documentos
     * obligatorios completos y responde 422 con `faltantes`. Un borrador NO
     * salta a postulada por aquí: eso lo hace enviar(). El cambio se hace con
     * un UPDATE condicionado por el estado leído: si otra petición se adelantó,
     * afecta 0 filas y se responde 422 (nunca 500).
     */
    public function estado(
        Request $request,
        Postulacion $postulacion,
        DocumentosDePostulacion $documentos,
    ): JsonResponse {
        // 1) Frontera de aislamiento ANTES de validar el cuerpo: una postulacion
        //    ajena responde 404 aunque el cuerpo venga invalido (no la revela).
        if ($postulacion->user_id !== $request->user()->id) {
            abort(404, 'Postulación no encontrada.');
        }

        // 2) El estado se identifica por codigo.
        $datos = $request->validate([
            'estado' => ['required', 'string'],
        ], $this->mensajes(), $this->atributos());

        $destino = $datos['estado'];
        $actual = $postulacion->estado?->codigo;

        if (ctype_digit($destino)) {
            throw ValidationException::withMessages([
                'estado' => 'El estado debe enviarse por su código, no por su id.',
            ]);
        }

        if (! in_array($destino, PostulacionEstado::codigos(), true)) {
            throw ValidationException::withMessages(['estado' => 'El estado no es válido.']);
        }

        // 'postulada' desde un borrador solo se alcanza por el envío (que valida
        // documentos); aquí se rechaza guiando al endpoint correcto. Desde
        // 'retirada' SÍ se admite: es "volver a postularme" (reactivación).
        $reactivar = $actual === PostulacionEstado::Retirada->value
            && $destino === PostulacionEstado::Postulada->value;

        if ($destino === PostulacionEstado::Postulada->value && ! $reactivar) {
            throw ValidationException::withMessages([
                'estado' => 'Para enviar tu postulación usa el endpoint de envío.',
            ]);
        }

        if ($actual === $destino) {
            throw ValidationException::withMessages([
                'estado' => "La postulación ya está en estado {$destino}.",
            ]);
        }

        $permitidas = PostulacionEstado::transiciones()[$actual] ?? [];

        if (! in_array($destino, $permitidas, true)) {
            throw ValidationException::withMessages([
                'estado' => "No se puede pasar de {$actual} a {$destino}.",
            ]);
        }

        $retirar = $destino === PostulacionEstado::Retirada->value;

        if ($retirar) {
            // El retiro no depende de las fechas: basta con que la convocatoria
            // siga publicada (aun despues del cierre, mientras no avance).
            if ($postulacion->convocatoria?->estado?->codigo !== ConvocatoriaEstado::Publicada->value) {
                throw ValidationException::withMessages([
                    'estado' => 'No puedes retirar tu postulación: la convocatoria ya no está publicada.',
                ]);
            }
        } elseif ($reactivar) {
            // Volver a postularme: misma ventana que el envío y obligatorios
            // completos. Si faltan, la postulación SIGUE retirada (no se toca).
            $this->exigirVentanaAbierta($postulacion->convocatoria, 'estado');

            $faltantes = $documentos->faltantes($postulacion);

            if ($faltantes !== []) {
                return response()->json([
                    'message' => 'No se pudo reactivar la postulación: faltan documentos obligatorios.',
                    'errors' => [
                        'documentos' => [$documentos->mensaje($faltantes)],
                    ],
                    'faltantes' => $faltantes,
                ], 422);
            }
        } else {
            // Reapertura (retirada -> borrador): misma ventana que el POST.
            $this->exigirVentanaAbierta($postulacion->convocatoria, 'estado');
        }

        // 3) UPDATE condicionado por el estado leido (protege contra carreras).
        $nuevoEstado = match (true) {
            $reactivar => PostulacionEstado::Postulada->value,
            $retirar => PostulacionEstado::Retirada->value,
            default => PostulacionEstado::Borrador->value,
        };

        $cambios = [
            'estado_id' => $this->parametroId('estado_postulacion', $nuevoEstado),
            'fecha_retiro' => $retirar ? now() : null,
        ];

        if ($reactivar) {
            $cambios['fecha_postulacion'] = now();
        }

        $afectadas = Postulacion::query()
            ->whereKey($postulacion->id)
            ->where('estado_id', $this->parametroId('estado_postulacion', $actual))
            ->update($cambios);

        if ($afectadas === 0) {
            throw ValidationException::withMessages([
                'estado' => 'No se pudo actualizar la postulación: su estado cambió. Inténtalo de nuevo.',
            ]);
        }

        return response()->json([
            'data' => $this->presentar($postulacion->fresh(['estado', 'convocatoria.estado']), $request->user()),
        ]);
    }

    /**
     * Envía el borrador: valida los documentos obligatorios y pasa la
     * postulación a 'postulada'. Solo el dueño (los demás -> 404).
     *
     *  - Ventana no publicada -> 404; antes de apertura o cerrada -> 422 (mismas
     *    reglas y mensajes que el POST).
     *  - Estado distinto de 'borrador' -> 422.
     *  - Obligatorios pendientes -> 422 con `faltantes` (código, nombre y, si no
     *    hay sexo con libreta pedida, el motivo para indicarlo).
     *  - UPDATE condicionado por estado: si otra petición se adelantó -> 422.
     */
    public function enviar(
        Request $request,
        Postulacion $postulacion,
        DocumentosDePostulacion $documentos,
    ): JsonResponse {
        // 1) Frontera de aislamiento: una postulación ajena responde 404.
        if ($postulacion->user_id !== $request->user()->id) {
            abort(404, 'Postulación no encontrada.');
        }

        // 2) Ventana: mismas reglas y mensajes que el POST.
        $this->exigirVentanaAbierta($postulacion->convocatoria);

        // 3) Solo un borrador se envía.
        if ($postulacion->estado?->codigo !== PostulacionEstado::Borrador->value) {
            throw ValidationException::withMessages([
                'postulacion' => 'Solo se puede enviar una postulación en borrador.',
            ]);
        }

        // 4) Documentos obligatorios: subidos o no_aplica (libreta de mujer).
        $faltantes = $documentos->faltantes($postulacion);

        if ($faltantes !== []) {
            return response()->json([
                'message' => 'No se pudo enviar la postulación: faltan documentos obligatorios.',
                'errors' => [
                    'documentos' => [$documentos->mensaje($faltantes)],
                ],
                'faltantes' => $faltantes,
            ], 422);
        }

        // 5) UPDATE condicionado: protege contra carreras y fija la fecha real.
        $afectadas = Postulacion::query()
            ->whereKey($postulacion->id)
            ->where('estado_id', $this->parametroId('estado_postulacion', PostulacionEstado::Borrador->value))
            ->update([
                'estado_id' => $this->parametroId('estado_postulacion', PostulacionEstado::Postulada->value),
                'fecha_postulacion' => now(),
                'fecha_retiro' => null,
            ]);

        if ($afectadas === 0) {
            throw ValidationException::withMessages([
                'postulacion' => 'No se pudo enviar la postulación: su estado cambió. Inténtalo de nuevo.',
            ]);
        }

        return response()->json([
            'data' => $this->presentar($postulacion->fresh(['estado', 'convocatoria.estado']), $request->user()),
        ]);
    }

    // ── Persistencia ───────────────────────────────────────────────────

    /** Id de un parámetro por tema+código, o 500 si falta el seeder. */
    private function parametroId(string $tema, string $codigo): int
    {
        $parametro = Parametro::porTemaYCodigo($tema, $codigo);

        abort_unless($parametro, 500, "Parámetro no encontrado: {$tema}.{$codigo}. ¿Se ejecutó ParametroSeeder?");

        return (int) $parametro->id;
    }

    /**
     * Violación del índice UNIQUE(convocatoria_id, user_id): SOLO el SQLSTATE
     * 23000 con código de driver 1062 (MySQL). Cualquier otro error se relanza.
     */
    private function esDuplicado(QueryException $e): bool
    {
        return $e->getCode() === '23000' && (int) ($e->errorInfo[1] ?? 0) === 1062;
    }

    private function yaPostulado(): ValidationException
    {
        return ValidationException::withMessages([
            'postulacion' => 'Ya estás postulado en esta convocatoria.',
        ]);
    }

    /**
     * Exige que la convocatoria acepte postulaciones HOY, con las MISMAS reglas
     * y mensajes que el POST: no publicada -> 404 (no se revela su existencia);
     * antes de apertura o cerrada -> 422. La clave decide bajo que campo del
     * cuerpo viaja el error ('postulacion' en el POST, 'estado' en el PATCH).
     */
    private function exigirVentanaAbierta(Convocatoria $convocatoria, string $clave = 'postulacion'): void
    {
        switch ($convocatoria->ventanaPostulacion()) {
            case VentanaPostulacion::NoPublicada:
                abort(404, 'Convocatoria no encontrada.');

            case VentanaPostulacion::AntesDeApertura:
                throw ValidationException::withMessages([
                    $clave => 'La convocatoria aún no está abierta. Abre el '
                        .$convocatoria->fecha_apertura?->toDateString().'.',
                ]);

            case VentanaPostulacion::Cerrada:
                throw ValidationException::withMessages([
                    $clave => 'La convocatoria ya cerró el '
                        .$convocatoria->fecha_cierre?->toDateString().'.',
                ]);
        }
    }

    // ── Presentación ───────────────────────────────────────────────────

    private function presentar(Postulacion $postulacion, User $user): array
    {
        return [
            'id' => $postulacion->id,
            'estado' => $this->parametro($postulacion->estado),
            'fecha_postulacion' => $postulacion->fecha_postulacion?->toIso8601String(),
            'fecha_retiro' => $postulacion->fecha_retiro?->toIso8601String(),
            'convocatoria' => $this->resumenConvocatoria($postulacion->convocatoria, $user),
        ];
    }

    /**
     * Resumen embebido: permite mostrar la tarjeta sin pedir el detalle.
     *
     * El `codigo` (CONV-AAAA-NNN) es un dato de gestión: solo lo recibe quien
     * está en la lista blanca (instructor, super admin y el subrol
     * gestor_convocatorias). Para el resto llega en null.
     */
    private function resumenConvocatoria(Convocatoria $convocatoria, User $user): array
    {
        return [
            'id' => $convocatoria->id,
            'codigo' => $this->veIdentificadorConvocatoria($user) ? $convocatoria->codigo : null,
            'nombre' => $convocatoria->nombre,
            'cargo' => $convocatoria->cargo,
            'area' => $convocatoria->area,
            'estado' => $this->parametro($convocatoria->estado),
            'fecha_apertura' => $convocatoria->fecha_apertura?->toDateString(),
            'fecha_cierre' => $convocatoria->fecha_cierre?->toDateString(),
        ];
    }

    private function parametro(?Parametro $parametro): ?array
    {
        return $parametro
            ? ['id' => $parametro->id, 'codigo' => $parametro->codigo, 'nombre' => $parametro->nombre]
            : null;
    }

    // ── Mensajes en español (sin cambiar APP_LOCALE) ────────────────────

    /** @return array<string, string> */
    private function mensajes(): array
    {
        return [
            'required' => 'El campo :attribute es obligatorio.',
            'string' => 'El campo :attribute debe ser texto.',
            'integer' => 'El campo :attribute debe ser un número entero.',
            'min' => 'El campo :attribute debe ser al menos :min.',
            'max' => 'El campo :attribute no debe superar :max.',
        ];
    }

    /** @return array<string, string> */
    private function atributos(): array
    {
        return [
            'page' => 'página',
            'per_page' => 'elementos por página',
            'estado' => 'estado',
        ];
    }
}
