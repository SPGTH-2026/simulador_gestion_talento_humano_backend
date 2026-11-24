<?php

namespace App\Models;

use App\Enums\Role;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

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

    protected $fillable = ['tema_id', 'codigo', 'nombre', 'orden', 'activo', 'es_sistema', 'es_defecto', 'creado_por'];

    /** Fichas a las que pertenece un documento creado por un gestor. Sin filas = documento global (sistema o anterior). */
    public function fichas(): BelongsToMany
    {
        return $this->belongsToMany(Ficha::class, 'parametro_fichas', 'parametro_id', 'ficha_id');
    }

    /**
     * Parámetros que `$usuario` puede ver. Los globales (de sistema o creados antes del aislamiento: sin creador y sin
     * fichas) los ve cualquiera; un documento creado por un gestor solo lo ven su creador y quienes comparten alguna de
     * sus fichas. El super admin ve todo.
     */
    public function scopeVisiblesPara(Builder $query, User $usuario): Builder
    {
        if ($usuario->role === Role::SuperAdmin) {
            return $query;
        }

        return $query->where(function (Builder $w) use ($usuario) {
            $w->where(fn (Builder $global) => $global->whereNull('creado_por')->whereDoesntHave('fichas'))
                ->orWhere('creado_por', $usuario->id)
                ->orWhereHas('fichas', fn (Builder $f) => $f->whereIn('fichas.id', $usuario->fichaIds()));
        });
    }

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
        // Se memoriza durante la petición (ver AppServiceProvider): una sola consulta
        // por tema+código aunque se pida varias veces.
        $memo = app('parametros.memo');
        $clave = $codigoTema.'.'.$codigoParametro;

        if (! $memo->offsetExists($clave)) {
            $memo[$clave] = static::query()
                ->whereHas('tema', fn (Builder $q) => $q->where('codigo', $codigoTema))
                ->where('codigo', $codigoParametro)
                ->first();
        }

        return $memo[$clave];
    }
}