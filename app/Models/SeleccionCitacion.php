<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Una cita de Selección (entrevista o prueba técnica) de una postulación. Ver la migración para las reglas de «vigente» e historial.
 */
class SeleccionCitacion extends Model
{
    public const PROGRAMADA = 'programada';

    public const CANCELADA = 'cancelada';

    protected $table = 'seleccion_citaciones';

    protected $fillable = [
        'convocatoria_id', 'postulacion_id', 'actividad', 'fecha_hora', 'lugar_o_enlace', 'indicaciones',
        'estado', 'vigente', 'motivo_cancelacion', 'creada_por',
    ];

    protected function casts(): array
    {
        return ['fecha_hora' => 'datetime', 'vigente' => 'boolean'];
    }

    public function postulacion(): BelongsTo
    {
        return $this->belongsTo(Postulacion::class);
    }

    public function convocatoria(): BelongsTo
    {
        return $this->belongsTo(Convocatoria::class);
    }

    public function creadora(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creada_por');
    }

    public function eventos(): HasMany
    {
        return $this->hasMany(SeleccionCitacionEvento::class, 'citacion_id')->orderBy('id');
    }

    /** ¿Es un enlace (http o https)? Si no, es texto de un lugar. La validación impide cualquier otro protocolo. */
    public function esEnlace(): bool
    {
        return (bool) preg_match('#^https?://#i', $this->lugar_o_enlace);
    }
}
