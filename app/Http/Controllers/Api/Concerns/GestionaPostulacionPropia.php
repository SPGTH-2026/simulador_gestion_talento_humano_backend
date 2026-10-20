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
     * Determina si la postulación es editable según las reglas de negocio:
     * - Borrador: solo dentro de la ventana de recepción (apertura..cierre).
     * - Postulada: convocatoria publicada, después de apertura, y antes de fecha_evaluacion (si existe).
     * - Retirada: nunca editable.
     * - Convocatoria no publicada: no editable (404 en exigirEditable).
     */
    private function puedeEditar(Postulacion $postulacion): bool
    {
        $postulacion->loadMissing(['convocatoria.estado', 'estado']);
        $convocatoria = $postulacion->convocatoria;
        $hoy = now('America/Bogota')->toDateString();

        if ($convocatoria->estado?->codigo !== 'publicada') {
            return false;
        }

        if ($convocatoria->fecha_apertura && $hoy < $convocatoria->fecha_apertura->toDateString()) {
            return false;
        }

        $codigo = $postulacion->estado?->codigo;

        if ($codigo === PostulacionEstado::Borrador->value) {
            return $convocatoria->ventanaPostulacion() === VentanaPostulacion::Abierta;
        }

        if ($codigo !== PostulacionEstado::Postulada->value) {
            return false;
        }

        return $convocatoria->fecha_evaluacion === null
            || $hoy < $convocatoria->fecha_evaluacion->toDateString();
    }

    /**
     * Devuelve el motivo legible de por qué no es editable (string vacío si sí lo es).
     */
    private function motivoNoEditable(Postulacion $postulacion): string
    {
        $postulacion->loadMissing(['convocatoria.estado', 'estado']);
        $convocatoria = $postulacion->convocatoria;
        $hoy = now('America/Bogota')->toDateString();

        if ($convocatoria->estado?->codigo !== 'publicada') {
            return 'La convocatoria no está publicada.';
        }

        if ($convocatoria->fecha_apertura && $hoy < $convocatoria->fecha_apertura->toDateString()) {
            return 'La convocatoria aún no está abierta. Abre el '
                .$convocatoria->fecha_apertura?->toDateString().'.';
        }

        $codigo = $postulacion->estado?->codigo;

        if ($codigo === PostulacionEstado::Retirada->value) {
            return 'Tu postulación está retirada. Reactívala para editar documentos.';
        }

        if ($codigo === PostulacionEstado::Borrador->value) {
            if ($convocatoria->ventanaPostulacion() !== VentanaPostulacion::Abierta) {
                return 'La convocatoria ya cerró el '
                    .$convocatoria->fecha_cierre?->toDateString()
                    .'. Los borradores no se editan fuera de la ventana.';
            }
            return '';
        }

        if ($codigo !== PostulacionEstado::Postulada->value) {
            return 'Esta postulación no está en estado editable.';
        }

        if ($convocatoria->fecha_evaluacion && $hoy >= $convocatoria->fecha_evaluacion->toDateString()) {
            return 'La evaluación ya comenzó el '
                .$convocatoria->fecha_evaluacion->toDateString()
                .'. Los documentos quedaron solo para consulta.';
        }

        return '';
    }

    /**
     * Exige que la postulación sea editable; lanza ValidationException con el motivo
     * o abort(404) si la convocatoria no está publicada.
     */
    private function exigirEditable(Postulacion $postulacion, string $clave): void
    {
        $motivo = $this->motivoNoEditable($postulacion);

        if ($motivo === '') {
            return;
        }

        $postulacion->loadMissing(['convocatoria.estado']);

        if ($postulacion->convocatoria->estado?->codigo !== 'publicada') {
            abort(404, 'Postulación no encontrada.');
        }

        throw ValidationException::withMessages([
            $clave => $motivo,
        ]);
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
     * Guarda el archivo en el disco configurado (documentos_disk) y devuelve sus metadatos.
     *
     * @return array{nombre_original: string, ruta: string, disco: string, mime: string, tamano_bytes: int}
     */
    private function guardarArchivo(UploadedFile $archivo, string $carpeta): array
    {
        $disco = config('filesystems.documentos_disk');

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