<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Perfil mínimo del aspirante. Por ahora solo guarda el sexo, que se captura en
 * el paso de documentos (solo si la convocatoria pide libreta militar) y queda
 * prellenado para las próximas postulaciones.
 */
class PerfilAspirante extends Model
{
    // El pluralizador por defecto no es de español: la tabla es "perfiles_aspirante".
    protected $table = 'perfiles_aspirante';

    protected $fillable = ['user_id', 'sexo_id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Sexo como parámetro del tema 'sexo' (códigos hombre/mujer). */
    public function sexo(): BelongsTo
    {
        return $this->belongsTo(Parametro::class, 'sexo_id');
    }
}
