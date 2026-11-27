<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Aceptación de una versión aprobada por el propio usuario autenticado. Es evidencia: no se modifica ni se borra. Guarda una copia
 * del nombre, el correo y la huella del texto aceptado, de modo que sigue siendo legible si la cuenta se desactiva o se elimina.
 * No registra IP ni datos del navegador.
 */
class AceptacionLegal extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'aceptaciones_legales';

    // Nada se asigna en masa: lo fija el servicio a partir del usuario autenticado y de la versión aprobada.
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['aceptado_en' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('Una aceptación registrada no se puede modificar.');
        });
        static::deleting(function (): void {
            throw new LogicException('Una aceptación registrada no se puede eliminar.');
        });
    }

    public function documento(): BelongsTo
    {
        return $this->belongsTo(DocumentoLegal::class, 'documento_legal_id');
    }
}
