<?php

namespace App\Services;

use App\Models\Parametro;
use App\Models\PerfilAspirante;
use App\Models\Postulacion;
use App\Models\PostulacionDatosPersonales;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Lectura y escritura de los datos personales de una postulación. El controlador solo valida y responde: las reglas de
 * negocio viven aquí.
 *
 * El sexo se pregunta aquí y solo aquí. Además de quedar en el formulario, se escribe en el perfil del aspirante porque
 * de él dependen otras reglas (la libreta militar), que leen el perfil.
 */
class DatosPersonalesDePostulacion
{
    /** Relaciones que se cargan para presentar los datos (catálogos y ubicaciones, del aspirante y de su representante). */
    private const RELACIONES = [
        'tipoDocumentoIdentidad', 'nivelFormacion', 'departamento', 'municipio',
        'representanteTipoDocumento', 'representanteDepartamento', 'representanteMunicipio',
    ];

    /**
     * Guarda (crea o reemplaza) los datos de la postulación. Los catálogos llegan por CÓDIGO. Un mayor de edad no conserva
     * datos de representante de un guardado anterior. La fecha de aceptación del tratamiento de datos se conserva.
     *
     * @param  array<string, mixed>  $datos  entrada ya validada por DatosPersonalesRequest
     */
    public function guardar(Postulacion $postulacion, array $datos): PostulacionDatosPersonales
    {
        $sexoId = $this->resolver('sexo', $datos['sexo'], 'sexo', 'El sexo debe ser masculino o femenino.');
        $tipoId = $this->resolver('tipo_documento_identidad', $datos['tipo_documento_identidad'], 'tipo_documento_identidad', 'El tipo de documento no es válido.');
        $nivelId = $this->resolver('nivel_formacion', $datos['nivel_formacion'], 'nivel_formacion', 'El nivel de formación no es válido.');

        $registro = PostulacionDatosPersonales::firstOrNew(['postulacion_id' => $postulacion->id]);

        $registro->fill([
            'tipo_documento_identidad_id' => $tipoId,
            'nivel_formacion_id' => $nivelId,
            'numero_documento' => strtoupper($datos['numero_documento']),
            'nombres' => trim($datos['nombres']),
            'apellidos' => trim($datos['apellidos']),
            'fecha_nacimiento' => $datos['fecha_nacimiento'],
            'telefono' => preg_replace('/\s+/', ' ', trim($datos['telefono'])),
            'departamento_codigo' => $datos['departamento'],
            'municipio_codigo' => $datos['municipio'],
            'direccion' => trim($datos['direccion'] ?? '') ?: null,
        ]);

        $this->guardarRepresentante($registro, $datos);

        // La autorización se registra al marcarla y no se revoca guardando de nuevo: conserva la fecha de la primera vez.
        if (! empty($datos['acepta_tratamiento_datos'])) {
            $registro->acepta_tratamiento_datos_at ??= now();
        }

        DB::transaction(function () use ($registro, $postulacion, $sexoId): void {
            $registro->save();

            PerfilAspirante::updateOrCreate(['user_id' => $postulacion->user_id], ['sexo_id' => $sexoId]);
        });

        return $registro->load(self::RELACIONES);
    }

