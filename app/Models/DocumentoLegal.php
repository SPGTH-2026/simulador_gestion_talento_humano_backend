<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * Versión de un texto legal. Una versión APROBADA es inmutable: no se edita ni se borra (lo impide el modelo y, en la base, las
 * aceptaciones bloquean el borrado). La aprobación es administrativa y NO equivale a una certificación jurídica del texto.
 */
class DocumentoLegal extends Model
{
    public const BORRADOR = 'borrador';

    public const APROBADO = 'aprobado';

    public const TIPOS = ['privacidad', 'terminos'];

    protected $table = 'documentos_legales';

    // Estado, huella y datos de aprobación no se asignan en masa: solo los fija el servicio al aprobar.
    protected $fillable = ['tipo', 'version', 'titulo', 'contenido'];

    protected function casts(): array
    {
        return ['aprobado_en' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $doc): void {
            if ($doc->getOriginal('estado') === self::APROBADO) {
                throw new LogicException('Una versión aprobada no se puede modificar.');
            }
        });
        static::deleting(function (self $doc): void {
            if ($doc->estado === self::APROBADO) {
                throw new LogicException('Una versión aprobada no se puede eliminar.');
            }
        });
    }

    public function estaAprobado(): bool
    {
        return $this->estado === self::APROBADO;
    }

    /** Huella del texto: cambia si cambia el título o el contenido. */
    public static function calcularHuella(string $titulo, string $contenido): string
    {
        return hash('sha256', $titulo."\n".$contenido);
    }

    public function aceptaciones(): HasMany
    {
        return $this->hasMany(AceptacionLegal::class, 'documento_legal_id');
    }
}
