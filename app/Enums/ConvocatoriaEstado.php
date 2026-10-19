<?php

namespace App\Enums;

// Estados de una convocatoria (tema estado_convocatoria). La lógica SIEMPRE
// consulta por codigo, nunca por id ni por nombre: los ids cambian entre
// local y Render; el codigo es estable.
enum ConvocatoriaEstado: string
{
    case Borrador = 'borrador';
    case Publicada = 'publicada';
    case EnProceso = 'en_proceso';
    case Cerrada = 'cerrada';

    /** @return string[] */
    public static function codigos(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Transiciones permitidas (el candado avanza de a UN paso):
     *   borrador  -> publicada     (se publica la convocatoria)
     *   publicada -> en_proceso    ("Cerrar recepción": ya no llegan postulaciones)
     *   en_proceso -> cerrada      ("Cerrar convocatoria")
     * No se salta un paso, no se repite un estado y una cerrada no se reabre.
     *
     * @return array<string, string[]>
     */
    public static function transiciones(): array
    {
        return [
            self::Borrador->value => [self::Publicada->value],
            self::Publicada->value => [self::EnProceso->value],
            self::EnProceso->value => [self::Cerrada->value],
        ];
    }
}