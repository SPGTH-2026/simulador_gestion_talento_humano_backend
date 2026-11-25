<?php

namespace App\Services\Citaciones;

use App\Models\SeleccionCitacion;

/** Forma en que la API entrega una cita. La hora se da en Colombia (y también el instante en UTC) para que nadie la recalcule. */
final class PresentaCitaciones
{
    public const ETIQUETAS = ['entrevista' => 'Entrevista', 'prueba_tecnica' => 'Prueba técnica'];

    public static function presentar(SeleccionCitacion $cita, bool $conHistorial = false): array
    {
        $local = $cita->fecha_hora->copy()->setTimezone('America/Bogota');
        $datos = [
            'id' => $cita->id,
            'postulacion_id' => $cita->postulacion_id,
            'actividad' => $cita->actividad,
            'actividad_etiqueta' => self::ETIQUETAS[$cita->actividad] ?? $cita->actividad,
            'estado' => $cita->estado,
            'fecha_hora' => $cita->fecha_hora->copy()->utc()->toIso8601String(),
            'fecha' => $local->format('Y-m-d'),
            'hora' => $local->format('H:i'),
            'zona_horaria' => 'America/Bogota',
            'lugar_o_enlace' => $cita->lugar_o_enlace,
            'es_enlace' => $cita->esEnlace(),
            'indicaciones' => $cita->indicaciones,
            'motivo_cancelacion' => $cita->motivo_cancelacion,
        ];

        if ($conHistorial) {
            $datos['programada_por'] = $cita->creadora?->name;
            $datos['historial'] = $cita->eventos->map(fn ($e) => [
                'tipo' => $e->tipo,
                'por' => $e->usuario?->name,
                'en' => $e->created_at?->toIso8601String(),
                'detalle' => $e->detalle,
            ])->values()->all();
        }

        return $datos;
    }
}
