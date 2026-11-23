<?php

namespace App\Console\Commands;

use App\Models\CorreccionPostulacion;
use App\Models\Evaluacion;
use App\Models\Postulacion;
use App\Services\RondasCorreccion;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Convierte en rondas de corrección las solicitudes antiguas (evaluaciones en «corrección solicitada» sin ronda).
 *
 * Sin opciones es un DIAGNÓSTICO de solo lectura. `--aplicar` convierte de forma explícita e idempotente (la evaluación
 * de origen es única: ejecutarlo dos veces no duplica nada). Las postulaciones con solicitudes simultáneas de varios
 * evaluadores NO se agrupan ni se convierten salvo `--incluir-solapes`, que las convierte como rondas SEPARADAS
 * conservando todos los documentos marcados, observaciones y responsables.
 *
 * Mientras haya solicitudes sin convertir, la API ya las lee como rondas de solo lectura con las mismas restricciones.
 */
class MigrarCorreccionesLegado extends Command
{
    protected $signature = 'correcciones:migrar-legado
        {--aplicar : Convierte las solicitudes antiguas (por defecto solo diagnostica)}
        {--incluir-solapes : Convierte también las postulaciones con solicitudes de varios evaluadores, como rondas separadas}';

    protected $description = 'Diagnostica y convierte las solicitudes de corrección anteriores a las rondas';

    public function handle(RondasCorreccion $servicio): int
    {
        $aplicar = (bool) $this->option('aplicar');
        $incluirSolapes = (bool) $this->option('incluir-solapes');

        $postulaciones = Postulacion::query()
            ->whereHas('evaluaciones', fn ($q) => $q->where('estado', 'correccion_solicitada'))
            ->orderBy('id')->get();

        $filas = [];
        $solapes = [];
        $pendientes = [];
        $convertidas = 0;
        $conteo = ['respondida' => 0, 'sin_plazo' => 0, 'vencida' => 0, 'vigente' => 0];

        foreach ($postulaciones as $postulacion) {
            $legadas = $servicio->rondas($postulacion)->filter(fn (CorreccionPostulacion $r) => ! $r->exists)->values();

            if ($legadas->isEmpty()) {
                continue;
            }

            $solapa = $legadas->count() > 1;
            if ($solapa) {
                $solapes[$postulacion->id] = $legadas->map(fn ($r) => 'evaluación '.$r->evaluacion_id.' (evaluador '.$r->solicitada_por.')')->all();
            }

            foreach ($legadas as $r) {
                $conteo[$r->estado()]++;
                $filas[] = [
                    $postulacion->id,
                    $r->evaluacion_id,
                    $r->solicitada_por,
                    $this->etiqueta($r->estado()),
                    $r->fecha_limite?->toIso8601String() ?? '—',
                    implode(',', $r->documentos_marcados ?? []) ?: '—',
                    $solapa ? 'SOLAPE' : '',
                ];
            }

            if (! $aplicar) {
                continue;
            }

            if ($solapa && ! $incluirSolapes) {
                $pendientes[] = $postulacion->id;

                continue;
            }

            $convertidas += DB::transaction(function () use ($servicio, $postulacion, $incluirSolapes) {
                $servicio->bloquear($postulacion);

                return $servicio->convertirLegadas($postulacion, null, ! $incluirSolapes)->count();
            });
        }

        $yaConvertidas = CorreccionPostulacion::query()->where('origen', 'legado')->count();

        $this->line($aplicar ? 'APLICACIÓN' : 'DIAGNÓSTICO DE SOLO LECTURA (no se modificó nada)');
        $this->newLine();

        if ($filas !== []) {
            $this->table(['Postulación', 'Evaluación', 'Evaluador', 'Estado', 'Plazo (UTC)', 'Documentos marcados', 'Nota'], $filas);
        } else {
            $this->info('No hay solicitudes antiguas sin convertir.');
        }

        $this->newLine();
        $this->line(sprintf(
            'Sin convertir: %d · Vigente: %d · Respondida: %d · Vencida: %d · Sin plazo registrado: %d',
            count($filas), $conteo['vigente'], $conteo['respondida'], $conteo['vencida'], $conteo['sin_plazo'],
        ));

        if ($solapes !== []) {
            $this->warn('Postulaciones con solicitudes simultáneas de distintos evaluadores (no se agrupan):');
            foreach ($solapes as $id => $items) {
                $this->line('  - postulación '.$id.': '.implode('; ', $items));
            }
        }

        // Evaluaciones que ya no son «corrección solicitada» pero cuyo perfil tiene documentos marcados «No»: pudieron
        // pasar por una corrección cuyo historial se borró al finalizar. No se reconstruyen ni consumen ronda.
        $sinAntecedentes = Evaluacion::query()
            ->where('estado', '!=', 'correccion_solicitada')
            ->whereNotNull('perfil')->get()
            ->filter(fn (Evaluacion $e) => collect($e->perfil)->contains(fn ($v, $k) => $v === false && str_starts_with((string) $k, 'doc-')))
            ->filter(fn (Evaluacion $e) => ! CorreccionPostulacion::query()->where('postulacion_id', $e->postulacion_id)->exists());
        $this->line('Antecedentes no disponibles (posible corrección anterior sin historial; no consumen ronda): '.$sinAntecedentes->count()
            .($sinAntecedentes->isNotEmpty() ? ' · evaluaciones '.$sinAntecedentes->pluck('id')->implode(',') : ''));

        if ($aplicar) {
            $this->newLine();
            $this->info("Convertidas ahora: {$convertidas} · Ya convertidas antes (origen legado): ".($yaConvertidas - $convertidas));
            if ($pendientes !== []) {
                $this->warn('Pendientes por solape (usa --incluir-solapes tras revisarlos): postulaciones '.implode(',', $pendientes));
            }
        } else {
            $this->newLine();
            $this->line('Para convertir: php artisan correcciones:migrar-legado --aplicar');
        }

        return self::SUCCESS;
    }

    private function etiqueta(string $estado): string
    {
        return match ($estado) {
            'sin_plazo' => 'Sin plazo registrado',
            'vencida' => 'Vencida',
            'respondida' => 'Respondida',
            default => 'Vigente',
        };
    }
}
