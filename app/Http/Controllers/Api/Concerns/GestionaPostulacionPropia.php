<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Enums\PostulacionEstado;
use App\Enums\VentanaPostulacion;
use App\Models\Postulacion;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Reglas compartidas por el paso de documentos y skills de una postulación:
 * aislamiento del dueño, editabilidad según la ventana y resolución de
 * parámetros por tema+código. Todo en español y con el mismo criterio que el
 * resto del módulo de postulaciones.
 */
trait GestionaPostulacionPropia
{
    /** Frontera de aislamiento: una postulación ajena (o inexistente) -> 404. */
    private function exigirPropia(Request $request, Postulacion $postulacion): void
    {
        if ($postulacion->user_id !== $request->user()->id) {
            abort(404, 'Postulación no encontrada.');
        }
    }

    /**
     * Solo se editan documentos/skills con la convocatoria publicada y dentro de
     * la ventana, y con la postulación en 'borrador' (antes de enviar) o
     * 'postulada' (después de enviar). Una 'retirada' queda bloqueada hasta
     * reabrirla. La clave decide bajo qué campo viaja el error 422.
     */
    private function exigirEditable(Postulacion $postulacion, string $clave): void
    {
        $convocatoria = $postulacion->convocatoria;

        switch ($convocatoria->ventanaPostulacion()) {
            case VentanaPostulacion::NoPublicada:
                abort(404, 'Postulación no encontrada.');

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

        if (! in_array($postulacion->estado?->codigo, [
            PostulacionEstado::Borrador->value,
            PostulacionEstado::Postulada->value,
        ], true)) {
            throw ValidationException::withMessages([
                $clave => 'Tu postulación está retirada. Reactívala para subir documentos.',
            ]);
        }
    }

    /** Alcance por ficha para revisores: fuera de su ficha -> 404 (no revela). */
    private function exigirRevision(Request $request, Postulacion $postulacion): void
    {
        $postulacion->loadMissing(['convocatoria', 'estado']);

        if (! in_array($postulacion->convocatoria->ficha_id, $request->user()->fichaIds(), true)) {
            abort(404, 'Postulación no encontrada.');
        }

        // Un borrador todavía no es un postulante: no entra a revisión.
        if ($postulacion->estado?->codigo === PostulacionEstado::Borrador->value) {
            abort(404, 'Postulación no encontrada.');
        }
    }

    // ── Archivos (PDF + tamaño, disco configurable) ─────────────────────

    /** @return array<int, string> Reglas para un archivo adjunto (PDF <= config). */
    private function reglasArchivo(): array
    {
        $maxKb = (int) config('convocatorias.tamano_maximo_mb') * 1024;

        return ['required', 'file', 'mimes:pdf', 'max:'.$maxKb];
    }

    /**
     * Guarda el archivo en el disco configurado y devuelve sus metadatos.
     *
     * @return array{nombre_original: string, ruta: string, disco: string, mime: string, tamano_bytes: int}
     */
    private function guardarArchivo(UploadedFile $archivo, string $carpeta): array
    {
        $disco = config('filesystems.default');

        return [
            'nombre_original' => $archivo->getClientOriginalName(),
            'ruta' => $archivo->store($carpeta, $disco),
            'disco' => $disco,
            'mime' => $archivo->getMimeType(),
            'tamano_bytes' => $archivo->getSize(),
        ];
    }

    /** Borra el archivo físico; un fallo aquí no rompe la operación de negocio. */
    private function borrarArchivo(?string $disco, ?string $ruta): void
    {
        if ($disco === null || $ruta === null) {
            return;
        }

        try {
            Storage::disk($disco)->delete($ruta);
        } catch (\Throwable) {
            // Ignorado a propósito.
        }
    }
}
