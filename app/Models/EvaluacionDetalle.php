<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EvaluacionDetalle extends Model
{
    // Explícito para no depender del plural inglés del inflector de Laravel.
    protected $table = 'evaluacion_detalles';

    protected $fillable = ['evaluacion_id', 'criterio', 'puntaje', 'ponderacion'];

    protected function casts(): array
    {
        return [
            'puntaje' => 'decimal:2',
            'ponderacion' => 'decimal:2',
        ];
    }

    public function evaluacion(): BelongsTo
    {
        return $this->belongsTo(Evaluacion::class);
    }
}
