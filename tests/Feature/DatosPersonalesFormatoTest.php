<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\DatosPersonalesAyudas;
use Tests\Concerns\PostulacionDocumentoAyudas;
use Tests\TestCase;

/** El formato de cada dato personal lo exige el servidor: teléfono con código del país, nombres solo con letras, documento numérico. */
class DatosPersonalesFormatoTest extends TestCase
{
    use DatosPersonalesAyudas;
    use PostulacionDocumentoAyudas;
    use RefreshDatabase;

    private static int $fichas = 0;

    /** @return array<string, mixed> */
    private function cuerpo(array $cambios = []): array
    {
        return $cambios + [
            'sexo' => 'hombre',
            'tipo_documento_identidad' => 'cc',
            'numero_documento' => '1012345678',
            'nombres' => 'Camilo Andrés',
            'apellidos' => "Rojas O'Neill-Pérez",
            'fecha_nacimiento' => now('America/Bogota')->subYears(25)->toDateString(),
            'telefono' => '+57 3001234567',
            'departamento' => '05',
            'municipio' => '05001',
            'direccion' => 'Calle 10 # 20-30, Apto 4°',
            'nivel_formacion' => 'tecnologo',
            'acepta_tratamiento_datos' => true,
        ];
    }

    private function guardar(array $cuerpo)
    {
        $ficha = $this->ficha((string) (3500000 + ++self::$fichas));
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->conDocumentos($this->convocatoriaAbierta($ficha, $this->instructor($ficha)), ['hoja_de_vida' => true]);
        $postulacion = $this->crearPostulacion($convocatoria, $aspirante, [
            'estado_id' => $this->parametroId('estado_postulacion', 'borrador'),
        ]);
        $this->conUbicacionDePrueba();

        return $this->actingAs($aspirante)->putJson("/api/postulaciones/{$postulacion->id}/datos-personales", $cuerpo);
    }

    public function test_acepta_un_teléfono_con_el_código_del_país(): void
    {
        $this->guardar($this->cuerpo())->assertOk()->assertJsonPath('data.datos.telefono', '+57 3001234567');
    }

    public function test_acepta_el_teléfono_de_otro_país_y_el_guardado_antes_sin_código(): void
    {
        $this->guardar($this->cuerpo(['telefono' => '+58 4121234567']))->assertOk();
        $this->guardar($this->cuerpo(['telefono' => '3001234567']))->assertOk();
    }

    /** @return array<string, array{string}> */
    public static function telefonosInvalidos(): array
    {
        return [
            'con letras' => ['+57 30012abc67'],
            'Colombia con menos de 10 dígitos' => ['+57 300123456'],
            'Colombia con más de 10 dígitos' => ['+57 30012345678'],
            'sin espacio tras el código' => ['+573001234567'],
            'código inexistente' => ['+0 3001234567'],
            'otro país demasiado corto' => ['+58 12345'],
            'solo texto' => ['tres cero cero'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('telefonosInvalidos')]
    public function test_rechaza_un_teléfono_mal_formado(string $telefono): void
    {
        $this->guardar($this->cuerpo(['telefono' => $telefono]))->assertStatus(422)->assertJsonValidationErrors('telefono');
    }

    public function test_nombres_y_apellidos_solo_llevan_letras(): void
    {
        $this->guardar($this->cuerpo(['nombres' => 'Camilo 123']))->assertStatus(422)->assertJsonValidationErrors('nombres');
        $this->guardar($this->cuerpo(['apellidos' => 'Rojas@Pérez']))->assertStatus(422)->assertJsonValidationErrors('apellidos');
        $this->guardar($this->cuerpo(['nombres' => str_repeat('a', 101)]))->assertStatus(422)->assertJsonValidationErrors('nombres');
    }

    public function test_la_dirección_rechaza_caracteres_raros_y_se_limita(): void
    {
        $this->guardar($this->cuerpo(['direccion' => 'Calle 5 <script>']))->assertStatus(422)->assertJsonValidationErrors('direccion');
        $this->guardar($this->cuerpo(['direccion' => str_repeat('a', 151)]))->assertStatus(422)->assertJsonValidationErrors('direccion');
    }

    public function test_la_cédula_es_solo_numérica_pero_el_pasaporte_admite_letras(): void
    {
        $this->guardar($this->cuerpo(['numero_documento' => '10123A5678']))->assertStatus(422)->assertJsonValidationErrors('numero_documento');
        $this->guardar($this->cuerpo(['numero_documento' => '1.012.345']))->assertStatus(422)->assertJsonValidationErrors('numero_documento');
        $this->guardar($this->cuerpo(['tipo_documento_identidad' => 'pasaporte', 'numero_documento' => 'AB123456']))->assertOk();
    }

    public function test_el_representante_tiene_las_mismas_reglas(): void
    {
        $menor = $this->cuerpo([
            'fecha_nacimiento' => now('America/Bogota')->subYears(17)->toDateString(),
            'representante_nombre' => 'Marta Pérez',
            'representante_tipo_documento' => 'cc',
            'representante_documento' => '52123456',
            'representante_telefono' => '+57 3109876543',
            'representante_correo' => 'marta.perez@example.com',
            'representante_parentesco' => 'Madre',
            'representante_conoce' => true,
        ]);

        $this->guardar($menor)->assertOk();
        $this->guardar(['representante_telefono' => '3109876543abc'] + $menor)->assertStatus(422)->assertJsonValidationErrors('representante_telefono');
        $this->guardar(['representante_nombre' => 'Marta 2'] + $menor)->assertStatus(422)->assertJsonValidationErrors('representante_nombre');
        $this->guardar(['representante_documento' => '52.123.456'] + $menor)->assertStatus(422)->assertJsonValidationErrors('representante_documento');
        $this->guardar(['representante_telefono_alterno' => '+57 12'] + $menor)->assertStatus(422)->assertJsonValidationErrors('representante_telefono_alterno');
    }
}
