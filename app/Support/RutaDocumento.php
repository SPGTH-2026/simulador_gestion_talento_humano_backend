<?php

namespace App\Support;

use App\Models\Ficha;
use App\Models\Postulacion;

/**
 * Carpeta de los archivos de una postulación en el disco de documentos:
 *
 *   fichas/{codigo_ficha}/usuarios/{usuario}/postulaciones/{postulacion}/{tipo}
 *
 * El primer segmento es el código de la ficha (p. ej. 3173334), el mismo que el sistema muestra como "Ficha 3173334".
 * La ficha sale de la CONVOCATORIA (fijada al crearla), no del usuario: un aprendiz puede cambiar de ficha, sus archivos
 * de una postulación no se mueven. Por eso la ruta guardada en la base manda: si la ficha se renombra después, los
 * archivos ya subidos conservan su carpeta anterior.
 * Solo van el código de ficha y el ID del usuario y de la postulación en la ruta, nunca nombre ni correo.
 */
final class RutaDocumento
{
    public static function carpeta(Postulacion $postulacion, string $tipo): string
    {
        $postulacion->loadMissing('convocatoria.ficha:id,codigo');

        return sprintf(
            'fichas/%s/usuarios/%d/postulaciones/%d/%s',
            self::segmentoFicha($postulacion->convocatoria->ficha),
            $postulacion->user_id,
            $postulacion->id,
            $tipo,
        );
    }

    /**
     * Código de la ficha como nombre de carpeta: solo letras, números, guion y punto. Cualquier otro carácter (espacios,
     * barras, acentos) se cambia por guion bajo, para que la ruta sea válida en cualquier almacén.
     */
    public static function segmentoFicha(Ficha $ficha): string
    {
        $limpio = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) $ficha->codigo), '._-');

        return $limpio !== '' ? $limpio : 'ficha-'.$ficha->id;
    }
}
