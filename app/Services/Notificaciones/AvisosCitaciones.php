<?php

namespace App\Services\Notificaciones;

use App\Enums\TipoNotificacion;
use App\Models\SeleccionCitacion;

/**
 * Avisos al aspirante por sus citas de Selección. Usan las notificaciones existentes (la campana): no envían correos. Corren dentro
 * de la transacción de quien los llama. El enlace lleva a «Mis postulaciones» con la cita concreta (?cita=ID): ahí el aspirante la consulta; una cita de otra persona no se le muestra.
 */
final class AvisosCitaciones
{
    private const ACTIVIDADES = ['entrevista' => 'la entrevista', 'prueba_tecnica' => 'la prueba técnica'];

    public function __construct(private Notificador $notificador) {}

    public function programada(SeleccionCitacion $cita): void
    {
        $this->enviar($cita, 'Cita programada', "Tienes {$this->actividad($cita)} de {$this->convocatoria($cita)}: {$this->cuando($cita)}.");
    }

    public function reprogramada(SeleccionCitacion $cita): void
    {
        $this->enviar($cita, 'Cita reprogramada', "Cambió {$this->actividad($cita)} de {$this->convocatoria($cita)}: ahora es {$this->cuando($cita)}.");
    }

    public function cancelada(SeleccionCitacion $cita): void
    {
        $this->enviar($cita, 'Cita cancelada', "Se canceló {$this->actividad($cita)} de {$this->convocatoria($cita)}. Motivo: {$cita->motivo_cancelacion}");
    }

    private function enviar(SeleccionCitacion $cita, string $titulo, string $mensaje): void
    {
        $cita->loadMissing('postulacion.user', 'postulacion.convocatoria:id,nombre');
        $this->notificador->enviar($cita->postulacion->user, TipoNotificacion::Citacion, $titulo, $mensaje, '/mis-postulaciones?cita='.$cita->id);
    }

    private function actividad(SeleccionCitacion $cita): string
    {
        return self::ACTIVIDADES[$cita->actividad] ?? 'la actividad';
    }

    private function convocatoria(SeleccionCitacion $cita): string
    {
        $cita->loadMissing('postulacion.convocatoria:id,nombre');

        return (string) $cita->postulacion->convocatoria->nombre;
    }

    /** «01/12/2026 a las 14:30 (hora de Colombia)». */
    private function cuando(SeleccionCitacion $cita): string
    {
        $local = $cita->fecha_hora->copy()->setTimezone('America/Bogota');

        return $local->format('d/m/Y').' a las '.$local->format('H:i').' (hora de Colombia)';
    }
}
