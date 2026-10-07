<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Ficha extends Model
{
    protected $fillable = ['codigo', 'nombre_programa', 'estado'];

    protected function casts(): array
    {
        return [
            'estado' => 'string',
        ];
    }

    public function instructores(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'ficha_instructor', 'ficha_id', 'usuario_id');
    }
}