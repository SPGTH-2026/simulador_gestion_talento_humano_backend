<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Models\SeleccionCalificacion;
use App\Models\SeleccionConfiguracion;

/**
 * Regla única del resultado adicional de Selección: promedio ponderado de las
 * actividades activas, en centésimas enteras (sin flotantes), con el redondeo a
 * dos decimales de la mitad hacia arriba. Lo comparten el detalle de
 * calificaciones y el ranking para que ambos den siempre el mismo valor.
 */
trait ResultadoAdicional
{
    /** "8.5" o "10" a centésimas enteras (850, 1000). Solo recibe textos ya validados. */
    protected function centesimas(string $nota): int
    {
        [$entera, $decimales] = array_pad(explode('.', $nota, 2), 2, '0');

        return ((int) $entera * 100) + (int) str_pad($decimales, 2, '0');
    }

    protected function formatear(int $centesimas): string
    {
        return sprintf('%d.%02d', intdiv($centesimas, 100), $centesimas % 100);
    }

    /**
     * Resultado adicional en centésimas, o null si no hay actividades activas o
     * falta alguna nota de las activas. Las notas de actividades desactivadas no cuentan.
     *
     * @param  array<string, int>  $notas  centésimas por actividad registrada
     */
    protected function resultadoPonderado(SeleccionConfiguracion $configuracion, array $notas): ?int
    {
        $suma = 0;
        $activas = 0;

        foreach (SeleccionCalificacion::ACTIVIDADES as $actividad) {
            if (! $configuracion->{$actividad.'_activa'}) {
                continue;
            }
            $activas++;
            if (! array_key_exists($actividad, $notas)) {
                return null;
            }
            $suma += $notas[$actividad] * $configuracion->{'peso_'.$actividad};
        }

        return $activas === 0 ? null : intdiv($suma + 50, 100);
    }
}
