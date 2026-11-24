<?php

namespace App\Services\Notificaciones;

use App\Enums\TipoNotificacion;
use App\Models\Notificacion;
use App\Models\User;

/**
 * Crea los avisos que se muestran en la campana. Quien dispara el flujo (una corrección, una evaluación) llama aquí dentro
 * de su propia transacción: si el flujo se deshace, el aviso también.
 */
final class Notificador
{
    public function enviar(
        User $destinatario,
        TipoNotificacion $tipo,
        string $titulo,
        string $mensaje,
        ?string $url = null,
    ): Notificacion {
        return Notificacion::create([
            'user_id' => $destinatario->id,
            'tipo' => $tipo,
            'titulo' => $titulo,
            'mensaje' => $mensaje,
            'url' => $url,
        ]);
    }
}
