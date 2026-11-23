<?php

namespace App\Services\Correcciones;

use App\Enums\PostulacionEstado;
use App\Models\CorreccionAutorizacion;
use App\Models\Postulacion;
use Illuminate\Support\Collection;

/**
 * Decisión única sobre qué se puede hacer con las correcciones de una postulación (pedir una ronda ordinaria o
 * excepcional, o por qué no), para que todas las pantallas lo expliquen igual.
 */
trait DecideRondas
{
    /**
     * Qué se puede hacer ahora con las correcciones de la postulación. Devuelve el tipo de ronda que se podría pedir
     * (o null) y el motivo, para que todas las pantallas lo expliquen igual.
     *
     * motivo: 'quedan' | 'excepcional' | 'pendiente' | 'vencida' | 'sin_plazo' | 'agotadas' | 'inactiva'
     */
    public function decision(Postulacion $postulacion, ?Collection $rondas = null, ?CorreccionAutorizacion $autorizacion = null): array
    {
        $rondas ??= $this->rondas($postulacion);
        $autorizacion ??= $this->autorizacionLibre($postulacion);
        $postulacion->loadMissing('estado');

        $ordinarias = $rondas->where('tipo', 'ordinaria')->values();
        $usadas = $ordinarias->count();
        $restantes = max(0, self::MAX_ORDINARIAS - $usadas);

        $base = ['ordinarias_usadas' => $usadas, 'ordinarias_max' => self::MAX_ORDINARIAS, 'ordinarias_restantes' => $restantes];

        if ($postulacion->estado?->codigo !== PostulacionEstado::Postulada->value) {
            return $base + ['puede_solicitar' => false, 'tipo' => null, 'motivo' => 'inactiva',
                'mensaje' => 'La postulación ya no está activa, por eso no se puede pedir una corrección.'];
        }

        // Solicitudes antiguas simultáneas de varios evaluadores: quedan en espera de la revisión del administrador.
        if ($rondas->filter(fn ($r) => ! $r->exists)->count() > 1) {
            return $base + ['puede_solicitar' => false, 'tipo' => null, 'motivo' => 'solape',
                'mensaje' => 'Esta postulación tiene solicitudes de corrección antiguas simultáneas de varios evaluadores que el administrador debe revisar antes de convertirlas. Por ahora no se pueden pedir, responder ni autorizar correcciones.'];
        }

        if ($rondas->first(fn ($r) => $r->estado() === 'vigente')) {
            return $base + ['puede_solicitar' => false, 'tipo' => null, 'motivo' => 'pendiente',
                'mensaje' => 'Ya hay una corrección pendiente: espera la respuesta del aspirante antes de pedir otra.'];
        }

        // Una ordinaria sin respuesta (vencida o sin plazo) bloquea las nuevas ordinarias, aunque quede una sin usar.
        $sinRespuesta = $ordinarias->first(fn ($r) => in_array($r->estado(), ['vencida', 'sin_plazo'], true));
        $bloqueo = null;
        if ($sinRespuesta) {
            $bloqueo = $sinRespuesta->estado() === 'sin_plazo' ? 'sin_plazo' : 'vencida';
        } elseif ($restantes === 0) {
            $bloqueo = 'agotadas';
        }

        if ($bloqueo === null) {
            return $base + ['puede_solicitar' => true, 'tipo' => 'ordinaria', 'motivo' => 'quedan',
                'mensaje' => sprintf('Puedes pedir la ronda %d de %d.', $usadas + 1, self::MAX_ORDINARIAS)];
        }

        $mensajes = [
            'vencida' => 'El plazo de una ronda venció sin respuesta: no se pueden pedir más rondas ordinarias. Puedes finalizar con la evidencia disponible o pedir una autorización excepcional al Instructor.',
            'sin_plazo' => 'Una solicitud anterior quedó sin plazo registrado: no se pueden pedir más rondas ordinarias. Puedes finalizar con la evidencia disponible o pedir una autorización excepcional al Instructor.',
            'agotadas' => 'Se agotaron las dos rondas ordinarias. Puedes finalizar con la evidencia disponible o pedir una autorización excepcional al Instructor.',
        ];

        if ($autorizacion) {
            return $base + ['puede_solicitar' => true, 'tipo' => 'excepcional', 'motivo' => 'excepcional', 'bloqueo' => $bloqueo,
                'mensaje' => 'El Instructor autorizó una ronda excepcional: puedes pedirla con los documentos y la observación.'];
        }

        return $base + ['puede_solicitar' => false, 'tipo' => null, 'motivo' => $bloqueo, 'bloqueo' => $bloqueo, 'mensaje' => $mensajes[$bloqueo]];
    }
}
