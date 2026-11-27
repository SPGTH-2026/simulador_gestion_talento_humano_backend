<?php

namespace App\Services\Documentos;

use App\Models\PerfilAspirante;
use App\Models\PostulacionDatosPersonales;

/**
 * Regla única de «la libreta militar no aplica». Antes estaba repetida en cada lugar que la consulta; ahora todos
 * preguntan aquí, para que la presentación, el envío, la evaluación y el portafolio no diverjan.
 *
 * No aplica a las mujeres ni a los menores de 18 años (la libreta se tramita al cumplir la mayoría de edad).
 */
final class ReglaLibreta
{
    public static function noAplica(?PerfilAspirante $perfil, ?PostulacionDatosPersonales $datos): bool
    {
        return $perfil?->sexo?->codigo === 'mujer' || ($datos?->esMenor() ?? false);
    }
}
