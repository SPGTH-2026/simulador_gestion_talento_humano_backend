<?php

namespace App\Models;

use App\Enums\PostulacionEstado;
use App\Services\RondasCorreccion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Evaluacion extends Model
{
    // El plural del inflector de Laravel es inglés ('evaluacions'): tabla en español.
    protected $table = 'evaluaciones';

    protected $fillable = [
        'postulacion_id',
        'evaluador_id',
        'puntaje_total',
        'estado',
        'observaciones',
        'perfil',
        'cumplimiento',
        'pasa_seleccion',
        'correccion_enviada_at',
        'fecha_limite_correccion',
    ];

    /** Días que tiene el aspirante para corregir desde que el evaluador lo pide. */
    public const DIAS_LIMITE_CORRECCION = 2;

    protected function casts(): array
    {
        return [
            'puntaje_total' => 'decimal:2',
            'perfil' => 'array',
            'cumplimiento' => 'integer',
            'pasa_seleccion' => 'boolean',
            'correccion_enviada_at' => 'datetime',
            'fecha_limite_correccion' => 'datetime',
            // Sube con cada cambio real y con cada invalidación documental; no está en $fillable: solo la mueve el servidor.
            'revision' => 'integer',
        ];
    }

    public function postulacion(): BelongsTo
    {
        return $this->belongsTo(Postulacion::class);
    }

    /**
     * Estado de la corrección pedida al aspirante. Es la única definición de «vigente»: la usan la edición del aspirante
     * y los listados, para que ninguna pantalla recalcule la regla.
     *
     *  - null:       no hay corrección solicitada.
     *  - 'vigente':  pedida, sin enviar, con plazo no vencido y la postulación sigue «Postulada». No depende del estado
     *                de la convocatoria: una convocatoria cerrada puede tener una corrección vigente.
     *  - 'enviada':  el aspirante ya respondió.
     *  - 'vencida':  se acabó el plazo sin respuesta (o la solicitud no tiene plazo registrado).
     *  - 'inactiva': el plazo corre pero la postulación ya no está «Postulada» (p. ej. retirada).
     */
    public function estadoCorreccion(?Postulacion $postulacion = null): ?string
    {
        $postulacion ??= $this->postulacion;

        if (! $postulacion) {
            return null;
        }

        // El estado sale de las rondas de la POSTULACIÓN (compartidas entre evaluadores), no de esta evaluación.
        $ronda = app(RondasCorreccion::class)->rondas($postulacion)->last();

        if (! $ronda) {
            return null;
        }

        return match ($ronda->estado()) {
            'respondida' => 'enviada',
            'vigente' => $postulacion->loadMissing('estado')->estado?->codigo === PostulacionEstado::Postulada->value ? 'vigente' : 'inactiva',
            default => 'vencida',
        };
    }

    public function evaluador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'evaluador_id');
    }

    public function detalles(): HasMany
    {
        return $this->hasMany(EvaluacionDetalle::class);
    }
}