    /**
     * Datos del representante legal: solo los lleva un menor de edad (16 y 17 años); un mayor no conserva los de un
     * guardado anterior. La autorización se firma en persona al contratar: aquí solo hay datos para localizarlo.
     *
     * @param  array<string, mixed>  $datos
     */
    private function guardarRepresentante(PostulacionDatosPersonales $registro, array $datos): void
    {
        $texto = fn (string $campo) => trim($datos[$campo] ?? '') ?: null;

        if (! $registro->esMenor()) {
            $registro->forceFill([
                'representante_nombre' => null, 'representante_documento' => null, 'representante_tipo_documento_id' => null,
                'representante_telefono' => null, 'representante_correo' => null, 'representante_telefono_alterno' => null,
                'representante_departamento_codigo' => null, 'representante_municipio_codigo' => null,
                'representante_parentesco' => null, 'representante_conoce' => false,
            ]);

            return;
        }

        $registro->forceFill([
            'representante_nombre' => $texto('representante_nombre'),
            'representante_documento' => strtoupper((string) $texto('representante_documento')),
            'representante_tipo_documento_id' => $this->resolver('tipo_documento_identidad', $datos['representante_tipo_documento'], 'representante_tipo_documento', 'El tipo de documento del representante no es válido.'),
            'representante_telefono' => $texto('representante_telefono'),
            'representante_correo' => strtolower((string) $texto('representante_correo')),
            'representante_telefono_alterno' => $texto('representante_telefono_alterno'),
            'representante_departamento_codigo' => $texto('representante_departamento'),
            'representante_municipio_codigo' => $texto('representante_municipio'),
            'representante_parentesco' => $texto('representante_parentesco'),
            'representante_conoce' => ! empty($datos['representante_conoce']),
        ]);
    }

    /**
     * Datos de la postulación en la forma que consume el frontend. `datos` es null mientras no se hayan llenado.
     *
     * @return array<string, mixed>
     */
    public function presentar(Postulacion $postulacion): array
    {
        $postulacion->loadMissing(array_map(fn (string $relacion) => "datosPersonales.{$relacion}", self::RELACIONES));
        $datos = $postulacion->datosPersonales;

        return [
            // «Completo» = datos guardados Y autorización dada: es lo que permite pasar a documentos y enviar.
            'completo' => $datos?->acepta_tratamiento_datos_at !== null,
            'es_menor' => $datos?->esMenor() ?? false,
            'edad_minima' => PostulacionDatosPersonales::EDAD_MINIMA,
            'datos' => $datos ? [
                'tipo_documento_identidad' => $this->parametro($datos->tipoDocumentoIdentidad),
                'numero_documento' => $datos->numero_documento,
                'nombres' => $datos->nombres,
                'apellidos' => $datos->apellidos,
                'fecha_nacimiento' => $datos->fecha_nacimiento->toDateString(),
                'telefono' => $datos->telefono,
                'departamento' => $datos->departamento ? ['codigo' => $datos->departamento->codigo, 'nombre' => $datos->departamento->nombre] : null,
                'municipio' => $datos->municipio ? ['codigo' => $datos->municipio->codigo, 'nombre' => $datos->municipio->nombre] : null,
                'direccion' => $datos->direccion,
                'nivel_formacion' => $this->parametro($datos->nivelFormacion),
                'representante_nombre' => $datos->representante_nombre,
                'representante_tipo_documento' => $this->parametro($datos->representanteTipoDocumento),
                'representante_documento' => $datos->representante_documento,
                'representante_telefono' => $datos->representante_telefono,
                'representante_correo' => $datos->representante_correo,
                'representante_telefono_alterno' => $datos->representante_telefono_alterno,
                'representante_departamento' => $datos->representanteDepartamento ? ['codigo' => $datos->representanteDepartamento->codigo, 'nombre' => $datos->representanteDepartamento->nombre] : null,
                'representante_municipio' => $datos->representanteMunicipio ? ['codigo' => $datos->representanteMunicipio->codigo, 'nombre' => $datos->representanteMunicipio->nombre] : null,
                'representante_parentesco' => $datos->representante_parentesco,
                'representante_conoce' => $datos->representante_conoce,
                'acepta_tratamiento_datos' => $datos->acepta_tratamiento_datos_at !== null,
            ] : null,
        ];
    }

    private function resolver(string $tema, string $codigo, string $campo, string $mensaje): int
    {
        $parametro = Parametro::porTemaYCodigo($tema, $codigo);

        if (! $parametro || ! $parametro->activo) {
            throw ValidationException::withMessages([$campo => $mensaje]);
        }

        return $parametro->id;
    }

    private function parametro(?Parametro $parametro): ?array
    {
        return $parametro
            ? ['id' => $parametro->id, 'codigo' => $parametro->codigo, 'nombre' => $parametro->nombre]
            : null;
    }
}
