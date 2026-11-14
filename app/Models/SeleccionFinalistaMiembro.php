<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Candidato incluido en una versión de la lista de finalistas, con su estado al confirmar. */
class SeleccionFinalistaMiembro extends Model
{
    protected $table = 'seleccion_finalistas_miembros';

    protected $fillable = ['lista_id', 'postulacion_id', 'posicion', 'resultado', 'huella'];

    protected function casts(): array
    {
        return ['posicion' => 'integer', 'resultado' => 'decimal:2'];
    }

    public function postulacion(): BelongsTo
    {
        return $this->belongsTo(Postulacion::class);
    }
}
