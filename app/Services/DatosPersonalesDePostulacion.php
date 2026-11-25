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

        $esMenor = $registro->esMenor();
        foreach (['representante_nombre', 'representante_documento', 'representante_telefono', 'representante_parentesco'] as $campo) {
            $registro->{$campo} = $esMenor ? trim($datos[$campo] ?? '') : null;
        }

        $registro->acepta_tratamiento_datos_at ??= now();

        DB::transaction(function () use ($registro, $postulacion, $sexoId): void {
            $registro->save();

            PerfilAspirante::updateOrCreate(['user_id' => $postulacion->user_id], ['sexo_id' => $sexoId]);
        });

        return $registro->load(['tipoDocumentoIdentidad', 'nivelFormacion', 'departamento', 'municipio']);
    }

    /**
     * Datos de la postulación en la forma que consume el frontend. `datos` es null mientras no se hayan llenado.
     *
     * @return array<string, mixed>
     */
    public function presentar(Postulacion $postulacion): array
    {
        $postulacion->loadMissing(['datosPersonales.tipoDocumentoIdentidad', 'datosPersonales.nivelFormacion', 'datosPersonales.departamento', 'datosPersonales.municipio']);
        $datos = $postulacion->datosPersonales;

        return [
            'completo' => $datos !== null,
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
                'representante_documento' => $datos->representante_documento,
                'representante_telefono' => $datos->representante_telefono,
                'representante_parentesco' => $datos->representante_parentesco,
                'acepta_tratamiento_datos' => true,
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
