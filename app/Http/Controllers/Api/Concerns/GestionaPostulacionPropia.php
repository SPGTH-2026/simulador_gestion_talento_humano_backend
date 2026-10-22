<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Enums\PostulacionEstado;
use App\Enums\VentanaPostulacion;
use App\Models\Postulacion;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

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

    /**
     * Alcance por ficha para revisores: fuera de su ficha -> 404 (no revela).
     *
     * @param bool $permitirBorrador Los gestores documentales (permiso
     *                              'documentos:gestionar') sí entran a los
     *                              borradores: un documento de un borrador es
     *                              un documento que hay que poder corregir.
     */
    private function exigirRevision(Request $request, Postulacion $postulacion, bool $permitirBorrador = false): void
    {
        $postulacion->loadMissing(['convocatoria', 'estado']);

        if (! in_array($postulacion->convocatoria->ficha_id, $request->user()->fichaIds(), true)) {
            abort(404, 'Postulación no encontrada.');
        }

        // Un borrador todavía no es un postulante: no entra a revisión.
        if (! $permitirBorrador && $postulacion->estado?->codigo === PostulacionEstado::Borrador->value) {
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
     * Si el disco falla (credenciales, bucket, red) NO se sigue como si nada:
     * un fallo silencioso deja la fila con 'ruta' vacía y el documento se
     * pierde en silencio. Se corta con un 422 en español.
     *
     * @return array{nombre_original: string, ruta: string, disco: string, mime: string, tamano_bytes: int}
     */
    private function guardarArchivo(UploadedFile $archivo, string $carpeta): array
    {
        $disco = (string) config('filesystems.documentos_disk');
        $motivo = null;

        try {
            $ruta = $archivo->store($carpeta, $disco);
        } catch (\Throwable $e) {
            report($e);
            $ruta = false;
            // El motivo real (endpoint, credenciales, bucket) queda en la misma
            // línea del log, para verlo sin buscar la excepción completa.
            $motivo = $e::class.': '.$e->getMessage();
        }

        if ($ruta === false || $ruta === '') {
            Log::error('No se pudo guardar el archivo en el disco de documentos.', [
                'disco' => $disco,
                'carpeta' => $carpeta,
                'motivo' => $motivo ?? 'store() devolvió vacío sin excepción',
            ]);

            throw ValidationException::withMessages([
                'archivo' => 'No se pudo guardar el archivo. Intenta de nuevo en unos minutos.',
            ]);
        }

        return [
            'nombre_original' => $archivo->getClientOriginalName(),
            'ruta' => $ruta,
            'disco' => $disco,
            'mime' => $archivo->getMimeType(),
            'tamano_bytes' => $archivo->getSize(),
        ];
    }

    /**
     * Entrega un archivo del disco al navegador.
     *
     * Se lee EN MEMORIA y se devuelve como respuesta normal, no como
     * StreamedResponse, a propósito: si el disco falla (credenciales de R2,
     * bucket, red) el error ocurre ANTES de enviar cabeceras y se puede
     * convertir en un 503 limpio. Con un stream el fallo ocurre a mitad de la
     * respuesta y el usuario se queda con un PDF truncado y un 200.
     */
    private function leerArchivo(
        ?string $disco,
        ?string $ruta,
        ?string $nombre,
        ?string $mime = null,
        bool $inline = false,
        string $mensajeNoEncontrado = 'Documento no encontrado.',
    ): Response {
        $discoReal = $this->discoDeDocumentos($disco);

        if ($ruta === null || $ruta === '') {
            abort(404, $mensajeNoEncontrado);
        }

        try {
            $existe = Storage::disk($discoReal)->exists($ruta);
        } catch (\Throwable $e) {
            report($e);
            Log::error('El disco de documentos no respondió al verificar el archivo.', [
                'disco' => $discoReal,
                'ruta' => $ruta,
            ]);

            abort(503, 'No se pudo leer el documento almacenado. Intenta de nuevo en unos minutos.');
        }

        if (! $existe) {
            abort(404, $mensajeNoEncontrado);
        }

        try {
            $contenido = Storage::disk($discoReal)->get($ruta);
        } catch (\Throwable $e) {
            report($e);
            Log::error('El disco de documentos falló al leer el archivo.', [
                'disco' => $discoReal,
                'ruta' => $ruta,
            ]);

            abort(503, 'No se pudo leer el documento almacenado. Intenta de nuevo en unos minutos.');
        }

        if ($contenido === null) {
            abort(404, $mensajeNoEncontrado);
        }

        $nombre = $this->nombreDeArchivo($nombre);

        return response($contenido, 200, [
            'Content-Type' => $mime ?: 'application/pdf',
            'Content-Length' => (string) strlen($contenido),
            'Content-Disposition' => HeaderUtils::makeDisposition(
                $inline ? HeaderUtils::DISPOSITION_INLINE : HeaderUtils::DISPOSITION_ATTACHMENT,
                $nombre,
                Str::ascii($nombre),
            ),
        ]);
    }

    /**
     * Disco real de un archivo guardado. Si la fila apunta a un disco que no
     * está configurado en este servidor (por ejemplo, una fila vieja con
     * 's3' cuando el despliegue usa R2), se cae al disco de documentos por
     * defecto y se deja rastro en el log.
     */
    private function discoDeDocumentos(?string $disco): string
    {
        $porDefecto = (string) config('filesystems.documentos_disk');
        $configurados = array_keys((array) config('filesystems.disks', []));

        if ($disco !== null && $disco !== '' && in_array($disco, $configurados, true)) {
            return $disco;
        }

        if ($disco !== null && $disco !== '') {
            Log::warning('El disco guardado en el archivo no está configurado; se usa el de documentos.', [
                'disco_guardado' => $disco,
                'disco_usado' => $porDefecto,
            ]);
        }

        return $porDefecto;
    }

    /** Nombre de archivo limpio para la cabecera Content-Disposition. */
    private function nombreDeArchivo(?string $nombre): string
    {
        $limpio = basename((string) $nombre);
        $limpio = (string) preg_replace('/[\x00-\x1F\x7F"\\\\]/u', '', $limpio);
        $limpio = trim($limpio);

        return $limpio !== '' ? $limpio : 'documento.pdf';
    }

    /** Borra el archivo físico; un fallo aquí no rompe la operación de negocio. */
    private function borrarArchivo(?string $disco, ?string $ruta): void
    {
        if ($disco === null || $ruta === null) {
            return;
        }

        try {
            Storage::disk($this->discoDeDocumentos($disco))->delete($ruta);
        } catch (\Throwable) {
            // Ignorado a propósito.
        }
    }
}