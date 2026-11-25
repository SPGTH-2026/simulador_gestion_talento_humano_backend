<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Departamento de Colombia (DIVIPOLA). La clave es el código oficial del DANE (2 dígitos). */
class Departamento extends Model
{
    protected $primaryKey = 'codigo';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = ['codigo', 'nombre'];

    public function municipios(): HasMany
    {
        return $this->hasMany(Municipio::class, 'departamento_codigo', 'codigo');
    }
}
