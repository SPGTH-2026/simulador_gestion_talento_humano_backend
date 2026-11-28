<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Designación (evento) de la evaluación documental de referencia de una
 * postulación. Ver la migración para el detalle de la foto guardada.
 */
class ReferenciaDocumental extends Model
{
    protected $table = 'referencias_documentales';

    protected $fillable = [
        'postulacion_id', 'evaluacion_id', 'evaluador_id', 'designado_por',
        'justificacion', 'cumplimiento', 'pasa_seleccion', 'evaluacion_actualizada_en', 'evaluacion_revision',
    ];

    protected function casts(): array
    {
        return [
            'cumplimiento' => 'float',
            // Revisión de la evaluación elegida al designarla; 0 = nunca vigente (referencias que ya eran inválidas).
            'evaluacion_revision' => 'integer',
            'pasa_seleccion' => 'boolean',
            'evaluacion_actualizada_en' => 'datetime',
        ];
    }

    public function postulacion(): BelongsTo
    {
        return $this->belongsTo(Postulacion::class);
    }

    public function evaluacion(): BelongsTo
    {
        return $this->belongsTo(Evaluacion::class);
    }

    public function evaluador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'evaluador_id');
    }

    public function designador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'designado_por');
    }
}
