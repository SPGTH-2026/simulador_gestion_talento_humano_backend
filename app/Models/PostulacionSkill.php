<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Skill declarada por el aspirante en una postulación. El origen se deduce de
 * los ids: `convocatoria_skill_id` (skill pedida por la convocatoria),
 * `parametro_id` del tema `skill_catalogo` (catálogo general) o ninguno de los
 * dos (skill propia, texto libre). El certificado es opcional: la skill
 * "declarada" no lo tiene; la "certificada" sí.
 */
class PostulacionSkill extends Model
{
    protected $table = 'postulacion_skills';

    protected $fillable = [
        'postulacion_id', 'convocatoria_skill_id', 'parametro_id', 'nombre',
        'certificado_nombre_original', 'certificado_ruta', 'certificado_disco',
        'certificado_mime', 'certificado_tamano_bytes',
    ];

    protected function casts(): array
    {
        return [
            'certificado_tamano_bytes' => 'integer',
        ];
    }

    public function postulacion(): BelongsTo
    {
        return $this->belongsTo(Postulacion::class);
    }

    /** Skill de la convocatoria; NULL si no viene de ahí. */
    public function convocatoriaSkill(): BelongsTo
    {
        return $this->belongsTo(ConvocatoriaSkill::class, 'convocatoria_skill_id');
    }

    /** Parámetro del catálogo (tema skill_catalogo); NULL si no viene de ahí. */
    public function parametro(): BelongsTo
    {
        return $this->belongsTo(Parametro::class, 'parametro_id');
    }

    /** Origen de la skill: convocatoria | catalogo | propia. */
    public function origen(): string
    {
        if ($this->convocatoria_skill_id !== null) {
            return 'convocatoria';
        }

        return $this->parametro_id !== null ? 'catalogo' : 'propia';
    }

    /** ¿Tiene certificado adjunto? */
    public function tieneCertificado(): bool
    {
        return $this->certificado_ruta !== null;
    }
}
