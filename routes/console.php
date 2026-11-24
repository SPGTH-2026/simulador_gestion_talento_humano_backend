<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Cierra la recepción de las convocatorias vencidas (publicada -> en_proceso).
// Corre a diario a las 00:05 en hora de Colombia para que el aspirante deje de
// verlas apenas pasa la fecha de cierre. En producción lo dispara el cron del
// scheduler (`php artisan schedule:run`); en local, `php artisan schedule:work`.
Schedule::command('convocatorias:cerrar-vencidas')
    ->dailyAt('00:05')
    ->timezone('America/Bogota')
    ->withoutOverlapping();

// Resumen de pendientes (evaluar, seleccionar, cerrar; borradores, correcciones por vencer, cierres próximos). Corre
// después del cierre automático, para que los avisos reflejen las convocatorias ya cerradas.
Schedule::command('notificaciones:resumen-diario')
    ->dailyAt('07:00')
    ->timezone('America/Bogota')
    ->withoutOverlapping();
