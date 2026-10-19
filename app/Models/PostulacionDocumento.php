<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Documento adjuntado a una postulación. Si parametro_id es NULL es un documento
 * ADICIONAL ("tipo otro"): exige título y admite nota. Si tiene parametro_id es
 * uno de los documentos que pide la convocatoria (uno por tipo).
 */
class PostulacionDocumento extends Model
{
    protected $table = 'postulacion_documentos';

    protected $fillable = [
        'postulacion_id', 'parametro_id', 'titulo', 'nota',
        'nombre_original', 'ruta', 'disco', 'mime', 'tamano_bytes',
    ];

    protected function casts(): array
    {
        return [
            'tamano_bytes' => 'integer',
        ];
    }

    public function postulacion(): BelongsTo
    {
        return $this->belongsTo(Postulacion::class);
    }

    /** Tipo de documento (tema tipo_documento). NULL en los adicionales. */
    public function parametro(): BelongsTo
    {
        return $this->belongsTo(Parametro::class);
    }

    /** ¿Es un documento adicional ("tipo otro")? */
    public function esAdicional(): bool
    {
        return $this->parametro_id === null;
    }
}
