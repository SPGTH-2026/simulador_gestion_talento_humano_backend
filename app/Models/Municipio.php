<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Municipio de Colombia (DIVIPOLA). La clave es el código oficial del DANE (5 dígitos; los 2 primeros son los del departamento). */
class Municipio extends Model
{
    protected $primaryKey = 'codigo';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = ['codigo', 'departamento_codigo', 'nombre', 'tipo'];

    public function departamento(): BelongsTo
    {
        return $this->belongsTo(Departamento::class, 'departamento_codigo', 'codigo');
    }
}
