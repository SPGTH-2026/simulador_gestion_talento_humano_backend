<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Actividades presenciales de Selección configuradas para una convocatoria.
 * Los pesos son porcentajes enteros; null en una actividad desactivada.
 */
class SeleccionConfiguracion extends Model
{
    protected $table = 'seleccion_configuraciones';

    protected $fillable = [
        'convocatoria_id', 'entrevista_activa', 'prueba_tecnica_activa',
        'peso_entrevista', 'peso_prueba_tecnica', 'actualizado_por',
    ];

    protected function casts(): array
    {
        return [
            'entrevista_activa' => 'boolean',
            'prueba_tecnica_activa' => 'boolean',
            'peso_entrevista' => 'integer',
            'peso_prueba_tecnica' => 'integer',
        ];
    }

    public function convocatoria(): BelongsTo
    {
        return $this->belongsTo(Convocatoria::class);
    }

    public function actualizador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actualizado_por');
    }
}
