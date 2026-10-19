<?php

namespace App\Console\Commands;

use App\Enums\ConvocatoriaEstado;
use App\Models\Convocatoria;
use App\Models\Parametro;
use Illuminate\Console\Command;

/**
 * Cierra la recepción de las convocatorias publicadas cuya fecha de cierre ya
 * pasó (en America/Bogota).
 *
 * publicada -> en_proceso es la transición "Cerrar recepción" definida en
 * ConvocatoriaEstado::transiciones(): desde ese momento ya no llegan
 * postulaciones y la convocatoria deja de mostrarse al aspirante (el listado
 * del aspirante solo expone las publicadas). El gestor la ve como en_proceso y
 * luego la cierra manualmente cuando termina la evaluación.
 */
class CerrarConvocatoriasVencidas extends Command
{
    protected $signature = 'convocatorias:cerrar-vencidas
                           {--dry-run : Muestra lo que haría sin guardar cambios}';

    protected $description = 'Pasa a en_proceso las convocatorias publicadas cuya fecha de cierre ya pasó.';

    public function handle(): int
    {
        // "Hoy" en la zona del aspirante; fecha_cierre == hoy sigue ABIERTA
        // (los extremos son inclusivos, igual que Convocatoria::ventanaPostulacion).
        $hoy = now('America/Bogota')->toDateString();

        // El estado se resuelve SIEMPRE por código (los ids cambian entre local y Render).
        $publicadaId = Parametro::porTemaYCodigo('estado_convocatoria', ConvocatoriaEstado::Publicada->value)?->id;
        $enProcesoId = Parametro::porTemaYCodigo('estado_convocatoria', ConvocatoriaEstado::EnProceso->value)?->id;

        if ($publicadaId === null || $enProcesoId === null) {
            $this->error('Falta el catálogo estado_convocatoria (¿se ejecutó ParametroSeeder?).');

            return self::FAILURE;
        }

        $vencidas = Convocatoria::query()
            ->where('estado_id', $publicadaId)
            ->whereNotNull('fecha_cierre')
            ->whereDate('fecha_cierre', '<', $hoy)
            ->orderBy('id')
            ->get();

        if ($vencidas->isEmpty()) {
            $this->info('No hay convocatorias publicadas con la fecha de cierre vencida.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');

        foreach ($vencidas as $convocatoria) {
            $this->line(sprintf(
                '%s %s | %s | cierre %s -> en_proceso',
                $dryRun ? '[dry-run]' : '-',
                $convocatoria->codigo,
                $convocatoria->nombre,
                $convocatoria->fecha_cierre?->toDateString(),
            ));

            if (! $dryRun) {
                $convocatoria->forceFill(['estado_id' => $enProcesoId])->save();
            }
        }

        $this->info(sprintf(
            '%d convocatoria(s) %s.',
            $vencidas->count(),
            $dryRun ? 'se cerrarían' : 'pasaron a en_proceso',
        ));

        return self::SUCCESS;
    }
}
