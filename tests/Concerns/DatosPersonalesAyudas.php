<?php

namespace Tests\Concerns;

use App\Models\Departamento;
use App\Models\Municipio;
use App\Models\Parametro;
use App\Models\Postulacion;
use App\Models\PostulacionDatosPersonales;

/**
 * Datos personales de una postulación para las pruebas que ENVÍAN postulaciones: el envío los exige. Por defecto son los
 * de una persona mayor de edad; `$cambios` permite probar otros casos (p. ej. un menor).
 */
trait DatosPersonalesAyudas
{
    private function conUbicacionDePrueba(): void
    {
        Departamento::firstOrCreate(['codigo' => '05'], ['nombre' => 'Antioquia']);
        Municipio::firstOrCreate(['codigo' => '05001'], ['departamento_codigo' => '05', 'nombre' => 'Medellín', 'tipo' => 'municipio']);
    }

    private function conDatosPersonales(Postulacion $postulacion, array $cambios = []): Postulacion
    {
        $this->conUbicacionDePrueba();

        PostulacionDatosPersonales::create($cambios + [
            'postulacion_id' => $postulacion->id,
            'tipo_documento_identidad_id' => Parametro::porTemaYCodigo('tipo_documento_identidad', 'cc')->id,
            'numero_documento' => '1012345678',
            'nombres' => 'Aspirante',
            'apellidos' => 'De Prueba',
            'fecha_nacimiento' => now()->subYears(25)->toDateString(),
            'telefono' => '3001234567',
            'departamento_codigo' => '05',
            'municipio_codigo' => '05001',
            'nivel_formacion_id' => Parametro::porTemaYCodigo('nivel_formacion', 'tecnologo')->id,
            'acepta_tratamiento_datos_at' => now(),
        ]);

        return $postulacion;
    }
}
