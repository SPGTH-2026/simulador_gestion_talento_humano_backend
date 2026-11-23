<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una ronda de corrección de una postulación. Ver App\Services\RondasCorreccion.
 */
class CorreccionPostulacion extends Model
{
    protected $table = 'correcciones_postulacion';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'documentos_marcados' => 'array',
            'documentos_respondidos' => 'array',
            'documentos_base' => 'array',
            'solicitada_at' => 'datetime',
            'fecha_limite' => 'datetime',
            'respondida_at' => 'datetime',
            'cerrada_at' => 'datetime',
        ];
    }

    /**
     * Estado derivado de las fechas (no se guarda, así no depende de tareas ni de visitas a la pantalla):
     * 'respondida', 'sin_plazo' (solicitud antigua sin plazo registrado), 'vencida' o 'vigente'.
     */
    public function estado(): string
    {
        if ($this->respondida_at) {
            return 'respondida';
        }

        if ($this->fecha_limite === null) {
            return 'sin_plazo';
        }

        return now()->gt($this->fecha_limite) ? 'vencida' : 'vigente';
    }

    public function solicitante(): BelongsTo
    {
        return $this->belongsTo(User::class, 'solicitada_por');
    }

    public function postulacion(): BelongsTo
    {
        return $this->belongsTo(Postulacion::class);
    }
}
