<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Skill de una convocatoria: texto libre guardado en mayúscula inicial.
 * La dedupe (sin distinguir mayúsculas) y el tope de 20 la hace el controlador
 * antes de persistir; la tabla solo garantiza la unicidad exacta.
 */
class ConvocatoriaSkill extends Model
{
    protected $table = 'convocatoria_skills';

    protected $fillable = ['convocatoria_id', 'nombre'];

    public function convocatoria(): BelongsTo
    {
        return $this->belongsTo(Convocatoria::class);
    }
}