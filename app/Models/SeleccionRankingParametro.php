<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Objetivo de finalistas de una convocatoria; null usa la sugerencia por defecto. */
class SeleccionRankingParametro extends Model
{
    protected $table = 'seleccion_ranking_parametros';

    protected $fillable = ['convocatoria_id', 'objetivo_finalistas', 'actualizado_por'];

    protected function casts(): array
    {
        return ['objetivo_finalistas' => 'integer'];
    }
}
