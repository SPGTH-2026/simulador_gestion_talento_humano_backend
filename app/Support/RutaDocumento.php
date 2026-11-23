<?php

namespace App\Support;

use App\Models\Postulacion;

/**
 * Carpeta de los archivos de una postulación en el disco de documentos:
 *
 *   fichas/{ficha}/usuarios/{usuario}/postulaciones/{postulacion}/{tipo}
 *
 * La ficha sale de la CONVOCATORIA (fijada al crearla), no del usuario: un aprendiz puede cambiar de ficha, sus archivos
 * de una postulación no se mueven. Solo van IDs en la ruta, nunca nombre ni correo.
 */
final class RutaDocumento
{
    public static function carpeta(Postulacion $postulacion, string $tipo): string
    {
        $postulacion->loadMissing('convocatoria:id,ficha_id');

        return sprintf(
            'fichas/%d/usuarios/%d/postulaciones/%d/%s',
            $postulacion->convocatoria->ficha_id,
            $postulacion->user_id,
            $postulacion->id,
            $tipo,
        );
    }
}
