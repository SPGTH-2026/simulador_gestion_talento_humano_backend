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
        'postulacion_id', 'sexo_id', 'tipo_documento_identidad_id', 'numero_documento', 'nombres', 'apellidos', 'fecha_nacimiento',
        'telefono', 'departamento_codigo', 'municipio_codigo', 'direccion', 'nivel_formacion_id', 'representante_nombre',
        'representante_documento', 'representante_tipo_documento_id', 'representante_telefono', 'representante_correo',
        'representante_telefono_alterno', 'representante_departamento_codigo', 'representante_municipio_codigo',
        'representante_parentesco', 'representante_conoce', 'acepta_tratamiento_datos_at',
    ];

    protected function casts(): array
    {
        return [
            'fecha_nacimiento' => 'date',
            'acepta_tratamiento_datos_at' => 'datetime',
            'representante_conoce' => 'boolean',
        ];
    }

    public function postulacion(): BelongsTo
    {
        return $this->belongsTo(Postulacion::class);
    }

    public function sexo(): BelongsTo
    {
        return $this->belongsTo(Parametro::class, 'sexo_id');
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

    public function representanteTipoDocumento(): BelongsTo
    {
        return $this->belongsTo(Parametro::class, 'representante_tipo_documento_id');
    }

    public function representanteDepartamento(): BelongsTo
    {
        return $this->belongsTo(Departamento::class, 'representante_departamento_codigo', 'codigo');
    }

    public function representanteMunicipio(): BelongsTo
    {
        return $this->belongsTo(Municipio::class, 'representante_municipio_codigo', 'codigo');
    }

    public function nivelFormacion(): BelongsTo
    {
        return $this->belongsTo(Parametro::class, 'nivel_formacion_id');
    }

    /**
     * ¿Están completos los datos del representante legal? Solo importan si la persona es menor de edad: nombre, tipo y número
     * de documento, teléfono, correo, parentesco y la declaración de que conoce la postulación. (La residencia y el teléfono
     * alterno son opcionales.)
     */
    public function representanteCompleto(): bool
    {
        if (! $this->esMenor()) {
            return true;
        }

        return $this->representante_conoce
            && filled($this->representante_nombre) && filled($this->representante_tipo_documento_id)
            && filled($this->representante_documento) && filled($this->representante_telefono)
            && filled($this->representante_correo) && filled($this->representante_parentesco);
    }

    /** ¿Es menor de 18 años? (entre 16 y 17: pide representante legal y no aplica la libreta militar). */
    public function esMenor(): bool
    {
        return $this->fecha_nacimiento->age < self::MAYORIA_DE_EDAD;
    }
}
