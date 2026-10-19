<?php

namespace App\Services;

use App\Models\Parametro;
use App\Models\PerfilAspirante;
use App\Models\Postulacion;

/**
 * Regla única de "documentos obligatorios de una postulación". La comparten la
 * presentación del estado (GET, campo `completo`) y la validación del envío,
 * para que no diverjan.
 *
 * Un documento obligatorio está satisfecho si tiene archivo subido o si está en
 * `no_aplica` (la libreta militar de una mujer no se exige).
 */
class DocumentosDePostulacion
{
    /** Motivo que acompaña a la libreta cuando el aspirante no ha registrado sexo. */
    public const MOTIVO_FALTA_SEXO =
        'Indica tu sexo para saber si aplica la libreta militar.';

    /**
     * Faltantes que impiden enviar: documentos OBLIGATORIOS de la convocatoria
     * sin archivo y que no están en 'no_aplica'. La libreta militar solo aplica
     * a hombres. Cuando no hay sexo registrado y la convocatoria pide la libreta,
     * ese faltante lleva un `motivo` propio.
     *
     * @return array<int, array{codigo: string, nombre: string, motivo?: string}>
     */
    public function faltantes(Postulacion $postulacion): array
    {
        $postulacion->loadMissing(['convocatoria.documentos', 'documentos']);

        $perfil = PerfilAspirante::query()
            ->where('user_id', $postulacion->user_id)
            ->with('sexo')
            ->first();

        $sexoMujer = $perfil?->sexo?->codigo === 'mujer';

        $subidos = $postulacion->documentos
            ->whereNotNull('parametro_id')
            ->keyBy('parametro_id');

        $faltantes = [];

        foreach ($postulacion->convocatoria->documentos as $parametro) {
            if (! (bool) $parametro->pivot->obligatorio) {
                continue;
            }

            // La libreta de una mujer no se exige: no es un faltante.
            if ($parametro->codigo === Parametro::DOC_LIBRETA_MILITAR && $sexoMujer) {
                continue;
            }

            if ($subidos->has($parametro->id)) {
                continue;
            }

            $faltante = [
                'codigo' => $parametro->codigo,
                'nombre' => $parametro->nombre,
            ];

            // Sin sexo no se sabe si la libreta aplica: se pide indicarlo.
            if ($parametro->codigo === Parametro::DOC_LIBRETA_MILITAR && $perfil === null) {
                $faltante['motivo'] = self::MOTIVO_FALTA_SEXO;
            }

            $faltantes[] = $faltante;
        }

        return $faltantes;
    }

    /** ¿Están todos los obligatorios subidos o en 'no_aplica'? */
    public function completo(Postulacion $postulacion): bool
    {
        return $this->faltantes($postulacion) === [];
    }

    /**
     * Mensaje humano para el 422 del envío.
     *
     * @param  array<int, array{codigo: string, nombre: string, motivo?: string}>  $faltantes
     */
    public function mensaje(array $faltantes): string
    {
        $nombres = array_map(
            fn (array $f) => isset($f['motivo'])
                ? "{$f['nombre']} ({$f['motivo']})"
                : $f['nombre'],
            $faltantes,
        );

        return 'Debes completar los documentos obligatorios: '.implode(', ', $nombres).'.';
    }
}
