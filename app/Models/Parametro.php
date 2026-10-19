<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Parametro extends Model
{
    // Códigos de documentos típicos (tema tipo_documento). Siguen siendo valores
    // de sistema (o al menos estables) y se resuelven por código (los ids cambian
    // entre local y Render). Ya NO se agregan automáticamente a la convocatoria:
    // cuáles son obligatorios lo decide config/convocatorias.php.
    public const DOC_HOJA_DE_VIDA = 'hoja_de_vida';
    public const DOC_DOCUMENTO_IDENTIDAD = 'documento_identidad';
    public const DOC_CERTIFICADOS_ACADEMICOS = 'certificados_academicos';
    public const DOC_CERTIFICADOS_LABORALES = 'certificados_laborales';
    public const DOC_LIBRETA_MILITAR = 'libreta_militar';
    public const DOC_TARJETA_PROFESIONAL = 'tarjeta_profesional';

    protected $fillable = ['tema_id', 'codigo', 'nombre', 'orden', 'activo', 'es_sistema', 'es_defecto'];

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
            'es_sistema' => 'boolean',
            'es_defecto' => 'boolean',
        ];
    }

    public function tema(): BelongsTo
    {
        return $this->belongsTo(ParametroTema::class, 'tema_id');
    }

    /**
     * Busca un parámetro por el código de su tema y su propio código.
     * Es la forma SIEMPRE de resolver los valores de sistema (estados y
     * documentos obligatorios): estable entre local y Render.
     */
    public static function porTemaYCodigo(string $codigoTema, string $codigoParametro): ?self
    {
        return static::query()
            ->whereHas('tema', fn (Builder $q) => $q->where('codigo', $codigoTema))
            ->where('codigo', $codigoParametro)
            ->first();
    }
}