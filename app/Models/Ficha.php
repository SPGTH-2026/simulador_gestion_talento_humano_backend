<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Ficha extends Model
{
    protected $fillable = ['codigo', 'nombre_programa', 'estado', 'creado_por'];

    protected function casts(): array
    {
        return [
            'estado' => 'string',
        ];
    }

    /** Ficha de un aspirante/aprendiz. El instructor usa fichas() (pivot N:M). */
    public function instructores(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'ficha_instructor', 'ficha_id', 'usuario_id');
    }

    /**
     * Usuario que registró la ficha. Es su dueño: mientras exista, puede
     * gestionarla aunque nadie se la asigne por el pivot.
     */
    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por');
    }

    /** Convocatorias de la ficha. */
    public function convocatorias(): HasMany
    {
        return $this->hasMany(Convocatoria::class, 'ficha_id');
    }

    /** Aspirantes y aprendices que pertenecen a la ficha (users.ficha_id). */
    public function usuarios(): HasMany
    {
        return $this->hasMany(User::class, 'ficha_id');
    }
}
