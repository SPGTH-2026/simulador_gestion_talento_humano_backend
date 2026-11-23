<?php

namespace App\Http\Controllers\Api\Concerns;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Archivos de los documentos y skills de una postulación: validación del PDF, guardado en el disco configurado,
 * lectura en línea o descarga, y borrado. Lo usan los controladores de documentos y de skills.
 */
trait GestionaArchivosDocumentos
{

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

        try {
            $ruta = $archivo->store($carpeta, $disco);
        } catch (\Throwable $e) {
            report($e);
            $ruta = false;
        }

        if ($ruta === false || $ruta === '') {
            Log::error('No se pudo guardar el archivo en el disco de documentos.', [
                'disco' => $disco,
                'carpeta' => $carpeta,
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
            // Un archivo subido nunca debe interpretarse como HTML o script aunque el navegador intente adivinar el tipo.
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
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
