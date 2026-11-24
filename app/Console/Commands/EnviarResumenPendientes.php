<?php

namespace App\Console\Commands;

use App\Services\Notificaciones\ResumenPendientes;
use Illuminate\Console\Command;

/**
 * Envía a cada persona el resumen de lo que tiene pendiente (evaluar, seleccionar, cerrar, borradores, correcciones por
 * vencer, cierre próximo). Lo dispara el scheduler cada mañana, o el disparador externo en hosting sin cron.
 */
class EnviarResumenPendientes extends Command
{
    protected $signature = 'notificaciones:resumen-diario';

    protected $description = 'Avisa a cada persona de lo que tiene pendiente, solo si hay algo.';

    public function handle(ResumenPendientes $resumen): int
    {
        $creados = $resumen->ejecutar();

        $this->info("{$creados} aviso(s) de pendientes enviados.");

        return self::SUCCESS;
    }
}
