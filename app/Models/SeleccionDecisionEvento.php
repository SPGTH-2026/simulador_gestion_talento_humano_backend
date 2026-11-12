<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Registro inmutable de un cambio de decisión: resultado anterior y nuevo, motivo, actor y lista que lo sustentó. */
class SeleccionDecisionEvento extends Model
{
    protected $table = 'seleccion_decision_eventos';

    protected $fillable = ['decision_id', 'resultado_anterior', 'resultado_nuevo', 'motivo', 'lista_version', 'actor_id'];

    protected function casts(): array
    {
        return ['lista_version' => 'integer'];
    }

    public function decision(): BelongsTo
    {
        return $this->belongsTo(SeleccionDecision::class, 'decision_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
