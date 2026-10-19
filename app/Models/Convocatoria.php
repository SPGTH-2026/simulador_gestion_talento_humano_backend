<?php

namespace App\Models;

use App\Enums\ConvocatoriaEstado;
use App\Enums\VentanaPostulacion;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Convocatoria extends Model
{
    // ficha_id, codigo, estado_id y creado_por NO son asignables en masa: son de
    // la regla de negocio y el controlador los valida y los pone explícitamente.
    // Las skills se guardan como texto libre en la tabla convocatoria_skills.
    protected $fillable = [
        'nombre', 'cargo', 'area', 'descripcion', 'vacantes',
        'salario_min', 'salario_max',
        'funciones', 'responsabilidades', 'perfil_requerido',
        'formacion', 'experiencia', 'competencias', 'conocimientos_especificos',
        'tipo_convocatoria_id', 'tipo_empleo_id', 'tipo_contrato_id', 'modalidad_id',
        'fecha_apertura', 'fecha_cierre', 'fecha_evaluacion',
    ];

    protected function casts(): array
    {
        return [
            'vacantes' => 'integer',
            'salario_min' => 'float',
            'salario_max' => 'float',
            'fecha_apertura' => 'date',
            'fecha_cierre' => 'date',
            'fecha_evaluacion' => 'date',
        ];
    }

    public function ficha(): BelongsTo
    {
        return $this->belongsTo(Ficha::class);
    }

    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por');
    }

    public function tipoConvocatoria(): BelongsTo
    {
        return $this->belongsTo(Parametro::class, 'tipo_convocatoria_id');
    }

    public function tipoEmpleo(): BelongsTo
    {
        return $this->belongsTo(Parametro::class, 'tipo_empleo_id');
    }

    public function tipoContrato(): BelongsTo
    {
        return $this->belongsTo(Parametro::class, 'tipo_contrato_id');
    }

    public function modalidad(): BelongsTo
    {
        return $this->belongsTo(Parametro::class, 'modalidad_id');
    }

    /** Estado como parámetro: siempre apunta a un valor del tema estado_convocatoria. */
    public function estado(): BelongsTo
    {
        return $this->belongsTo(Parametro::class, 'estado_id');
    }

    /** Skills exigidas (texto libre definido por el gestor para esta convocatoria). */
    public function skills(): HasMany
    {
        return $this->hasMany(ConvocatoriaSkill::class);
    }

    /** Documentos requeridos (parámetros del tema tipo_documento). */
    public function documentos(): BelongsToMany
    {
        return $this->belongsToMany(Parametro::class, 'convocatoria_documentos', 'convocatoria_id', 'parametro_id')
            ->withPivot('obligatorio');
    }

    /** Postulaciones recibidas. La ficha de la postulación vive aquí, no en la postulación. */
    public function postulaciones(): HasMany
    {
        return $this->hasMany(Postulacion::class);
    }

    /**
     * Único punto que decide si HOY se puede postular a esta convocatoria.
     *
     * Debe estar publicada y la fecha de hoy (en America/Bogota, para que "hoy"
     * sea el mismo día para el aspirante y el gestor) caer dentro de
     * fecha_apertura <= hoy <= fecha_cierre, con los dos extremos inclusive.
     * Los `date` se comparan por día (toDateString) para no depender de la hora.
     */
    public function ventanaPostulacion(?CarbonInterface $hoy = null): VentanaPostulacion
    {
        if ($this->estado?->codigo !== ConvocatoriaEstado::Publicada->value) {
            return VentanaPostulacion::NoPublicada;
        }

        $hoy = ($hoy ?? now('America/Bogota'))->toDateString();

        if ($this->fecha_apertura === null || $hoy < $this->fecha_apertura->toDateString()) {
            return VentanaPostulacion::AntesDeApertura;
        }

        if ($this->fecha_cierre === null || $hoy > $this->fecha_cierre->toDateString()) {
            return VentanaPostulacion::Cerrada;
        }

        return VentanaPostulacion::Abierta;
    }
}
