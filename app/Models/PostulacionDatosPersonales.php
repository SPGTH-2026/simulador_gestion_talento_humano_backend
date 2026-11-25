<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Datos personales declarados por el aspirante en UNA postulación (identificación, contacto, formación y, si es menor
 * de edad, su representante legal). Cada convocatoria empieza en blanco: no se heredan del perfil.
 */
class PostulacionDatosPersonales extends Model
{
    protected $table = 'postulacion_datos_personales';

    /** Edad mínima para postularse. Por debajo de ella el sistema no deja continuar. */
    public const EDAD_MINIMA = 16;

    public const MAYORIA_DE_EDAD = 18;

    protected $fillable = [
        'postulacion_id', 'tipo_documento_identidad_id', 'numero_documento', 'nombres', 'apellidos', 'fecha_nacimiento',
        'telefono', 'departamento_codigo', 'municipio_codigo', 'direccion', 'nivel_formacion_id', 'representante_nombre',
        'representante_documento', 'representante_telefono', 'representante_parentesco', 'acepta_tratamiento_datos_at',
    ];

    protected function casts(): array
    {
        return [
            'fecha_nacimiento' => 'date',
            'acepta_tratamiento_datos_at' => 'datetime',
        ];
    }

    public function postulacion(): BelongsTo
    {
        return $this->belongsTo(Postulacion::class);
    }

    public function tipoDocumentoIdentidad(): BelongsTo
    {
        return $this->belongsTo(Parametro::class, 'tipo_documento_identidad_id');
    }

    public function departamento(): BelongsTo
    {
        return $this->belongsTo(Departamento::class, 'departamento_codigo', 'codigo');
    }

    public function municipio(): BelongsTo
    {
        return $this->belongsTo(Municipio::class, 'municipio_codigo', 'codigo');
    }

    public function nivelFormacion(): BelongsTo
    {
        return $this->belongsTo(Parametro::class, 'nivel_formacion_id');
    }

    /** ¿Es menor de 18 años? (entre 16 y 17: pide representante legal y no aplica la libreta militar). */
    public function esMenor(): bool
    {
        return $this->fecha_nacimiento->age < self::MAYORIA_DE_EDAD;
    }
}
