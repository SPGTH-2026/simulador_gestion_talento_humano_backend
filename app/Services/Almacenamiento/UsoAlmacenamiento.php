<?php

namespace App\Services\Almacenamiento;

use App\Models\Ficha;
use App\Models\PostulacionDocumento;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Cuánto ocupa lo que guarda el sistema:
 *
 *  - La base de datos (Supabase/Postgres en producción, o MySQL en local), con la misma conexión que usa la aplicación.
 *  - Los documentos: el tamaño que se guardó en la base al subir cada archivo. Así se cuentan también los documentos
 *    antiguos, aunque estén en una carpeta que no sigue la organización por ficha, y no depende de que el bucket responda.
 *
 * La medida de la base se guarda en caché unos minutos; la de los documentos es una suma de la base y se calcula al momento.
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

    /** Documentos y bytes registrados de una ficha, con los documentos antiguos incluidos. */
    public function documentosDeFicha(Ficha $ficha): array
    {
        $fila = PostulacionDocumento::query()
            ->whereHas('postulacion.convocatoria', fn ($q) => $q->where('ficha_id', $ficha->id))
            ->selectRaw('count(*) as objetos, coalesce(sum(tamano_bytes), 0) as bytes')
            ->first();

        return ['bytes' => (int) $fila->bytes, 'objetos' => (int) $fila->objetos];
    }

    /** Bytes de todos los documentos registrados en el sistema. */
    public function totalDocumentos(): int
    {
        return (int) PostulacionDocumento::query()->sum('tamano_bytes');
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

        $usadoTotal = $base['bytes'] !== null ? $base['bytes'] + $documentos : null;

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
}
