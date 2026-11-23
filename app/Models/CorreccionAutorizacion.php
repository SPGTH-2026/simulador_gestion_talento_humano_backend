<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Autorización excepcional de una ronda de corrección (una sola ronda por autorización). */
class CorreccionAutorizacion extends Model
{
    protected $table = 'correccion_autorizaciones';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['usada_at' => 'datetime'];
    }

    public function autorizador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'autorizada_por');
    }
}
