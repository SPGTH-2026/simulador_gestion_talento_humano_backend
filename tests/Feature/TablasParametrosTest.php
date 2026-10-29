<?php

namespace App\Tests\Feature;

use App\Enums\Role;
use App\Models\Convocatoria;
use App\Models\ConvocatoriaSkill;
use App\Models\Ficha;
use App\Models\Parametro;
use App\Models\ParametroTema;
use App\Models\User;
use Database\Seeders\ParametroSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Paso 1: invariantes de base de datos del módulo de convocatorias.
 * Cubre la estructura definitiva de las 5 tablas y el catálogo sembrado
 * (10 temas / 52 parámetros) con sus reglas: es_defecto único por tema donde
 * aplica, unicidades, restrict de borrado y el nullOnDelete de users.ficha_id.
 */
class TablasParametrosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ParametroSeeder::class);
    }

    private function ficha(string $codigo = '3173334'): Ficha
    {
        return Ficha::create(['codigo' => $codigo, 'nombre_programa' => 'Análisis y Desarrollo de Software']);
    }

    private function parametro(string $tema, string $codigo): Parametro
    {
        $parametro = Parametro::porTemaYCodigo($tema, $codigo);
        $this->assertNotNull($parametro, "Falta el parámetro {$tema}/{$codigo}.");

        return $parametro;
    }

    private function usuario(Role $role, array $extra = []): User
    {
        return User::factory()->create($extra + [
            'role' => $role,
            'active' => true,
            'email_verified_at' => now(),
        ]);
    }

    private function crearConvocatoria(Ficha $ficha, array $overrides = []): Convocatoria
    {
        $datos = array_merge([
            'codigo' => 'CONV-2026-001',
            'nombre' => 'Convocatoria de prueba',
            'cargo' => 'Desarrollador PHP',
            'area' => 'Tecnología',
            'descripcion' => 'Descripción de la convocatoria de prueba.',
            'perfil_requerido' => 'Perfil requerido para el cargo.',
            'vacantes' => 2,
            'salario_min' => 2000000,
            'salario_max' => 3000000,
            'tipo_convocatoria_id' => $this->parametro('tipo_convocatoria', 'externo')->id,
            'tipo_empleo_id' => $this->parametro('tipo_empleo', 'tiempo_completo')->id,
            'tipo_contrato_id' => $this->parametro('tipo_contrato', 'termino_fijo')->id,
            'modalidad_id' => $this->parametro('modalidad', 'presencial')->id,
            'estado_id' => $this->parametro('estado_convocatoria', 'borrador')->id,
            'fecha_apertura' => now()->toDateString(),
            'fecha_cierre' => now()->addDays(30)->toDateString(),
            'ficha_id' => $ficha->id,
            'creado_por' => $this->usuario(Role::Instructor)->id,
        ], $overrides);

        return Convocatoria::unguarded(fn () => Convocatoria::create($datos));
    }

    // ── Columnas y estructura ──────────────────────────────────────────

    public function test_parametros_tiene_es_defecto_con_default_false(): void
    {
        $this->assertTrue(Schema::hasColumn('parametros', 'es_defecto'));

        // Crear un parámetro sin indicar es_defecto: debe quedar en false.
        $tema = ParametroTema::where('codigo', 'tipo_contrato')->firstOrFail();
        $nuevo = Parametro::create([
            'tema_id' => $tema->id,
            'codigo' => 'contrato_aprendizaje',
            'nombre' => 'Contrato de aprendizaje',
            'orden' => 99,
        ]);

        // El default lo aplica MySQL; se lee de la base con fresh().
        $this->assertFalse($nuevo->fresh()->es_defecto);
    }

    public function test_convocatorias_usa_el_esquema_nuevo_con_codigo(): void
    {
        foreach ([
            'ficha_id', 'codigo', 'nombre', 'cargo', 'area', 'descripcion',
            'salario_min', 'salario_max', 'funciones', 'responsabilidades',
            'perfil_requerido', 'formacion', 'experiencia', 'competencias',
            'conocimientos_especificos', 'vacantes', 'tipo_convocatoria_id',
            'tipo_empleo_id', 'tipo_contrato_id', 'modalidad_id', 'estado_id',
            'fecha_apertura', 'fecha_cierre', 'fecha_evaluacion', 'creado_por',
        ] as $columna) {
            $this->assertTrue(Schema::hasColumn('convocatorias', $columna), "Falta la columna {$columna}.");
        }

        // requisitos se renombró a perfil_requerido: no debe existir la vieja.
        $this->assertFalse(Schema::hasColumn('convocatorias', 'requisitos'));
    }

    public function test_convocatorias_campos_exigidos_al_publicar_son_nullable(): void
    {
        $columnas = collect(Schema::getColumns('convocatorias'))->keyBy('name');

        $opcionales = [
            'cargo', 'area', 'descripcion', 'salario_min', 'salario_max',
            'funciones', 'responsabilidades', 'perfil_requerido', 'formacion',
            'experiencia', 'competencias', 'conocimientos_especificos',
            'fecha_apertura', 'fecha_cierre', 'fecha_evaluacion',
        ];

        foreach ($opcionales as $columna) {
            $this->assertTrue($columnas[$columna]['nullable'], "{$columna} debe ser nullable.");
        }

        foreach ([
            'ficha_id', 'codigo', 'nombre', 'vacantes', 'tipo_convocatoria_id',
            'tipo_empleo_id', 'tipo_contrato_id', 'modalidad_id', 'estado_id', 'creado_por',
        ] as $columna) {
            $this->assertFalse($columnas[$columna]['nullable'], "{$columna} NO debe ser nullable.");
        }
    }

    public function test_convocatorias_tiene_unique_ficha_codigo_sin_indice_suelto_ficha(): void
    {
        $indices = collect(Schema::getIndexes('convocatorias'));

        $unico = $indices->first(fn (array $i) => $i['unique']
            && collect($i['columns'])->sort()->values()->all() === ['codigo', 'ficha_id']);
        $this->assertNotNull($unico, 'Falta el UNIQUE(ficha_id, codigo).');

        $suelto = $indices->first(fn (array $i) => ! $i['primary'] && ! $i['unique']
            && $i['columns'] === ['ficha_id']);
        $this->assertNull($suelto, 'No debe existir un índice suelto sobre ficha_id (lo cubre el UNIQUE).');
    }

    public function test_convocatoria_skills_conserva_nombre_y_agrega_parametro_id_nullable(): void
    {
        // La skill sigue teniendo SIEMPRE su nombre (copia visible) y, si vino
        // del catálogo, el parametro_id. La columna es nullable: las skills de
        // texto libre siguen living sin parametro_id.
        $this->assertTrue(Schema::hasColumn('convocatoria_skills', 'nombre'));
        $this->assertTrue(Schema::hasColumn('convocatoria_skills', 'parametro_id'));

        $columnas = collect(Schema::getColumns('convocatoria_skills'))->keyBy('name');
        $this->assertTrue($columnas['parametro_id']['nullable'], 'parametro_id debe ser nullable.');
    }

    public function test_convocatoria_skills_parametro_id_borra_en_null(): void
    {
        // Si la skill se retira del catálogo, la fila se CONSERVA con su
        // nombre: la FK es ON DELETE SET NULL (nunca RESTRICT ni CASCADE).
        $foraneas = collect(Schema::getForeignKeys('convocatoria_skills'))
            ->first(fn (array $fk) => $fk['columns'] === ['parametro_id']);

        $this->assertNotNull($foraneas, 'Falta la FK de convocatoria_skills.parametro_id.');
        $this->assertSame('parametros', $foraneas['foreign_table']);
        $this->assertSame('set null', strtolower((string) $foraneas['on_delete']));
    }

    public function test_postulacion_skills_tiene_parametro_id_nullable(): void
    {
        $this->assertTrue(Schema::hasColumn('postulacion_skills', 'parametro_id'));

        $columnas = collect(Schema::getColumns('postulacion_skills'))->keyBy('name');
        $this->assertTrue($columnas['parametro_id']['nullable'], 'parametro_id debe ser nullable.');
    }

    public function test_convocatoria_documentos_conserva_obligatorio(): void
    {
        $this->assertTrue(Schema::hasColumn('convocatoria_documentos', 'obligatorio'));
    }

    // ── es_defecto por tema (ajuste 3) ─────────────────────────────────

    public function test_solo_existe_un_es_defecto_por_tema_donde_aplica(): void
    {
        $dondeAplica = ['tipo_convocatoria', 'tipo_empleo', 'tipo_contrato', 'modalidad'];

        foreach ($dondeAplica as $tema) {
            $this->assertSame(
                1,
                ParametroTema::where('codigo', $tema)->firstOrFail()->parametros()->where('es_defecto', true)->count(),
                "El tema {$tema} debe tener exactamente UN es_defecto."
            );
        }
    }

    public function test_los_temas_sin_es_defecto_no_tienen_ninguno(): void
    {
        $sinDefecto = ['estado_convocatoria', 'skill_tecnica', 'tipo_documento', 'sexo', 'skill_catalogo'];

        foreach ($sinDefecto as $tema) {
            $this->assertSame(
                0,
                ParametroTema::where('codigo', $tema)->firstOrFail()->parametros()->where('es_defecto', true)->count(),
                "El tema {$tema} NO debe tener es_defecto."
            );
        }
    }

    public function test_el_es_defecto_esperado_es_el_correcto_y_queda_activo(): void
    {
        $esperados = [
            ['tipo_convocatoria', 'externo'],
            ['tipo_empleo', 'tiempo_completo'],
            ['tipo_contrato', 'termino_fijo'],
            ['modalidad', 'presencial'],
        ];

        foreach ($esperados as [$tema, $codigo]) {
            $parametro = $this->parametro($tema, $codigo);
            $this->assertTrue($parametro->es_defecto, "{$tema}/{$codigo} debe ser el es_defecto.");
            $this->assertTrue($parametro->activo, "{$tema}/{$codigo} debe estar activo.");
        }
    }

    // ── Unicidades ─────────────────────────────────────────────────────

    public function test_el_codigo_de_convocatoria_es_unico_por_ficha(): void
    {
        $ficha = $this->ficha();
        $this->crearConvocatoria($ficha);

        $this->expectException(QueryException::class);
        $this->crearConvocatoria($ficha);
    }

    public function test_el_mismo_codigo_si_puede_repetirse_en_fichas_distintas(): void
    {
        $convocatoriaB = $this->crearConvocatoria($this->ficha('2631620'));

        $this->assertSame('CONV-2026-001', $convocatoriaB->fresh()->codigo);
        $this->assertDatabaseCount('convocatorias', 1);
    }

    public function test_una_skill_no_puede_repetirse_en_la_misma_convocatoria(): void
    {
        $convocatoria = $this->crearConvocatoria($this->ficha());

        ConvocatoriaSkill::create(['convocatoria_id' => $convocatoria->id, 'nombre' => 'PHP']);

        $this->expectException(QueryException::class);
        ConvocatoriaSkill::create(['convocatoria_id' => $convocatoria->id, 'nombre' => 'PHP']);
    }

    // ── Restrict de borrado ────────────────────────────────────────────

    public function test_no_se_puede_borrar_una_ficha_con_convocatorias(): void
    {
        $ficha = $this->ficha();
        $this->crearConvocatoria($ficha);

        $this->expectException(QueryException::class);
        $ficha->delete();
    }

    public function test_no_se_puede_borrar_un_parametro_en_uso(): void
    {
        $ficha = $this->ficha();
        $estadoBorrador = $this->parametro('estado_convocatoria', 'borrador');
        $this->crearConvocatoria($ficha);

        $this->expectException(QueryException::class);
        $estadoBorrador->delete();
    }

    // ── users.ficha_id: nullOnDelete (ajuste 4, queda fijado por prueba) ─

    public function test_borrar_una_ficha_nullifica_el_ficha_id_del_usuario(): void
    {
        $ficha = $this->ficha();
        $usuario = $this->usuario(Role::Aspirante, ['ficha_id' => $ficha->id]);

        $ficha->delete();

        $this->assertNull($usuario->fresh()->ficha_id);
    }
}