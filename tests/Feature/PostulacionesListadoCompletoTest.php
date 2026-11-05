<?php

namespace App\Tests\Feature;

use App\Enums\Role;
use App\Models\Convocatoria;
use App\Models\Ficha;
use App\Models\Parametro;
use App\Models\Postulacion;
use App\Models\PostulacionDocumento;
use App\Models\User;
use Database\Seeders\ParametroSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * El listado "mis postulaciones" trae `completo` por fila, calculado en lote:
 * el número de consultas no depende de cuántas postulaciones haya en la página.
 */
class PostulacionesListadoCompletoTest extends TestCase
{
    use RefreshDatabase;

    private static int $codigoSecuencia = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ParametroSeeder::class);
    }

    private function parametroId(string $tema, string $codigo): int
    {
        return (int) Parametro::porTemaYCodigo($tema, $codigo)->id;
    }

    private function aspirante(Ficha $ficha): User
    {
        return User::factory()->create([
            'role' => Role::Aspirante,
            'active' => true,
            'ficha_id' => $ficha->id,
            'email_verified_at' => now(),
        ]);
    }

    private function convocatoria(Ficha $ficha, User $creador): Convocatoria
    {
        $c = Convocatoria::unguarded(fn () => Convocatoria::create([
            'ficha_id' => $ficha->id,
            'codigo' => sprintf('CONV-LCO-%03d', ++self::$codigoSecuencia),
            'nombre' => 'Convocatoria '.self::$codigoSecuencia,
            'vacantes' => 1,
            'tipo_convocatoria_id' => $this->parametroId('tipo_convocatoria', 'externo'),
            'tipo_empleo_id' => $this->parametroId('tipo_empleo', 'tiempo_completo'),
            'tipo_contrato_id' => $this->parametroId('tipo_contrato', 'termino_fijo'),
            'modalidad_id' => $this->parametroId('modalidad', 'presencial'),
            'estado_id' => $this->parametroId('estado_convocatoria', 'publicada'),
            'fecha_apertura' => now('America/Bogota')->subDay()->toDateString(),
            'fecha_cierre' => now('America/Bogota')->addDay()->toDateString(),
            'creado_por' => $creador->id,
        ]));

        $c->documentos()->attach($this->parametroId('tipo_documento', 'hoja_de_vida'), ['obligatorio' => true]);

        return $c->fresh();
    }

    private function borrador(Convocatoria $c, User $aspirante): Postulacion
    {
        return Postulacion::create([
            'convocatoria_id' => $c->id,
            'user_id' => $aspirante->id,
            'estado_id' => $this->parametroId('estado_postulacion', 'borrador'),
            'fecha_postulacion' => now(),
        ]);
    }

    private function subirHojaDeVida(Postulacion $p): void
    {
        PostulacionDocumento::create([
            'postulacion_id' => $p->id,
            'parametro_id' => $this->parametroId('tipo_documento', 'hoja_de_vida'),
            'nombre_original' => 'hv.pdf',
            'ruta' => 'x/hv.pdf',
            'disco' => 'local',
            'mime' => 'application/pdf',
            'tamano_bytes' => 100,
        ]);
    }

    public function test_completo_cambia_al_subir_el_obligatorio(): void
    {
        $ficha = Ficha::create(['codigo' => '3173334', 'nombre_programa' => 'ADSO']);
        $instructor = User::factory()->create(['role' => Role::Instructor, 'active' => true, 'email_verified_at' => now()]);
        $aspirante = $this->aspirante($ficha);
        $p = $this->borrador($this->convocatoria($ficha, $instructor), $aspirante);

        $this->actingAs($aspirante)->getJson('/api/postulaciones')
            ->assertOk()
            ->assertJsonPath('data.0.completo', false);

        $this->subirHojaDeVida($p);

        $this->actingAs($aspirante)->getJson('/api/postulaciones')
            ->assertOk()
            ->assertJsonPath('data.0.completo', true);
    }

    public function test_el_numero_de_consultas_no_crece_con_las_postulaciones(): void
    {
        $ficha = Ficha::create(['codigo' => '3173334', 'nombre_programa' => 'ADSO']);
        $instructor = User::factory()->create(['role' => Role::Instructor, 'active' => true, 'email_verified_at' => now()]);
        $aspirante = $this->aspirante($ficha);

        $this->borrador($this->convocatoria($ficha, $instructor), $aspirante);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($aspirante)->getJson('/api/postulaciones')->assertOk();
        $consultasUna = count(DB::getQueryLog());

        for ($i = 0; $i < 5; $i++) {
            $this->borrador($this->convocatoria($ficha, $instructor), $aspirante);
        }

        DB::flushQueryLog();
        $this->actingAs($aspirante)->getJson('/api/postulaciones')
            ->assertOk()
            ->assertJsonCount(6, 'data');
        $consultasSeis = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame($consultasUna, $consultasSeis, 'El listado debe usar el mismo número de consultas sin importar cuántas filas trae.');
    }
}
