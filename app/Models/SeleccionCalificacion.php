<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Nota vigente de una actividad presencial (entrevista o prueba técnica) de una
 * postulación. El historial de cambios vive en SeleccionCalificacionCorreccion.
 */
class SeleccionCalificacion extends Model
{
    public const ACTIVIDADES = ['entrevista', 'prueba_tecnica'];

    protected $table = 'seleccion_calificaciones';

    protected $fillable = [
        'convocatoria_id', 'postulacion_id', 'actividad', 'nota',
        'fecha_realizacion', 'observacion', 'registrado_por',
    ];

    protected function casts(): array
    {
        return [
            'nota' => 'decimal:2',
            'fecha_realizacion' => 'date',
        ];
    }

    public function postulacion(): BelongsTo
    {
        return $this->belongsTo(Postulacion::class);
    }

    public function registrador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }

    public function correcciones(): HasMany
    {
        return $this->hasMany(SeleccionCalificacionCorreccion::class, 'calificacion_id')->orderBy('id');
    }
}
