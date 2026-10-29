<?php

namespace App\Tests\Feature;

use App\Enums\Role;
use App\Models\Convocatoria;
use App\Models\Ficha;
use App\Models\Parametro;
use App\Models\User;
use Database\Seeders\ParametroSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pruebas del Paso 2b: crear, listar, ver y editar convocatorias.
 *
 * Cubren reglas de negocio (ficha única, codigo autogenerado, estado inicial,
 * clasificación por código o id, skills y documentos), aislamiento por ficha,
 * visibilidad del aspirante, edición por estado y traducción de los mensajes
 * de validación del módulo al español.
 */
class ConvocatoriaGestionTest extends TestCase
{
    use RefreshDatabase;

    private static int $codigoSecuencia = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ParametroSeeder::class);
    }

    // ── Utilidades ─────────────────────────────────────────────────────

    private function ficha(string $codigo = '3173334'): Ficha
    {
        return Ficha::create([
            'codigo' => $codigo,
            'nombre_programa' => 'Análisis y Desarrollo de Software',
        ]);
    }

    private function usuario(Role $role, array $extra = []): User
    {
        return User::factory()->create($extra + [
            'role' => $role,
            'subrole' => null,
            'active' => true,
            'email_verified_at' => now(),
        ]);
    }

    /** Instructor con exactamente las fichas indicadas. */
    private function instructor(Ficha ...$fichas): User
    {
        $instructor = $this->usuario(Role::Instructor);
        foreach ($fichas as $ficha) {
            $instructor->fichas()->attach($ficha->id);
        }

        return $instructor->fresh();
    }

    private function aspirante(Ficha $ficha): User
    {
        return $this->usuario(Role::Aspirante, ['ficha_id' => $ficha->id]);
    }

    private function parametro(string $tema, string $codigo): Parametro
    {
        $parametro = Parametro::porTemaYCodigo($tema, $codigo);
        $this->assertNotNull($parametro, "Falta el parámetro {$tema}.{$codigo}");

        return $parametro;
    }

    private function parametroId(string $tema, string $codigo): int
    {
        return (int) $this->parametro($tema, $codigo)->id;
    }

    private function crearConvocatoria(Ficha $ficha, User $creador, array $overrides = []): Convocatoria
    {
        return Convocatoria::unguarded(fn () => Convocatoria::create($overrides + [
            'ficha_id' => $ficha->id,
            'codigo' => sprintf('CONV-TEST-%03d', ++self::$codigoSecuencia),
            'nombre' => 'Convocatoria base',
            'vacantes' => 1,
            'tipo_convocatoria_id' => $this->parametroId('tipo_convocatoria', 'externo'),
            'tipo_empleo_id' => $this->parametroId('tipo_empleo', 'tiempo_completo'),
            'tipo_contrato_id' => $this->parametroId('tipo_contrato', 'termino_fijo'),
            'modalidad_id' => $this->parametroId('modalidad', 'presencial'),
            'estado_id' => $this->parametroId('estado_convocatoria', 'borrador'),
            'creado_por' => $creador->id,
        ]));
    }

    private function codigosDocumentos(array $documentos): array
    {
        return array_column($documentos, 'codigo');
    }

    // ── Crear: reglas de ficha ─────────────────────────────────────────

    public function test_instructor_sin_ficha_no_puede_crear(): void
    {
        $instructor = $this->usuario(Role::Instructor);

        $this->actingAs($instructor)
            ->postJson('/api/convocatorias', ['nombre' => 'Sin ficha'])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['ficha']]);
    }

    public function test_instructor_con_dos_fichas_y_sin_elegir_no_puede_crear(): void
    {
        $instructor = $this->instructor($this->ficha('3173334'), $this->ficha('2631620'));

        $this->actingAs($instructor)
            ->postJson('/api/convocatorias', ['nombre' => 'Dos fichas'])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['ficha']]);
    }

    public function test_instructor_con_dos_fichas_elige_en_cual_crea(): void
    {
        $primera = $this->ficha('3173334');
        $segunda = $this->ficha('2631620');
        $instructor = $this->instructor($primera, $segunda);

        $respuesta = $this->actingAs($instructor)
            ->postJson('/api/convocatorias', ['nombre' => 'Elegida', 'ficha_id' => $segunda->id])
            ->assertCreated();

        $this->assertSame($segunda->id, $respuesta->json('data.ficha.id'));
    }

    public function test_no_se_puede_crear_en_una_ficha_ajena(): void
    {
        $ajena = $this->ficha('2631620');
        $instructor = $this->instructor($this->ficha('3173334'));

        $this->actingAs($instructor)
            ->postJson('/api/convocatorias', ['nombre' => 'Ajena', 'ficha_id' => $ajena->id])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['ficha_id']]);
    }

    public function test_no_se_puede_mover_la_ficha_de_una_convocatoria_al_editar(): void
    {
        $ficha = $this->ficha('3173334');
        $otra = $this->ficha('2631620');
        $instructor = $this->instructor($ficha, $otra);
        $convocatoria = $this->crearConvocatoria($ficha, $instructor);

        $this->actingAs($instructor)
            ->patchJson("/api/convocatorias/{$convocatoria->id}", [
                'nombre' => 'Con ficha cambiada',
                'ficha_id' => $otra->id,
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['campos']]);
    }

    // ── Crear: caso mínimo y defaults ──────────────────────────────────

    public function test_crear_con_solo_nombre_aplica_defaults(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);

        $respuesta = $this->actingAs($instructor)
            ->postJson('/api/convocatorias', ['nombre' => 'Instructor de inglés'])
            ->assertCreated();

        $this->assertSame(sprintf('CONV-%d-001', now('America/Bogota')->year), $respuesta->json('data.codigo'));
        $this->assertSame('borrador', $respuesta->json('data.estado.codigo'));
        $this->assertSame('externo', $respuesta->json('data.tipo_convocatoria.codigo'));
        $this->assertSame('tiempo_completo', $respuesta->json('data.tipo_empleo.codigo'));
        $this->assertSame('termino_fijo', $respuesta->json('data.tipo_contrato.codigo'));
        $this->assertSame('presencial', $respuesta->json('data.modalidad.codigo'));
        $this->assertSame(1, $respuesta->json('data.vacantes'));
        $this->assertSame('3173334', $respuesta->json('data.ficha.codigo'));

        // Los obligatorios se agregan solos aunque no se envíen.
        $this->assertEqualsCanonicalizing(
            ['hoja_de_vida', 'documento_identidad', 'certificados_academicos', 'certificados_laborales'],
            $this->codigosDocumentos($respuesta->json('data.documentos')),
        );
        foreach ($respuesta->json('data.documentos') as $documento) {
            $this->assertTrue($documento['obligatorio']);
        }
    }

    public function test_crear_con_cuerpo_completo(): void
    {
        $instructor = $this->instructor($this->ficha());

        $respuesta = $this->actingAs($instructor)->postJson('/api/convocatorias', [
            'nombre' => 'Instructor de inglés',
            'cargo' => 'Instructor',
            'area' => 'Bilingüismo',
            'descripcion' => 'Buscamos instructor con nivel B2.',
            'vacantes' => 2,
            'salario_min' => 3500000,
            'salario_max' => 4200000,
            'funciones' => 'Dictar formación técnica.',
            'responsabilidades' => 'Planear y ejecutar sesiones.',
            'perfil_requerido' => 'Profesional en idiomas.',
            'formacion' => 'Profesional',
            'experiencia' => '2 años',
            'competencias' => 'Comunicación',
            'conocimientos_especificos' => 'Inglés B2',
            'tipo_convocatoria' => 'interno',
            'tipo_empleo' => 'medio_tiempo',
            'tipo_contrato' => 'prestacion_servicios',
            'modalidad' => 'remoto',
            'fecha_apertura' => '2026-10-15',
            'fecha_cierre' => '2026-11-15',
            'skills' => ['Excel avanzado', 'Power BI'],
            'documentos' => ['documento_identidad'],
        ])->assertCreated();

        $respuesta->assertJsonStructure(['data' => [
            'id', 'codigo', 'ficha' => ['id', 'codigo', 'nombre_programa'],
            'nombre', 'cargo', 'area', 'descripcion', 'estado' => ['id', 'codigo', 'nombre'],
            'vacantes', 'salario_min', 'salario_max',
            'tipo_convocatoria' => ['id', 'codigo', 'nombre'],
            'tipo_empleo' => ['id', 'codigo', 'nombre'],
            'tipo_contrato' => ['id', 'codigo', 'nombre'],
            'modalidad' => ['id', 'codigo', 'nombre'],
            'funciones', 'responsabilidades', 'perfil_requerido', 'formacion', 'experiencia',
            'competencias', 'conocimientos_especificos',
            'fecha_apertura', 'fecha_cierre', 'fecha_evaluacion', 'skills', 'documentos',
        ]]);

        $this->assertEquals(3500000.0, $respuesta->json('data.salario_min'));
        $this->assertSame('2026-10-15', $respuesta->json('data.fecha_apertura'));
        $this->assertSame('2026-11-15', $respuesta->json('data.fecha_cierre'));
        $this->assertSame('interno', $respuesta->json('data.tipo_convocatoria.codigo'));
        $this->assertSame('medio_tiempo', $respuesta->json('data.tipo_empleo.codigo'));
        $this->assertSame('prestacion_servicios', $respuesta->json('data.tipo_contrato.codigo'));
        $this->assertSame('remoto', $respuesta->json('data.modalidad.codigo'));
        $this->assertSame(['Excel avanzado', 'Power BI'], $respuesta->json('data.skills'));

        $documentos = $respuesta->json('data.documentos');
        $this->assertCount(4, $documentos);
        $this->assertEqualsCanonicalizing(
            ['hoja_de_vida', 'documento_identidad', 'certificados_academicos', 'certificados_laborales'],
            $this->codigosDocumentos($documentos),
        );
        foreach ($documentos as $documento) {
            $this->assertTrue($documento['obligatorio']);
        }
    }

    public function test_crear_con_clasificacion_por_id(): void
    {
        $instructor = $this->instructor($this->ficha());

        $respuesta = $this->actingAs($instructor)->postJson('/api/convocatorias', [
            'nombre' => 'Con id',
            'tipo_empleo_id' => $this->parametroId('tipo_empleo', 'por_horas'),
        ])->assertCreated();

        $this->assertSame('por_horas', $respuesta->json('data.tipo_empleo.codigo'));
    }

    public function test_codigo_es_secuencial_por_ficha_y_anio(): void
    {
        $fichaA = $this->ficha('3173334');
        $fichaB = $this->ficha('2631620');
        $instructorA = $this->instructor($fichaA);
        $instructorB = $this->instructor($fichaB);

        $anio = now('America/Bogota')->year;

        $primera = $this->actingAs($instructorA)->postJson('/api/convocatorias', ['nombre' => 'A1'])->assertCreated();
        $segunda = $this->actingAs($instructorA)->postJson('/api/convocatorias', ['nombre' => 'A2'])->assertCreated();
        $otraFicha = $this->actingAs($instructorB)->postJson('/api/convocatorias', ['nombre' => 'B1'])->assertCreated();

        $this->assertSame("CONV-{$anio}-001", $primera->json('data.codigo'));
        $this->assertSame("CONV-{$anio}-002", $segunda->json('data.codigo'));
        $this->assertSame("CONV-{$anio}-001", $otraFicha->json('data.codigo'));
    }

    // ── Crear: validaciones ────────────────────────────────────────────

    public function test_nombre_obligatorio_en_espanol(): void
    {
        $instructor = $this->instructor($this->ficha());

        $this->actingAs($instructor)
            ->postJson('/api/convocatorias', [])
            ->assertStatus(422)
            ->assertJsonPath('errors.nombre.0', 'El campo nombre es obligatorio.');
    }

    public function test_rechaza_campos_reservados(): void
    {
        $instructor = $this->instructor($this->ficha());

        $this->actingAs($instructor)
            ->postJson('/api/convocatorias', ['nombre' => 'X', 'codigo' => 'CONV-2026-999'])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['campos']]);
    }

    public function test_rechaza_clasificacion_invalida(): void
    {
        $instructor = $this->instructor($this->ficha());

        $this->actingAs($instructor)
            ->postJson('/api/convocatorias', ['nombre' => 'X', 'tipo_empleo' => 'no_existe'])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['tipo_empleo']]);
    }

    public function test_rechaza_codigo_e_id_juntos(): void
    {
        $instructor = $this->instructor($this->ficha());

        $this->actingAs($instructor)->postJson('/api/convocatorias', [
            'nombre' => 'X',
            'tipo_empleo' => 'medio_tiempo',
            'tipo_empleo_id' => $this->parametroId('tipo_empleo', 'tiempo_completo'),
        ])->assertStatus(422)->assertJsonStructure(['errors' => ['tipo_empleo']]);
    }

    public function test_rechaza_salario_max_menor_que_min(): void
    {
        $instructor = $this->instructor($this->ficha());

        $this->actingAs($instructor)->postJson('/api/convocatorias', [
            'nombre' => 'X',
            'salario_min' => 5000000,
            'salario_max' => 4000000,
        ])->assertStatus(422)->assertJsonStructure(['errors' => ['salario_max']]);
    }

    public function test_rechaza_fecha_cierre_anterior_a_apertura(): void
    {
        $instructor = $this->instructor($this->ficha());

        $this->actingAs($instructor)->postJson('/api/convocatorias', [
            'nombre' => 'X',
            'fecha_apertura' => '2026-11-15',
            'fecha_cierre' => '2026-10-15',
        ])->assertStatus(422)->assertJsonStructure(['errors' => ['fecha_cierre']]);
    }

    public function test_skills_se_recortan_y_se_descartan_las_vacias(): void
    {
        $instructor = $this->instructor($this->ficha());

        // Se recortan los espacios sobrantes y las vacías se descartan.
        $respuesta = $this->actingAs($instructor)->postJson('/api/convocatorias', [
            'nombre' => 'X',
            'skills' => ['  Excel  ', '', 'Power  BI'],
        ])->assertCreated();

        $this->assertSame(['Excel', 'Power BI'], $respuesta->json('data.skills'));
    }

    public function test_skills_repetidas_por_mayusculas_devuelve_422(): void
    {
        $instructor = $this->instructor($this->ficha());

        // Una skill repetida (sin distinguir mayúsculas ni espacios sobrantes)
        // es un error: no se guarda en silencio.
        $this->actingAs($instructor)
            ->postJson('/api/convocatorias', [
                'nombre' => 'X',
                'skills' => ['  Excel  ', 'excel', 'Power BI'],
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.skills.0', 'No puedes repetir la misma skill.');

        $this->assertSame(0, Convocatoria::count());
    }

    public function test_rechaza_mas_de_20_skills(): void
    {
        $instructor = $this->instructor($this->ficha());

        $skills = array_map(fn (int $i) => "Skill {$i}", range(1, 21));

        $this->actingAs($instructor)
            ->postJson('/api/convocatorias', ['nombre' => 'X', 'skills' => $skills])
            ->assertStatus(422)
            ->assertJsonPath('errors.skills.0', 'No puedes registrar más de 20 skills.');
    }

    public function test_rechaza_skill_demasiado_larga(): void
    {
        $instructor = $this->instructor($this->ficha());

        $this->actingAs($instructor)->postJson('/api/convocatorias', [
            'nombre' => 'X',
            'skills' => [str_repeat('a', 101)],
        ])->assertStatus(422)->assertJsonStructure(['errors' => ['skills.0']]);
    }

    public function test_rechaza_documento_invalido(): void
    {
        $instructor = $this->instructor($this->ficha());

        $this->actingAs($instructor)->postJson('/api/convocatorias', [
            'nombre' => 'X',
            'documentos' => ['no_existe'],
        ])->assertStatus(422)->assertJsonStructure(['errors' => ['documentos.0']]);
    }

    // ── Listado ────────────────────────────────────────────────────────

    public function test_listado_paginado_y_ordenado(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);

        $this->crearConvocatoria($ficha, $instructor, ['codigo' => 'C-1', 'nombre' => 'Uno', 'fecha_apertura' => '2026-10-01']);
        $this->crearConvocatoria($ficha, $instructor, ['codigo' => 'C-2', 'nombre' => 'Dos', 'fecha_apertura' => '2026-10-03']);
        $this->crearConvocatoria($ficha, $instructor, ['codigo' => 'C-3', 'nombre' => 'Tres', 'fecha_apertura' => '2026-10-02']);

        $respuesta = $this->actingAs($instructor)
            ->getJson('/api/convocatorias?per_page=2&page=1')
            ->assertOk();

        $this->assertCount(2, $respuesta->json('data'));
        $this->assertSame('Dos', $respuesta->json('data.0.nombre'));
        $this->assertSame('Tres', $respuesta->json('data.1.nombre'));
        $this->assertSame(3, $respuesta->json('meta.total'));
        $this->assertSame(2, $respuesta->json('meta.last_page'));
        $this->assertSame(2, $respuesta->json('meta.per_page'));
    }

    public function test_listado_no_muestra_otras_fichas(): void
    {
        $fichaA = $this->ficha('3173334');
        $fichaB = $this->ficha('2631620');
        $instructorA = $this->instructor($fichaA);
        $instructorB = $this->instructor($fichaB);

        $this->crearConvocatoria($fichaA, $instructorA, ['codigo' => 'A-1']);
        $this->crearConvocatoria($fichaB, $instructorB, ['codigo' => 'B-1']);

        $respuesta = $this->actingAs($instructorA)->getJson('/api/convocatorias')->assertOk();

        $this->assertCount(1, $respuesta->json('data'));
        $this->assertSame('A-1', $respuesta->json('data.0.codigo'));
    }

    public function test_aspirante_solo_ve_publicadas(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);

        $this->crearConvocatoria($ficha, $instructor, ['codigo' => 'BOR-1', 'nombre' => 'Borrador']);
        $this->crearConvocatoria($ficha, $instructor, [
            'codigo' => 'PUB-1',
            'nombre' => 'Publicada',
            'estado_id' => $this->parametroId('estado_convocatoria', 'publicada'),
        ]);

        $respuesta = $this->actingAs($aspirante)->getJson('/api/convocatorias')->assertOk();

        $this->assertCount(1, $respuesta->json('data'));
        // El código (CONV-AAAA-NNN) es un dato de gestión: el aspiring candidate no lo recibe.
        $this->assertNull($respuesta->json('data.0.codigo'));
    }

    public function test_listado_filtra_por_estado(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);

        $this->crearConvocatoria($ficha, $instructor, ['codigo' => 'BOR-1']);
        $this->crearConvocatoria($ficha, $instructor, [
            'codigo' => 'PUB-1',
            'estado_id' => $this->parametroId('estado_convocatoria', 'publicada'),
        ]);

        $respuesta = $this->actingAs($instructor)
            ->getJson('/api/convocatorias?estado=publicada')
            ->assertOk();

        $this->assertCount(1, $respuesta->json('data'));
        $this->assertSame('PUB-1', $respuesta->json('data.0.codigo'));
    }

    public function test_listado_estado_invalido(): void
    {
        $instructor = $this->instructor($this->ficha());

        $this->actingAs($instructor)
            ->getJson('/api/convocatorias?estado=no_existe')
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['estado']]);
    }

    public function test_listado_filtra_por_ficha(): void
    {
        $fichaA = $this->ficha('3173334');
        $fichaB = $this->ficha('2631620');
        // El instructor tiene las dos fichas: el filtro elige entre ambas.
        $instructor = $this->instructor($fichaA, $fichaB);

        $this->crearConvocatoria($fichaA, $instructor, ['codigo' => 'A-1']);
        $this->crearConvocatoria($fichaB, $instructor, ['codigo' => 'B-1']);

        $respuesta = $this->actingAs($instructor)
            ->getJson('/api/convocatorias?ficha_id='.$fichaB->id)
            ->assertOk();

        $this->assertCount(1, $respuesta->json('data'));
        $this->assertSame('B-1', $respuesta->json('data.0.codigo'));
    }

    public function test_listado_filtro_por_ficha_no_amplia_el_alcance(): void
    {
        $fichaA = $this->ficha('3173334');
        $fichaB = $this->ficha('2631620');
        // El instructor solo tiene la ficha A: pedir la B no revela nada.
        $instructor = $this->instructor($fichaA);

        $this->crearConvocatoria($fichaA, $instructor, ['codigo' => 'A-1']);

        $respuesta = $this->actingAs($instructor)
            ->getJson('/api/convocatorias?ficha_id='.$fichaB->id)
            ->assertOk();

        $this->assertCount(0, $respuesta->json('data'));
    }

    public function test_listado_ficha_invalida(): void
    {
        $instructor = $this->instructor($this->ficha());

        $this->actingAs($instructor)
            ->getJson('/api/convocatorias?ficha_id=999999')
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['ficha_id']]);
    }

    public function test_listado_per_page_fuera_de_rango(): void
    {
        $instructor = $this->instructor($this->ficha());

        $this->actingAs($instructor)
            ->getJson('/api/convocatorias?per_page=200')
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['per_page']]);
    }

    // ── Detalle ────────────────────────────────────────────────────────

    public function test_detalle_completo_incluye_skills_y_documentos(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);

        $creada = $this->actingAs($instructor)->postJson('/api/convocatorias', [
            'nombre' => 'Detalle',
            'skills' => ['Excel avanzado'],
        ])->assertCreated();

        $respuesta = $this->actingAs($instructor)
            ->getJson('/api/convocatorias/'.$creada->json('data.id'))
            ->assertOk();

        $respuesta->assertJsonStructure(['data' => [
            'id', 'codigo', 'ficha', 'nombre', 'cargo', 'area', 'descripcion', 'estado',
            'vacantes', 'salario_min', 'salario_max', 'tipo_convocatoria', 'tipo_empleo',
            'tipo_contrato', 'modalidad', 'funciones', 'responsabilidades', 'perfil_requerido',
            'formacion', 'experiencia', 'competencias', 'conocimientos_especificos',
            'fecha_apertura', 'fecha_cierre', 'fecha_evaluacion', 'skills', 'documentos',
        ]]);
        $this->assertSame(['Excel avanzado'], $respuesta->json('data.skills'));
    }

    public function test_detalle_fuera_de_alcance_responde_404(): void
    {
        $fichaA = $this->ficha('3173334');
        $fichaB = $this->ficha('2631620');
        $instructorA = $this->instructor($fichaA);
        $instructorB = $this->instructor($fichaB);
        $ajena = $this->crearConvocatoria($fichaB, $instructorB, ['codigo' => 'B-1']);

        $this->actingAs($instructorA)
            ->getJson('/api/convocatorias/'.$ajena->id)
            ->assertNotFound();
    }

    public function test_aspirante_no_ve_detalle_de_borrador(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $borrador = $this->crearConvocatoria($ficha, $instructor, ['codigo' => 'BOR-1']);

        $this->actingAs($aspirante)
            ->getJson('/api/convocatorias/'.$borrador->id)
            ->assertNotFound();
    }

    public function test_aspirante_ve_detalle_de_publicada(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $publicada = $this->crearConvocatoria($ficha, $instructor, [
            'codigo' => 'PUB-1',
            'estado_id' => $this->parametroId('estado_convocatoria', 'publicada'),
        ]);

        // El Aspirante ve el detalle, pero NO el código: es un dato de gestión.
        $this->actingAs($aspirante)
            ->getJson('/api/convocatorias/'.$publicada->id)
            ->assertOk()
            ->assertJsonPath('data.codigo', null)
            ->assertJsonPath('data.id', $publicada->id);

        // Quien sí puede gestionar lo recibe.
        $this->actingAs($instructor)
            ->getJson('/api/convocatorias/'.$publicada->id)
            ->assertOk()
            ->assertJsonPath('data.codigo', 'PUB-1');
    }

    // ── Edición ────────────────────────────────────────────────────────

    public function test_edicion_parcial_en_borrador_conserva_lo_no_enviado(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->crearConvocatoria($ficha, $instructor, ['nombre' => 'Original', 'cargo' => 'Instructor']);

        $respuesta = $this->actingAs($instructor)
            ->patchJson('/api/convocatorias/'.$convocatoria->id, ['nombre' => 'Actualizado'])
            ->assertOk();

        $this->assertSame('Actualizado', $respuesta->json('data.nombre'));
        $this->assertSame('Instructor', $respuesta->json('data.cargo'));
    }

    public function test_edicion_en_publicada_permite_todo(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->crearConvocatoria($ficha, $instructor, [
            'estado_id' => $this->parametroId('estado_convocatoria', 'publicada'),
            'nombre' => 'Publicada',
        ]);

        $respuesta = $this->actingAs($instructor)->patchJson('/api/convocatorias/'.$convocatoria->id, [
            'nombre' => 'Publicada editada',
            'salario_max' => 9000000,
        ])->assertOk();

        $this->assertSame('Publicada editada', $respuesta->json('data.nombre'));
        $this->assertEquals(9000000.0, $respuesta->json('data.salario_max'));
        $this->assertSame('publicada', $respuesta->json('data.estado.codigo'));
    }

    public function test_edicion_en_proceso_responde_422(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->crearConvocatoria($ficha, $instructor, [
            'estado_id' => $this->parametroId('estado_convocatoria', 'en_proceso'),
        ]);

        $this->actingAs($instructor)
            ->patchJson('/api/convocatorias/'.$convocatoria->id, ['nombre' => 'X'])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['estado']]);
    }

    public function test_edicion_en_cerrada_responde_422(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->crearConvocatoria($ficha, $instructor, [
            'estado_id' => $this->parametroId('estado_convocatoria', 'cerrada'),
        ]);

        $this->actingAs($instructor)
            ->patchJson('/api/convocatorias/'.$convocatoria->id, ['nombre' => 'X'])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['estado']]);
    }

    public function test_edicion_rechaza_reservados(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->crearConvocatoria($ficha, $instructor);

        $this->actingAs($instructor)
            ->patchJson('/api/convocatorias/'.$convocatoria->id, ['codigo' => 'OTRO'])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['campos']]);

        $this->assertSame($convocatoria->codigo, $convocatoria->fresh()->codigo);
    }

    public function test_edicion_reemplaza_skills(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->crearConvocatoria($ficha, $instructor);
        $convocatoria->skills()->create(['nombre' => 'Antigua']);

        $respuesta = $this->actingAs($instructor)
            ->patchJson('/api/convocatorias/'.$convocatoria->id, ['skills' => []])
            ->assertOk();

        $this->assertSame([], $respuesta->json('data.skills'));
        $this->assertSame(0, $convocatoria->fresh()->skills()->count());
    }

    public function test_edicion_reemplaza_documentos_opcionales_y_conserva_obligatorios(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);

        $creada = $this->actingAs($instructor)->postJson('/api/convocatorias', [
            'nombre' => 'Docs',
            'documentos' => ['documento_identidad'],
        ])->assertCreated();

        $respuesta = $this->actingAs($instructor)->patchJson('/api/convocatorias/'.$creada->json('data.id'), [
            'documentos' => ['libreta_militar'],
        ])->assertOk();

        // Los obligatorios se mantienen y se suma el opcional elegido.
        $this->assertEqualsCanonicalizing(
            ['hoja_de_vida', 'documento_identidad', 'certificados_academicos', 'certificados_laborales', 'libreta_militar'],
            $this->codigosDocumentos($respuesta->json('data.documentos')),
        );
        $this->assertSame(5, Convocatoria::find($creada->json('data.id'))->documentos()->count());
    }

    public function test_documentos_obligatorios_se_agregan_siempre(): void
    {
        $instructor = $this->instructor($this->ficha());

        // Aunque el gestor no seleccione nada, los obligatorios siempre van.
        $sinDocs = $this->actingAs($instructor)->postJson('/api/convocatorias', [
            'nombre' => 'Sin documentos',
        ])->assertCreated();

        $this->assertEqualsCanonicalizing(
            ['hoja_de_vida', 'documento_identidad', 'certificados_academicos', 'certificados_laborales'],
            $this->codigosDocumentos($sinDocs->json('data.documentos')),
        );
        $this->assertTrue(
            collect($sinDocs->json('data.documentos'))->every(fn ($d) => $d['obligatorio'] === true)
        );

        // Si el gestor los reenvía no se duplican ni cambian el resultado.
        $conDocs = $this->actingAs($instructor)->postJson('/api/convocatorias', [
            'nombre' => 'Con documentos',
            'documentos' => ['hoja_de_vida', 'certificados_laborales'],
        ])->assertCreated();

        $this->assertEqualsCanonicalizing(
            ['hoja_de_vida', 'documento_identidad', 'certificados_academicos', 'certificados_laborales'],
            $this->codigosDocumentos($conDocs->json('data.documentos')),
        );
    }

    public function test_documentos_opcionales_quedan_obligatorios_al_incluirlos(): void
    {
        $instructor = $this->instructor($this->ficha());

        // libreta_militar y tarjeta_profesional se agregan solo si el gestor las
        // incluye, y quedan como obligatorias (regla de config/convocatorias.php).
        $respuesta = $this->actingAs($instructor)->postJson('/api/convocatorias', [
            'nombre' => 'Con libreta y tarjeta',
            'documentos' => ['libreta_militar', 'tarjeta_profesional'],
        ])->assertCreated();

        $documentos = collect($respuesta->json('data.documentos'))->keyBy('codigo');

        $this->assertTrue($documentos['libreta_militar']['obligatorio']);
        $this->assertTrue($documentos['tarjeta_profesional']['obligatorio']);

        // Los obligatorios fijos siguen presentes.
        $this->assertEqualsCanonicalizing(
            [
                'hoja_de_vida', 'documento_identidad', 'certificados_academicos',
                'certificados_laborales', 'libreta_militar', 'tarjeta_profesional',
            ],
            $this->codigosDocumentos($respuesta->json('data.documentos')),
        );
    }

    public function test_edicion_no_puede_quitar_los_obligatorios(): void
    {
        $instructor = $this->instructor($this->ficha());

        $creada = $this->actingAs($instructor)->postJson('/api/convocatorias', [
            'nombre' => 'Docs',
            'documentos' => ['hoja_de_vida', 'certificados_laborales'],
        ])->assertCreated();

        // El gestor envía una lista vacía, pero los obligatorios se reinyectan.
        $respuesta = $this->actingAs($instructor)->patchJson('/api/convocatorias/'.$creada->json('data.id'), [
            'documentos' => [],
        ])->assertOk();

        $this->assertEqualsCanonicalizing(
            ['hoja_de_vida', 'documento_identidad', 'certificados_academicos', 'certificados_laborales'],
            $this->codigosDocumentos($respuesta->json('data.documentos')),
        );
        $this->assertSame(4, Convocatoria::find($creada->json('data.id'))->documentos()->count());
    }

    public function test_edicion_valida_salario_contra_el_valor_actual(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->crearConvocatoria($ficha, $instructor, ['salario_min' => 5000000]);

        $this->actingAs($instructor)
            ->patchJson('/api/convocatorias/'.$convocatoria->id, ['salario_max' => 1000000])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['salario_max']]);
    }

    public function test_edicion_nombre_vacio_responde_422(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->crearConvocatoria($ficha, $instructor);

        $this->actingAs($instructor)
            ->patchJson('/api/convocatorias/'.$convocatoria->id, ['nombre' => ''])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['nombre']]);
    }

    public function test_edicion_fuera_de_alcance_responde_404(): void
    {
        $fichaA = $this->ficha('3173334');
        $fichaB = $this->ficha('2631620');
        $instructorA = $this->instructor($fichaA);
        $instructorB = $this->instructor($fichaB);
        $ajena = $this->crearConvocatoria($fichaB, $instructorB, ['codigo' => 'B-1']);

        $this->actingAs($instructorA)
            ->patchJson('/api/convocatorias/'.$ajena->id, ['nombre' => 'X'])
            ->assertNotFound();
    }

    public function test_aspirante_no_puede_editar(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->crearConvocatoria($ficha, $instructor);

        $this->actingAs($aspirante)
            ->patchJson('/api/convocatorias/'.$convocatoria->id, ['nombre' => 'X'])
            ->assertForbidden();
    }

    // ── Retiro del endpoint de fichas ──────────────────────────────────

    public function test_endpoint_de_fichas_retirado(): void
    {
        $instructor = $this->instructor($this->ficha());

        $this->actingAs($instructor)->getJson('/api/convocatorias/fichas')->assertNotFound();
    }

    // ── Buscador ?q= ───────────────────────────────────────────────────

    public function test_buscador_por_nombre_ignora_mayusculas_y_espacios(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $this->crearConvocatoria($ficha, $instructor, ['codigo' => 'C-1', 'nombre' => 'Instructor de inglés']);
        $this->crearConvocatoria($ficha, $instructor, ['codigo' => 'C-2', 'nombre' => 'Coordinador académico']);

        $respuesta = $this->actingAs($instructor)
            ->getJson('/api/convocatorias?q='.urlencode('  INSTRUCTOR  '))
            ->assertOk();

        $this->assertSame(['C-1'], array_column($respuesta->json('data'), 'codigo'));
    }

    public function test_buscador_por_codigo(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $this->crearConvocatoria($ficha, $instructor, ['codigo' => 'CONV-2026-500', 'nombre' => 'Alpha']);
        $this->crearConvocatoria($ficha, $instructor, ['codigo' => 'CONV-2026-501', 'nombre' => 'Beta']);

        $respuesta = $this->actingAs($instructor)
            ->getJson('/api/convocatorias?q=2026-500')
            ->assertOk();

        $this->assertSame(['CONV-2026-500'], array_column($respuesta->json('data'), 'codigo'));
    }

    public function test_buscador_por_cargo(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $this->crearConvocatoria($ficha, $instructor, ['codigo' => 'C-1', 'nombre' => 'A', 'cargo' => 'Instructor']);
        $this->crearConvocatoria($ficha, $instructor, ['codigo' => 'C-2', 'nombre' => 'B', 'cargo' => 'Coordinador']);

        $respuesta = $this->actingAs($instructor)
            ->getJson('/api/convocatorias?q=coord')
            ->assertOk();

        $this->assertSame(['C-2'], array_column($respuesta->json('data'), 'codigo'));
    }

    public function test_buscador_se_combina_con_estado(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $this->crearConvocatoria($ficha, $instructor, ['codigo' => 'MD-1', 'nombre' => 'Docente']);
        $this->crearConvocatoria($ficha, $instructor, [
            'codigo' => 'MD-2',
            'nombre' => 'Docente',
            'estado_id' => $this->parametroId('estado_convocatoria', 'publicada'),
        ]);

        $respuesta = $this->actingAs($instructor)
            ->getJson('/api/convocatorias?q=Docente&estado=publicada')
            ->assertOk();

        $this->assertSame(['MD-2'], array_column($respuesta->json('data'), 'codigo'));
    }

    public function test_buscador_sin_resultados_devuelve_meta_correcta(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $this->crearConvocatoria($ficha, $instructor, ['codigo' => 'C-1', 'nombre' => 'Alpha']);

        $respuesta = $this->actingAs($instructor)
            ->getJson('/api/convocatorias?q=zzzznoexiste')
            ->assertOk();

        $this->assertSame([], $respuesta->json('data'));
        $this->assertSame(0, $respuesta->json('meta.total'));
        $this->assertSame(1, $respuesta->json('meta.last_page'));
        $this->assertSame(1, $respuesta->json('meta.current_page'));
        $this->assertSame(20, $respuesta->json('meta.per_page'));
    }

    public function test_buscador_de_aspirante_no_ve_borradores(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $this->crearConvocatoria($ficha, $instructor, ['codigo' => 'BX-1', 'nombre' => 'BusquedaX']);
        $this->crearConvocatoria($ficha, $instructor, [
            'codigo' => 'BX-2',
            'nombre' => 'BusquedaX',
            'estado_id' => $this->parametroId('estado_convocatoria', 'publicada'),
        ]);

        $respuesta = $this->actingAs($aspirante)
            ->getJson('/api/convocatorias?q=BusquedaX')
            ->assertOk();

        // Solo ve la publicada (y sin código: es un dato de gestión).
        $this->assertCount(1, $respuesta->json('data'));
        $this->assertNull($respuesta->json('data.0.codigo'));
        $this->assertSame('BusquedaX', $respuesta->json('data.0.nombre'));
    }

    public function test_buscador_por_codigo_solo_lo_usa_quien_gestiona(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $this->crearConvocatoria($ficha, $instructor, [
            'codigo' => 'BUS-9',
            'nombre' => 'Nombre normal',
            'estado_id' => $this->parametroId('estado_convocatoria', 'publicada'),
        ]);

        // Quien gestiona: el código es un criterio de búsqueda válido.
        $delGestor = $this->actingAs($instructor)
            ->getJson('/api/convocatorias?q=BUS-9')
            ->assertOk();
        $this->assertCount(1, $delGestor->json('data'));
        $this->assertSame('BUS-9', $delGestor->json('data.0.codigo'));

        // El Aspirante no busca por código (ni lo recibe): 0 resultados.
        $delAspirante = $this->actingAs($aspirante)
            ->getJson('/api/convocatorias?q=BUS-9')
            ->assertOk();
        $this->assertSame([], $delAspirante->json('data'));
    }

    public function test_buscador_no_cruza_fichas(): void
    {
        $fichaA = $this->ficha('3173334');
        $fichaB = $this->ficha('2631620');
        $instructorA = $this->instructor($fichaA);
        $instructorB = $this->instructor($fichaB);
        $this->crearConvocatoria($fichaB, $instructorB, ['codigo' => 'FB-1', 'nombre' => 'Secreto Ficha B']);

        $respuesta = $this->actingAs($instructorA)
            ->getJson('/api/convocatorias?q=Secreto')
            ->assertOk();

        $this->assertSame([], $respuesta->json('data'));
    }

    public function test_buscador_trata_comodines_como_texto_literal(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $this->crearConvocatoria($ficha, $instructor, ['codigo' => 'W-1', 'nombre' => '100% efectivo']);
        $this->crearConvocatoria($ficha, $instructor, ['codigo' => 'W-2', 'nombre' => '100X efectivo']);
        $this->crearConvocatoria($ficha, $instructor, ['codigo' => 'W-3', 'nombre' => 'soporte_n1']);
        $this->crearConvocatoria($ficha, $instructor, ['codigo' => 'W-4', 'nombre' => 'soporteXn1']);

        $porcentaje = $this->actingAs($instructor)
            ->getJson('/api/convocatorias?'.http_build_query(['q' => '100%']))
            ->assertOk();
        $this->assertSame(['W-1'], array_column($porcentaje->json('data'), 'codigo'));

        $underscore = $this->actingAs($instructor)
            ->getJson('/api/convocatorias?'.http_build_query(['q' => 'soporte_n1']))
            ->assertOk();
        $this->assertSame(['W-3'], array_column($underscore->json('data'), 'codigo'));
    }
}
