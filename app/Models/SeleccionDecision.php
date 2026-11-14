<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Decisión interna vigente de un candidato. El historial vive en SeleccionDecisionEvento. */
class SeleccionDecision extends Model
{
    public const RESULTADOS = ['seleccionado', 'no_seleccionado'];

    protected $table = 'seleccion_decisiones';

    protected $fillable = ['convocatoria_id', 'postulacion_id', 'resultado', 'motivo', 'lista_version', 'decidido_por'];

    protected function casts(): array
    {
        return ['lista_version' => 'integer'];
    }

    public function postulacion(): BelongsTo
    {
        return $this->belongsTo(Postulacion::class);
    }

    public function decididor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decidido_por');
    }

    public function eventos(): HasMany
    {
        return $this->hasMany(SeleccionDecisionEvento::class, 'decision_id');
    }
}
