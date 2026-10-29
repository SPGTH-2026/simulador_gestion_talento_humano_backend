<?php

namespace App\Enums;

// Estados de una postulación (tema estado_postulacion). La lógica SIEMPRE
// consulta por codigo, nunca por id ni por nombre: los ids cambian entre
// local y Render; el codigo es estable.
enum PostulacionEstado: string
{
    // La postulación nace en borrador: el aspirante completa sexo, documentos y
    // skills ANTES de enviarla. Solo enviar() la pasa a postulada.
    case Borrador = 'borrador';
    case Postulada = 'postulada';
    case Retirada = 'retirada';

    /** @return string[] */
    public static function codigos(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Transiciones permitidas para el cambio MANUAL de estado (PATCH /estado):
     *
     *  - borrador  -> retirada (descartar) y postulada (solo vía enviar()).
     *  - postulada -> retirada (retirar).
     *  - retirada  -> borrador  (reabrir) y postulada (volver a postularme;
     *    esta última solo con la ventana abierta y los obligatorios completos).
     *
     * Descartar/retirar solo exige que la convocatoria siga publicada; reabrir
     * y reactivar usan la misma ventana que el envío. Esa validación vive en el
     * controlador, igual que la exigencia de documentos obligatorios antes de
     * enviar o reactivar.
     *
     * @return array<string, string[]>
     */
    public static function transiciones(): array
    {
        return [
            self::Borrador->value => [self::Postulada->value, self::Retirada->value],
            self::Postulada->value => [self::Retirada->value],
            self::Retirada->value => [self::Borrador->value, self::Postulada->value],
        ];
    }
}
