<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Skill de una convocatoria: puede venir del CATÁLOGO (tema skill_catalogo,
 * con `parametro_id`) o ser TEXTO LIBRE escrito por el gestor (`parametro_id`
 * en NULL). En ambos casos `nombre` es la copia visible: si el parámetro se
 * retira del catálogo, la fila se conserva con su nombre (FK SET NULL).
 *
 * La dedupe (sin distinguir mayúsculas ni espacios sobrantes) y el tope de 20
 * las hace el controlador antes de persistir; la tabla solo garantiza la
 * unicidad exacta por convocatoria.
 */
class ConvocatoriaSkill extends Model
{
    protected $table = 'convocatoria_skills';

    protected $fillable = ['convocatoria_id', 'nombre', 'parametro_id'];

    public function convocatoria(): BelongsTo
    {
        return $this->belongsTo(Convocatoria::class);
    }

    /** Parámetro del catálogo del que provino la skill (NULL si es texto libre). */
    public function parametro(): BelongsTo
    {
        return $this->belongsTo(Parametro::class);
    }
}
