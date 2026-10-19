<?php

namespace App\Tests\Feature;

use App\Enums\Role;
use App\Models\Parametro;
use App\Models\ParametroTema;
use App\Models\User;
use Database\Seeders\ParametroSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Paso 2a: GET /api/parametros.
 * Catálogo de solo lectura para los formularios del módulo: activos, ordenados
 * por 'orden' y luego por 'nombre', agrupados por tema sin ?tema= y planos con
 * ?tema=. Protegido por auth:sanctum + verified + can:convocatorias:ver.
 */
class ParametroControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ParametroSeeder::class);
    }

    private function usuario(Role $role, ?string $subrole = null, array $extra = []): User
    {
        return User::factory()->create($extra + [
            'role' => $role,
            'subrole' => $subrole,
            'active' => true,
            'email_verified_at' => now(),
        ]);
    }

    public function test_devuelve_el_catalogo_completo_agrupado_por_tema(): void
    {
        $grupos = $this->actingAs($this->usuario(Role::Aspirante))
            ->getJson('/api/parametros')
            ->assertOk()
            ->json('data');

        $this->assertCount(10, $grupos);

        $total = array_sum(array_map(fn (array $g) => count($g['parametros']), $grupos));
        $this->assertSame(52, $total);

        foreach ($grupos as $grupo) {
            $this->assertSame(['tema', 'nombre', 'parametros'], array_keys($grupo));
            foreach ($grupo['parametros'] as $parametro) {
                // Solo campos públicos: sin tema_id ni timestamps.
                $this->assertSame(
                    ['id', 'codigo', 'nombre', 'es_sistema', 'es_defecto'],
                    array_keys($parametro)
                );
            }
        }
    }

    public function test_filtro_por_tema_valido_devuelve_lista_plana(): void
    {
        $data = $this->actingAs($this->usuario(Role::Aspirante))
            ->getJson('/api/parametros?tema=tipo_empleo')
            ->assertOk()
            ->json('data');

        $this->assertCount(3, $data);
        $this->assertSame(
            ['tiempo_completo', 'medio_tiempo', 'por_horas'],
            array_column($data, 'codigo')
        );
        $this->assertSame(
            ['id', 'codigo', 'nombre', 'es_sistema', 'es_defecto'],
            array_keys($data[0])
        );
    }

    public function test_ordena_por_orden_y_luego_por_nombre(): void
    {
        $tema = ParametroTema::where('codigo', 'tipo_empleo')->firstOrFail();

        // Mismo 'orden' (99) y distinto nombre: el desempate debe ser alfabético.
        Parametro::create(['tema_id' => $tema->id, 'codigo' => 'zzz', 'nombre' => 'Zeta', 'orden' => 99, 'activo' => true]);
        Parametro::create(['tema_id' => $tema->id, 'codigo' => 'aaa', 'nombre' => 'Alfa', 'orden' => 99, 'activo' => true]);

        $codigos = array_column(
            $this->actingAs($this->usuario(Role::Aspirante))
                ->getJson('/api/parametros?tema=tipo_empleo')
                ->assertOk()
                ->json('data'),
            'codigo'
        );

        $this->assertSame(['aaa', 'zzz'], array_slice($codigos, -2));
    }

    public function test_es_defecto_presente_donde_aplica(): void
    {
        $grupos = collect(
            $this->actingAs($this->usuario(Role::Aspirante))
                ->getJson('/api/parametros')
                ->assertOk()
                ->json('data')
        )->keyBy('tema');

        $esDefecto = function (string $tema, string $codigo) use ($grupos): bool {
            $parametro = collect($grupos[$tema]['parametros'])->firstWhere('codigo', $codigo);
            $this->assertNotNull($parametro, "Falta {$tema}/{$codigo}.");

            return $parametro['es_defecto'];
        };

        $this->assertTrue($esDefecto('tipo_convocatoria', 'externo'));
        $this->assertTrue($esDefecto('tipo_empleo', 'tiempo_completo'));
        $this->assertTrue($esDefecto('tipo_contrato', 'termino_fijo'));
        $this->assertTrue($esDefecto('modalidad', 'presencial'));

        // Un no-defecto del mismo tema debe quedar en false.
        $this->assertFalse($esDefecto('tipo_convocatoria', 'interno'));
    }

    public function test_skills_se_devuelven_como_sugerencias(): void
    {
        $data = $this->actingAs($this->usuario(Role::Aspirante))
            ->getJson('/api/parametros?tema=skill_tecnica')
            ->assertOk()
            ->json('data');

        $this->assertCount(6, $data);

        // Las skills son sugerencias de texto libre: en el seeder no tienen codigo.
        $this->assertSame(
            ['Excel avanzado', 'Power BI', 'SAP HR', 'Legislación laboral', 'Selección de personal', 'Entrevistas por competencias'],
            array_column($data, 'nombre')
        );
        $this->assertSame(array_fill(0, 6, null), array_column($data, 'codigo'));
    }

    public function test_tema_desconocido_devuelve_404(): void
    {
        $this->actingAs($this->usuario(Role::Aspirante))
            ->getJson('/api/parametros?tema=no_existe')
            ->assertNotFound();
    }

    public function test_parametro_inactivo_no_aparece(): void
    {
        Parametro::porTemaYCodigo('tipo_empleo', 'por_horas')->update(['activo' => false]);

        $data = $this->actingAs($this->usuario(Role::Aspirante))
            ->getJson('/api/parametros?tema=tipo_empleo')
            ->assertOk()
            ->json('data');

        $this->assertCount(2, $data);
        $this->assertNotContains('por_horas', array_column($data, 'codigo'));
    }

    public function test_aspirante_puede_leer_el_catalogo_de_skills(): void
    {
        $data = $this->actingAs($this->usuario(Role::Aspirante))
            ->getJson('/api/parametros?tema=skill_catalogo')
            ->assertOk()
            ->json('data');

        $this->assertCount(19, $data);
        $this->assertSame(
            ['id', 'codigo', 'nombre', 'es_sistema', 'es_defecto'],
            array_keys($data[0])
        );
        $this->assertContains('comunicacion_asertiva', array_column($data, 'codigo'));
        $this->assertContains('excel', array_column($data, 'codigo'));
        $this->assertContains('redaccion_informes', array_column($data, 'codigo'));
    }

    public function test_usuario_sin_ficha_puede_consultar(): void
    {
        $usuario = $this->usuario(Role::Aspirante, null, ['ficha_id' => null]);
        $this->assertNull($usuario->ficha_id);

        $this->actingAs($usuario)->getJson('/api/parametros')->assertOk();
    }

    public function test_401_sin_sesion(): void
    {
        $this->getJson('/api/parametros')->assertUnauthorized();
    }

    public function test_403_sin_permiso(): void
    {
        // El aprendiz evaluador no tiene convocatorias:ver.
        $usuario = $this->usuario(Role::Aprendiz, 'evaluador');

        $this->assertNotContains('convocatorias:ver', $usuario->permissions());

        $this->actingAs($usuario)->getJson('/api/parametros')->assertForbidden();
    }
}
