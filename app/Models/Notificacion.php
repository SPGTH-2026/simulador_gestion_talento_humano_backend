<?php

namespace App\Models;

use App\Enums\TipoNotificacion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Notificacion extends Model
{
    protected $table = 'notificaciones';

    // Solo el servicio Notificador crea filas: el usuario y el tipo no se asignan desde una petición.
    protected $fillable = ['user_id', 'tipo', 'titulo', 'mensaje', 'url'];

    protected function casts(): array
    {
        return [
            'tipo' => TipoNotificacion::class,
            'leida_at' => 'datetime',
            'ocultada_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @param  Builder<Notificacion>  $query */
    public function scopeNoLeidas(Builder $query): void
    {
        $query->whereNull('leida_at');
    }

    /** Las que se ven en la lista normal y cuentan como pendientes: no ocultadas. */
    public function scopeVisibles(Builder $query): void
    {
        $query->whereNull('ocultada_at');
    }

    public function scopeOcultas(Builder $query): void
    {
        $query->whereNotNull('ocultada_at');
    }

    public function estaOculta(): bool
    {
        return $this->ocultada_at !== null;
    }

    public function estaLeida(): bool
    {
        return $this->leida_at !== null;
    }
}
