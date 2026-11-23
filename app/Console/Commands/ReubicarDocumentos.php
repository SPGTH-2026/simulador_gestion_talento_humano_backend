<?php

namespace App\Console\Commands;

use App\Models\PostulacionDocumento;
use App\Models\PostulacionSkill;
use App\Support\RutaDocumento;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * Pasa los archivos ya subidos a la estructura nueva:
 *
 *   fichas/{codigo_ficha}/usuarios/{usuario}/postulaciones/{postulacion}/{documentos|adicionales|skills}/{archivo}
 *
 * Seguro por diseño:
 *  - Sin --ejecutar solo MUESTRA el plan (no copia ni cambia nada).
 *  - COPIA el archivo (no lo mueve), verifica que exista y que pese lo mismo, y solo entonces actualiza la ruta en la base.
 *  - NO borra los originales: se borran a mano cuando ya se comprobó que todo está bien.
 *  - Lo que ya está en la estructura nueva se omite, así que se puede volver a correr sin duplicar nada.
 *  - Un archivo que falta en el disco se reporta y se deja como está.
 */
class ReubicarDocumentos extends Command
{
    protected $signature = 'documentos:reubicar
                           {--ejecutar : Aplica los cambios. Sin esta opción solo muestra el plan}';

    protected $description = 'Copia los documentos a la carpeta fichas/{codigo_ficha}/usuarios/{usuario}/postulaciones/{postulacion} y actualiza su ruta.';

    private int $copiados = 0;
    private int $omitidos = 0;
    private int $faltantes = 0;
    private int $errores = 0;

    public function handle(): int
    {
        $ejecutar = (bool) $this->option('ejecutar');

        $this->info($ejecutar ? 'Aplicando cambios.' : 'Modo plan: no se cambia nada. Usa --ejecutar para aplicar.');

        PostulacionDocumento::query()
            ->with('postulacion.convocatoria:id,ficha_id')
            ->orderBy('id')
            ->chunkById(100, function ($lote) use ($ejecutar) {
                foreach ($lote as $documento) {
                    $tipo = $documento->parametro_id === null ? 'adicionales' : 'documentos';
                    $this->reubicar($documento, 'ruta', 'disco', $tipo, $ejecutar);
                }
            });

        PostulacionSkill::query()
            ->whereNotNull('certificado_ruta')
            ->with('postulacion.convocatoria:id,ficha_id')
            ->orderBy('id')
            ->chunkById(100, function ($lote) use ($ejecutar) {
                foreach ($lote as $skill) {
                    $this->reubicar($skill, 'certificado_ruta', 'certificado_disco', 'skills', $ejecutar);
                }
            });

        $this->newLine();
        $this->line("Copiados: {$this->copiados} · Omitidos (ya estaban o sin postulación): {$this->omitidos}");
        $this->line("Archivos que faltan en el disco: {$this->faltantes} · Errores: {$this->errores}");
        if ($ejecutar) {
            $this->comment('Los archivos originales se conservaron. Bórralos después de verificar la aplicación.');
        }

        return $this->errores > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function reubicar(Model $registro, string $campoRuta, string $campoDisco, string $tipo, bool $ejecutar): void
    {
        $ruta = (string) $registro->getAttribute($campoRuta);
        $disco = (string) ($registro->getAttribute($campoDisco) ?: config('filesystems.documentos_disk'));
        $postulacion = $registro->postulacion;

        if (str_starts_with($ruta, 'fichas/') || $postulacion === null) {
            $this->omitidos++;

            return;
        }

        $destino = RutaDocumento::carpeta($postulacion, $tipo).'/'.basename($ruta);

        if (! Storage::disk($disco)->exists($ruta)) {
            $this->faltantes++;
            $this->warn("No existe en el disco: {$ruta} (id {$registro->getKey()}, ".$registro::class.')');

            return;
        }

        if (! $ejecutar) {
            $this->line("{$ruta}  →  {$destino}");
            $this->copiados++;

            return;
        }

        try {
            $this->copiarVerificando($disco, $ruta, $destino);

            $registro->forceFill([$campoRuta => $destino])->save();
            $this->copiados++;
        } catch (\Throwable $e) {
            $this->errores++;
            $this->error("Falló {$ruta} (id {$registro->getKey()}): {$e->getMessage()}");
        }
    }

    private function copiarVerificando(string $disco, string $origen, string $destino): void
    {
        $almacen = Storage::disk($disco);

        if (! $almacen->exists($destino)) {
            if (! $almacen->copy($origen, $destino)) {
                throw new \RuntimeException('la copia no se pudo hacer');
            }
        }

        if (! $almacen->exists($destino) || $almacen->size($destino) !== $almacen->size($origen)) {
            throw new \RuntimeException('la copia no coincide con el original (tamaño distinto o no existe)');
        }
    }
}
