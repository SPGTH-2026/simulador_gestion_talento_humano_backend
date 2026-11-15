<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Una versión confirmada de la lista de finalistas de una convocatoria. */
class SeleccionFinalistaLista extends Model
{
    protected $table = 'seleccion_finalistas_listas';

    protected $fillable = ['convocatoria_id', 'version', 'modo', 'objetivo', 'motivo', 'confirmado_por'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'objetivo' => 'integer'];
    }

    public function miembros(): HasMany
    {
        return $this->hasMany(SeleccionFinalistaMiembro::class, 'lista_id')->orderBy('id');
    }

    public function confirmador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmado_por');
    }
}
