<?php

namespace Tests\Concerns;

use App\Models\Parametro;
use App\Models\Postulacion;

/**
 * Una postulación ENVIADA ya no se edita: para cambiar un documento hay que retirarla. Estas pruebas parten de una
 * postulación enviada (con su evaluación) y comprueban lo que ocurre al cambiar un documento, así que simulan el cambio
 * con la postulación retirada y la dejan como estaba al terminar.
 */
trait MientrasEditable
{
    private function mientrasEditable(Postulacion $postulacion, callable $accion): mixed
    {
        $original = $postulacion->estado_id;
        $postulacion->forceFill(['estado_id' => Parametro::porTemaYCodigo('estado_postulacion', 'retirada')->id])->save();

        try {
            return $accion();
        } finally {
            $postulacion->forceFill(['estado_id' => $original])->save();
        }
    }
}
