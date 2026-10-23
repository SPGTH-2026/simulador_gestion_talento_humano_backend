<?php

namespace App\Tests\Feature;

use App\Enums\PostulacionEstado;
use App\Enums\Role;
use App\Models\Convocatoria;
use App\Models\Ficha;
use App\Models\Parametro;
use App\Models\ParametroTema;
use App\Models\Postulacion;
use App\Models\User;
use Database\Seeders\ParametroSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Paso 1: esquema y catálogo del módulo de postulaciones.
 * Cubre la tabla postulaciones (columnas, UNIQUE y RESTRICT de las 3 FK), la
 * semilla del tema estado_postulacion y la relación Convocatoria::postulaciones().
 */
class PostulacionPaso1Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ParametroSeeder::class);
    }

    private function parametro(string $tema, string $codigo): Parametro
    {
        $parametro = Parametro::porTemaYCodigo($tema, $codigo);
        $this->assertNotNull($parametro, "Falta el parámetro {$tema}/{$codigo}.");

        return $parametro;
    }

    private function ficha(string $codigo = '3173334'): Ficha
    {
        return Ficha::create(['codigo' => $codigo, 'nombre_programa' => 'Análisis y Desarrollo de Software']);
    }

    private function usuario(Role $role = Role::Aspirante, array $extra = []): User
    {
        return User::factory()->create($extra + [
            'role' => $role,
            'active' => true,
            'email_verified_at' => now(),
        ]);
    }

    private function convocatoria(Ficha $ficha, array $overrides = []): Convocatoria
    {
        $datos = array_merge([
            'codigo' => 'CONV-2026-001',
            'nombre' => 'Convocatoria de prueba',
            'vacantes' => 1,
            'tipo_convocatoria_id' => $this->parametro('tipo_convocatoria', 'externo')->id,
            'tipo_empleo_id' => $this->parametro('tipo_empleo', 'tiempo_completo')->id,
            'tipo_contrato_id' => $this->parametro('tipo_contrato', 'termino_fijo')->id,
            'modalidad_id' => $this->parametro('modalidad', 'presencial')->id,
            'estado_id' => $this->parametro('estado_convocatoria', 'publicada')->id,
            'ficha_id' => $ficha->id,
            'creado_por' => $this->usuario(Role::Instructor)->id,
        ], $overrides);

        return Convocatoria::unguarded(fn () => Convocatoria::create($datos));
    }

    private function postulacion(Convocatoria $convocatoria, User $usuario, array $overrides = []): Postulacion
    {
        return Postulacion::create(array_merge([
            'convocatoria_id' => $convocatoria->id,
            'user_id' => $usuario->id,
            'estado_id' => $this->parametro('estado_postulacion', 'postulada')->id,
            'fecha_postulacion' => now(),
        ], $overrides));
    }

    // ── Esquema ────────────────────────────────────────────────────────

    public function test_postulaciones_usa_el_esquema_esperado(): void
    {
        foreach ([
            'id', 'convocatoria_id', 'user_id', 'estado_id',
            'fecha_postulacion', 'fecha_retiro', 'created_at', 'updated_at',
        ] as $columna) {
            $this->assertTrue(Schema::hasColumn('postulaciones', $columna), "Falta la columna {$columna}.");
        }
    }

    public function test_fecha_postulacion_tiene_valor_por_defecto(): void
    {
        $postulacion = Postulacion::create([
            'convocatoria_id' => $this->convocatoria($this->ficha())->id,
            'user_id' => $this->usuario()->id,
            'estado_id' => $this->parametro('estado_postulacion', 'postulada')->id,
        ]);

        $this->assertNotNull($postulacion->fresh()->fecha_postulacion);
    }

    public function test_no_se_puede_postular_dos_veces_a_la_misma_convocatoria(): void
    {
        $convocatoria = $this->convocatoria($this->ficha());
        $usuario = $this->usuario();

        $this->postulacion($convocatoria, $usuario);

        $this->expectException(QueryException::class);
        $this->postulacion($convocatoria, $usuario);
    }

    // ── RESTRICT de las 3 FK ───────────────────────────────────────────

    public function test_no_se_puede_borrar_una_convocatoria_con_postulaciones(): void
    {
        $convocatoria = $this->convocatoria($this->ficha());
        $this->postulacion($convocatoria, $this->usuario());

        $this->expectException(QueryException::class);
        $convocatoria->delete();
    }

    public function test_no_se_puede_borrar_un_usuario_con_postulaciones(): void
    {
        $usuario = $this->usuario();
        $this->postulacion($this->convocatoria($this->ficha()), $usuario);

        $this->expectException(QueryException::class);
        $usuario->delete();
    }

    public function test_no_se_puede_borrar_el_estado_de_una_postulacion(): void
    {
        $this->postulacion($this->convocatoria($this->ficha()), $this->usuario());
        $estadoPostulada = $this->parametro('estado_postulacion', 'postulada');

        $this->expectException(QueryException::class);
        $estadoPostulada->delete();
    }

    // ── Semilla estado_postulacion ─────────────────────────────────────

    public function test_estado_postulacion_queda_como_sistema_activo_por_codigo(): void
    {
        $this->assertSame(
            ['borrador', 'postulada', 'retirada'],
            ParametroTema::where('codigo', 'estado_postulacion')
                ->firstOrFail()
                ->parametros()
                ->orderBy('orden')
                ->pluck('codigo')
                ->all(),
        );

        foreach (['borrador', 'postulada', 'retirada'] as $codigo) {
            $parametro = $this->parametro('estado_postulacion', $codigo);
            $this->assertTrue($parametro->es_sistema, "estado_postulacion/{$codigo} debe ser de sistema.");
            $this->assertTrue($parametro->activo, "estado_postulacion/{$codigo} debe quedar activo.");
        }
    }

    public function test_el_seeder_agrega_el_tema_y_es_idempotente(): void
    {
        $this->seed(ParametroSeeder::class);

        $this->assertSame(10, ParametroTema::count());
        $this->assertSame(43, Parametro::count());
        $this->assertNotNull($this->parametro('estado_postulacion', 'borrador'));
        $this->assertNotNull($this->parametro('estado_postulacion', 'postulada'));
        $this->assertNotNull($this->parametro('estado_postulacion', 'retirada'));
    }

    // ── Enum y relaciones ──────────────────────────────────────────────

    public function test_enum_de_estados_expone_codigos_y_transiciones(): void
    {
        $this->assertSame(['borrador', 'postulada', 'retirada'], PostulacionEstado::codigos());
        $this->assertSame(
            ['postulada', 'retirada'],
            PostulacionEstado::transiciones()['borrador']
        );
        $this->assertSame(
            ['retirada'],
            PostulacionEstado::transiciones()['postulada']
        );
        $this->assertSame(
            ['borrador', 'postulada'],
            PostulacionEstado::transiciones()['retirada']
        );
    }

    public function test_relaciones_de_convocatoria_y_postulacion(): void
    {
        $convocatoria = $this->convocatoria($this->ficha());
        $usuario = $this->usuario();
        $postulacion = $this->postulacion($convocatoria, $usuario);

        $this->assertSame(1, $convocatoria->postulaciones()->count());
        $this->assertTrue($convocatoria->postulaciones->first()->is($postulacion));
        $this->assertSame($convocatoria->id, $postulacion->convocatoria->id);
        $this->assertSame($usuario->id, $postulacion->user->id);
        $this->assertSame('postulada', $postulacion->estado->codigo);
    }
}
