<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Historial de una cita: programada, reprogramada o cancelada, con quién y qué cambió. */
class SeleccionCitacionEvento extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'seleccion_citacion_eventos';

    protected $fillable = ['citacion_id', 'tipo', 'usuario_id', 'detalle'];

    protected function casts(): array
    {
        return ['detalle' => 'array'];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }
}
