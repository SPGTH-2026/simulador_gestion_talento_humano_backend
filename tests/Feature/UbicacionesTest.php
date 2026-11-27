<?php

namespace Tests\Feature;

use App\Models\Departamento;
use App\Models\Municipio;
use Database\Seeders\UbicacionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\DatosPersonalesAyudas;
use Tests\Concerns\PostulacionDocumentoAyudas;
use Tests\TestCase;

/**
 * Departamentos y municipios de Colombia (DIVIPOLA, DANE): el sembrador, los endpoints de consulta y la validación en los
 * datos personales (el municipio debe ser un código oficial del departamento elegido).
 */
class UbicacionesTest extends TestCase
{
    use DatosPersonalesAyudas;
    use PostulacionDocumentoAyudas;
    use RefreshDatabase;

    public function test_el_sembrador_carga_los_33_departamentos_y_los_1122_municipios_y_es_idempotente(): void
    {
        $this->seed(UbicacionSeeder::class);
        $this->seed(UbicacionSeeder::class);

        $this->assertSame(33, Departamento::count());
        $this->assertSame(1122, Municipio::count());
        $this->assertSame('Bogotá, D.C.', Municipio::findOrFail('11001')->nombre);
        $this->assertSame('Cartagena de Indias', Municipio::findOrFail('13001')->nombre);
        $this->assertSame('05', Municipio::findOrFail('05001')->departamento_codigo);
    }

    public function test_ningun_municipio_queda_huerfano_y_su_codigo_empieza_con_el_de_su_departamento(): void
    {
        $this->seed(UbicacionSeeder::class);

        $this->assertSame(0, Municipio::whereDoesntHave('departamento')->count());
        $this->assertSame(0, Municipio::all()->filter(fn (Municipio $m) => ! str_starts_with($m->codigo, $m->departamento_codigo))->count());
    }

    public function test_el_aspirante_lista_los_departamentos_y_los_municipios_de_uno(): void
    {
        $this->seed(UbicacionSeeder::class);
        $aspirante = $this->aspirante($this->ficha());

        $departamentos = $this->actingAs($aspirante)->getJson('/api/ubicaciones/departamentos')->assertOk()->json('data');
        $this->assertCount(33, $departamentos);
        $this->assertSame(['codigo', 'nombre'], array_keys($departamentos[0]));
        $this->assertContains('Antioquia', array_column($departamentos, 'nombre'));

        $municipios = $this->actingAs($aspirante)->getJson('/api/ubicaciones/departamentos/05/municipios')->assertOk()->json('data');
        $this->assertCount(125, $municipios);
        $this->assertContains('Medellín', array_column($municipios, 'nombre'));
        // Solo los de ese departamento.
        $this->assertSame([], array_filter($municipios, fn (array $m) => ! str_starts_with($m['codigo'], '05')));
    }

    public function test_un_departamento_inexistente_responde_404_y_sin_sesion_401(): void
    {
        $this->seed(UbicacionSeeder::class);

        $this->actingAs($this->aspirante($this->ficha()))->getJson('/api/ubicaciones/departamentos/77/municipios')->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/ubicaciones/departamentos')->assertUnauthorized();
    }

    public function test_los_datos_personales_exigen_un_municipio_del_departamento_elegido(): void
    {
        $this->seed(UbicacionSeeder::class);
        $ficha = $this->ficha();
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->conDocumentos($this->convocatoriaAbierta($ficha, $this->instructor($ficha)), ['hoja_de_vida' => false]);
        $postulacion = $this->crearPostulacion($convocatoria, $aspirante, ['estado_id' => $this->parametroId('estado_postulacion', 'borrador')]);

        $cuerpo = [
            'sexo' => 'hombre', 'tipo_documento_identidad' => 'cc', 'numero_documento' => '1012345678', 'nombres' => 'Ana',
            'apellidos' => 'Pérez', 'fecha_nacimiento' => now()->subYears(25)->toDateString(), 'telefono' => '3001234567',
            'nivel_formacion' => 'tecnologo', 'acepta_tratamiento_datos' => true,
        ];
        $guardar = fn (array $ubicacion) => $this->actingAs($aspirante)->putJson("/api/postulaciones/{$postulacion->id}/datos-personales", $cuerpo + $ubicacion);

        // Cartagena (13001) no es de Antioquia (05).
        $guardar(['departamento' => '05', 'municipio' => '13001'])->assertStatus(422)->assertJsonValidationErrors('municipio');
        // Códigos inventados o texto libre.
        $guardar(['departamento' => '77', 'municipio' => '77001'])->assertStatus(422)->assertJsonValidationErrors('departamento');
        $guardar(['departamento' => 'Antioquia', 'municipio' => 'Medellín'])->assertStatus(422)->assertJsonValidationErrors(['departamento', 'municipio']);

        $guardar(['departamento' => '05', 'municipio' => '05001'])
            ->assertOk()
            ->assertJsonPath('data.datos.departamento', ['codigo' => '05', 'nombre' => 'Antioquia'])
            ->assertJsonPath('data.datos.municipio', ['codigo' => '05001', 'nombre' => 'Medellín']);
    }
}
