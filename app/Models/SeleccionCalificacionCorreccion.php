<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Evento de corrección de una nota: conserva el valor anterior y el nuevo. */
class SeleccionCalificacionCorreccion extends Model
{
    protected $table = 'seleccion_calificacion_correcciones';

    protected $fillable = [
        'calificacion_id', 'nota_anterior', 'nota_nueva', 'fecha_anterior', 'fecha_nueva',
        'observacion_anterior', 'observacion_nueva', 'motivo', 'corregido_por',
    ];

    protected function casts(): array
    {
        return [
            'nota_anterior' => 'decimal:2',
            'nota_nueva' => 'decimal:2',
            'fecha_anterior' => 'date',
            'fecha_nueva' => 'date',
        ];
    }

    public function calificacion(): BelongsTo
    {
        return $this->belongsTo(SeleccionCalificacion::class, 'calificacion_id');
    }

    public function corrector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'corregido_por');
    }
}
