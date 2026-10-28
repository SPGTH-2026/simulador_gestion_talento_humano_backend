<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Postulacion extends Model
{
    // El pluralizador por defecto no es de español: sin esto Eloquent buscaría
    // la tabla "postulacions". La tabla real es "postulaciones".
    protected $table = 'postulaciones';

    // La ficha no se copia: se deriva de la convocatoria. Tampoco se asignan en
    // masa la convocatoria ni el usuario en los endpoints: el controlador los
    // resuelve del recurso y del autenticado.
    protected $fillable = [
        'convocatoria_id', 'user_id', 'estado_id',
        'fecha_postulacion', 'fecha_retiro',
    ];

    protected function casts(): array
    {
        return [
            'fecha_postulacion' => 'datetime',
            'fecha_retiro' => 'datetime',
        ];
    }

    public function convocatoria(): BelongsTo
    {
        return $this->belongsTo(Convocatoria::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Estado como parámetro: siempre un valor del tema estado_postulacion. */
    public function estado(): BelongsTo
    {
        return $this->belongsTo(Parametro::class, 'estado_id');
    }

    /** Documentos adjuntados a esta postulación (requeridos y adicionales). */
    public function documentos(): HasMany
    {
        return $this->hasMany(PostulacionDocumento::class);
    }

    /** Skills declaradas por el aspirante en esta postulación. */
    public function skills(): HasMany
    {
        return $this->hasMany(PostulacionSkill::class);
    }

    /** Evaluaciones recibidas sobre esta postulación (módulo de Evaluación). */
    public function evaluaciones(): HasMany
    {
        return $this->hasMany(Evaluacion::class);
    }
}
