<?php

namespace App\Console\Commands;

use App\Models\Notificacion;
use Illuminate\Console\Command;

/**
 * Elimina las notificaciones que cumplieron su plazo de retención (config/notificaciones.php): las ocultas y las leídas, a los 30 días
 * por defecto. Nunca toca las no leídas visibles. Borra por lotes para no bloquear la tabla. DESACTIVADO por defecto (config/notificaciones.php): sin activarlo no borra nada. Lo dispara el scheduler cada madrugada, o el
 * disparador externo en hosting sin cron.
 */
class DepurarNotificaciones extends Command
{
    protected $signature = 'notificaciones:depurar';

    protected $description = 'Elimina las notificaciones ocultas o leídas que superaron su plazo de retención.';

    public function handle(): int
    {
        if (! config('notificaciones.depuracion_activa')) {
            $this->warn('La depuración de notificaciones está desactivada (NOTIFICACIONES_DEPURACION_ACTIVA): no se eliminó nada.');

            return self::SUCCESS;
        }

        $ocultas = $this->borrar(
            Notificacion::query()->whereNotNull('ocultada_at')->where('ocultada_at', '<=', now()->subDays(max(1, (int) config('notificaciones.dias_ocultas')))),
        );
        $leidas = $this->borrar(
            Notificacion::query()->whereNull('ocultada_at')->whereNotNull('leida_at')->where('leida_at', '<=', now()->subDays(max(1, (int) config('notificaciones.dias_leidas')))),
        );

        $this->info("{$ocultas} oculta(s) y {$leidas} leída(s) eliminadas.");

        return self::SUCCESS;
    }

    /** @param  \Illuminate\Database\Eloquent\Builder<Notificacion>  $consulta */
    private function borrar($consulta): int
    {
        $total = 0;
        while (($ids = (clone $consulta)->limit(1000)->pluck('id'))->isNotEmpty()) {
            $total += Notificacion::query()->whereIn('id', $ids)->delete();
        }

        return $total;
    }
}
