<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Evaluacion extends Model
{
    // El plural del inflector de Laravel es inglés ('evaluacions'): tabla en español.
    protected $table = 'evaluaciones';

    protected $fillable = [
        'postulacion_id',
        'evaluador_id',
        'puntaje_total',
        'estado',
        'observaciones',
        'perfil',
        'cumplimiento',
        'pasa_seleccion',
        'correccion_enviada_at',
        'fecha_limite_correccion',
    ];

    /** Días que tiene el aspirante para corregir desde que el evaluador lo pide. */
    public const DIAS_LIMITE_CORRECCION = 2;

    protected function casts(): array
    {
        return [
            'puntaje_total' => 'decimal:2',
            'perfil' => 'array',
            'cumplimiento' => 'integer',
            'pasa_seleccion' => 'boolean',
            'correccion_enviada_at' => 'datetime',
            'fecha_limite_correccion' => 'datetime',
        ];
    }

    public function postulacion(): BelongsTo
    {
        return $this->belongsTo(Postulacion::class);
    }

    public function evaluador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'evaluador_id');
    }

    public function detalles(): HasMany
    {
        return $this->hasMany(EvaluacionDetalle::class);
    }
}
