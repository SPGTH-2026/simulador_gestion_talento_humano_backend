<?php

namespace App\Services\Almacenamiento;

use App\Models\Ficha;
use App\Support\RutaDocumento;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Cuánto ocupa lo que guarda el sistema:
 *
 *  - La base de datos (Supabase/Postgres en producción, o MySQL en local), con la misma conexión que usa la aplicación.
 *  - Los documentos de cada ficha: los archivos bajo fichas/{código}/ en el disco de documentos (R2 en producción).
 *
 * Listar el bucket cuesta una petición por cada mil objetos, así que el resultado se guarda en caché unos minutos. Un
 * fallo al consultar el disco no se guarda: la siguiente vez se vuelve a intentar.
 */
final class UsoAlmacenamiento
{
    private const SEGUNDOS_EN_CACHE = 300;

    /**
     * Tamaño de la base de datos según su motor. Si el motor no permite medirla, `bytes` va nulo en vez de romper la
     * pantalla.
     *
     * @return array{motor: string, bytes: ?int}
     */
    public function base(): array
    {
        return Cache::remember('almacenamiento.base', self::SEGUNDOS_EN_CACHE, function (): array {
            $motor = DB::connection()->getDriverName();

            try {
                $bytes = match ($motor) {
                    'pgsql' => (int) DB::selectOne('select pg_database_size(current_database()) as bytes')->bytes,
                    'mysql' => (int) DB::selectOne(
                        'select coalesce(sum(data_length + index_length), 0) as bytes from information_schema.tables where table_schema = database()',
                    )->bytes,
                    default => null,
                };
            } catch (Throwable $e) {
                Log::warning('No se pudo medir el tamaño de la base de datos.', ['motor' => $motor, 'error' => $e->getMessage()]);
                $bytes = null;
            }

            return ['motor' => $motor, 'bytes' => $bytes];
        });
    }

    /**
     * Archivos y bytes de la carpeta de una ficha en el disco de documentos. Si el disco no responde, devuelve nulos y
     * no lo guarda en caché.
     *
     * @return array{bytes: ?int, objetos: ?int}
     */
    public function documentosDeFicha(Ficha $ficha): array
    {
        $clave = $this->claveFicha($ficha);

        if (Cache::has($clave)) {
            return Cache::get($clave);
        }

        try {
            $prefijo = 'fichas/'.RutaDocumento::segmentoFicha($ficha).'/';
            $disco = Storage::disk((string) config('filesystems.documentos_disk'))->getDriver();

            $bytes = 0;
            $objetos = 0;
            foreach ($disco->listContents($prefijo, true) as $item) {
                if ($item->isFile()) {
                    $bytes += (int) ($item->fileSize() ?? 0);
                    $objetos++;
                }
            }
        } catch (Throwable $e) {
            Log::warning('No se pudo medir los documentos de una ficha.', ['ficha_id' => $ficha->id, 'error' => $e->getMessage()]);

            return ['bytes' => null, 'objetos' => null];
        }

        $resultado = ['bytes' => $bytes, 'objetos' => $objetos];
        Cache::put($clave, $resultado, self::SEGUNDOS_EN_CACHE);

        return $resultado;
    }

    /**
     * Bytes de TODOS los archivos del disco de documentos (no solo de una ficha). Nulo si el disco no responde.
     */
    public function totalDocumentos(): ?int
    {
        $clave = 'almacenamiento.documentos.total';

        if (Cache::has($clave)) {
            return Cache::get($clave);
        }

        try {
            $disco = Storage::disk((string) config('filesystems.documentos_disk'))->getDriver();

            $bytes = 0;
            foreach ($disco->listContents('', true) as $item) {
                if ($item->isFile()) {
                    $bytes += (int) ($item->fileSize() ?? 0);
                }
            }
        } catch (Throwable $e) {
            Log::warning('No se pudo medir el total de documentos.', ['error' => $e->getMessage()]);

            return null;
        }

        Cache::put($clave, $bytes, self::SEGUNDOS_EN_CACHE);

        return $bytes;
    }

    /**
     * Uso total del sistema frente a su capacidad. El porcentaje total suma la base de datos y los documentos contra la
     * capacidad de ambos. El detalle de la base (`base`) solo se incluye si `$conDetalle` lo pide: es del super admin.
     *
     * @return array{porcentaje: ?float, usado_bytes: ?int, limite_bytes: int, documentos: array, base?: array}
     */
    public function resumenUso(bool $conDetalle): array
    {
        $base = $this->base();
        $documentos = $this->totalDocumentos();

        $limiteBase = $this->bytesDeLimite('limite_base_mb');
        $limiteDocumentos = $this->bytesDeLimite('limite_documentos_mb');

        $usadoTotal = $base['bytes'] !== null && $documentos !== null ? $base['bytes'] + $documentos : null;

        $uso = [
            'porcentaje' => $this->porcentaje($usadoTotal, $limiteBase + $limiteDocumentos),
            'usado_bytes' => $usadoTotal,
            'limite_bytes' => $limiteBase + $limiteDocumentos,
            'documentos' => [
                'usado_bytes' => $documentos,
                'limite_bytes' => $limiteDocumentos,
                'porcentaje' => $this->porcentaje($documentos, $limiteDocumentos),
            ],
        ];

        if ($conDetalle) {
            $uso['base'] = [
                'usado_bytes' => $base['bytes'],
                'limite_bytes' => $limiteBase,
                'porcentaje' => $this->porcentaje($base['bytes'], $limiteBase),
            ];
        }

        return $uso;
    }

    /** Porcentaje con un decimal, o nulo si no se pudo medir o la capacidad no es válida. */
    private function porcentaje(?int $usado, int $limite): ?float
    {
        if ($usado === null || $limite <= 0) {
            return null;
        }

        return round($usado / $limite * 100, 1);
    }

    /** Capacidad configurada en MB, convertida a bytes. */
    private function bytesDeLimite(string $clave): int
    {
        return (int) round((float) config("almacenamiento.{$clave}") * 1048576);
    }

    /** Olvida la medida de una ficha (se llama después de borrar sus documentos). */
    public function olvidarFicha(int $fichaId): void
    {
        Cache::forget('almacenamiento.ficha.'.$fichaId);
        // El total del sistema también cambia al borrar: se vuelve a medir la próxima vez.
        Cache::forget('almacenamiento.documentos.total');
    }

    private function claveFicha(Ficha $ficha): string
    {
        return 'almacenamiento.ficha.'.$ficha->id;
    }
}
