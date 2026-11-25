<?php

namespace App\Http\Controllers\Api;

use App\Enums\ConvocatoriaEstado;
use App\Enums\PostulacionEstado;
use App\Models\Postulacion;
use App\Services\DocumentosDePostulacion;
use App\Services\Notificaciones\Avisos;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use App\Http\Controllers\Api\Concerns\IdentificadorConvocatoria;
use App\Http\Controllers\Api\Concerns\PresentaPostulaciones;
use App\Http\Controllers\Controller;

class PostulacionEstadoController extends Controller
{
    use PresentaPostulaciones;
    use IdentificadorConvocatoria;

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

        if ($retirar) {
            app(Avisos::class)->postulacionRetirada($postulacion);
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

        // 4) Datos personales: sin ellos no se sabe si es menor ni si la libreta aplica.
        if (! $postulacion->datosPersonales()->exists()) {
            throw ValidationException::withMessages([
                'datos_personales' => 'Debes llenar tus datos personales antes de enviar la postulación.',
            ]);
        }

        // 4b) Documentos obligatorios: subidos o no_aplica (libreta de mujer o de menor).
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

        app(Avisos::class)->postulacionEnviada($postulacion);

        return response()->json([
            'data' => $this->presentar($postulacion->fresh(['estado', 'convocatoria.estado']), $request->user()),
        ]);
    }
}
