<?php

namespace App\Tests\Feature;

use App\Enums\Role;
use App\Models\Convocatoria;
use App\Models\ConvocatoriaSkill;
use App\Models\Ficha;
use App\Models\Parametro;
use App\Models\Postulacion;
use App\Models\User;
use Database\Seeders\ParametroSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Skills de la convocatoria ligadas al CATÁLOGO (tema skill_catalogo) y
 * visibilidad de la ficha.
 *
 * - La convocatoria guarda, además del nombre, el parametro_id de la skill
 *   elegida (FK SET NULL: si la skill se retira del catálogo, la fila sigue).
 * - Sigue aceptando texto libre (parametro_id NULL).
 * - La ficha de la convocatoria SOLO la ven el instructor y el super admin:
 *   ni el aspirante ni el subrol gestor_convocatorias.
 * - El conteo de postulantes sigue siendo solo de quien tiene
 *   convocatorias:gestionar.
 */
class ConvocatoriaSkillsCatalogoTest extends TestCase
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

    /** El subrol gestor_convocatorias es un APRENDIZ con ese subrol. */
    private function gestorConvocatorias(Ficha $ficha): User
    {
        return $this->usuario(Role::Aprendiz, [
            'subrole' => 'gestor_convocatorias',
            'ficha_id' => $ficha->id,
        ]);
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
            'codigo' => sprintf('CONV-CAT-%03d', ++self::$codigoSecuencia),
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

    private function publicada(Convocatoria $convocatoria): Convocatoria
    {
        $convocatoria->forceFill([
            'estado_id' => $this->parametroId('estado_convocatoria', 'publicada'),
            'fecha_apertura' => now('America/Bogota')->subDay()->toDateString(),
            'fecha_cierre' => now('America/Bogota')->addDay()->toDateString(),
        ])->save();

        return $convocatoria->fresh();
    }

    // ── Skills del catálogo ────────────────────────────────────────────

    public function test_una_skill_del_catalogo_se_guarda_con_su_parametro_id(): void
    {
        $instructor = $this->instructor($this->ficha());
        $excel = $this->parametro('skill_catalogo', 'excel');

        $respuesta = $this->actingAs($instructor)->postJson('/api/convocatorias', [
            'nombre' => 'Con skills de catálogo',
            'skills' => [
                ['nombre' => 'Excel', 'parametro_id' => $excel->id],
            ],
        ])->assertCreated();

        // El nombre lo pone el catálogo, no el cliente.
        $this->assertSame(['Excel'], $respuesta->json('data.skills'));

        $detalle = $respuesta->json('data.skills_detalle');
        $this->assertCount(1, $detalle);
        $this->assertSame('Excel', $detalle[0]['nombre']);
        $this->assertSame((int) $excel->id, $detalle[0]['parametro_id']);
        $this->assertIsInt($detalle[0]['id']);

        $guardada = ConvocatoriaSkill::sole();
        $this->assertSame('Excel', $guardada->nombre);
        $this->assertSame((int) $excel->id, (int) $guardada->parametro_id);
    }

    public function test_el_nombre_enviado_por_el_cliente_se_ignora_si_viene_del_catalogo(): void
    {
        $instructor = $this->instructor($this->ficha());
        $excel = $this->parametro('skill_catalogo', 'excel');

        $respuesta = $this->actingAs($instructor)->postJson('/api/convocatorias', [
            'nombre' => 'Nombre inventado',
            'skills' => [
                ['nombre' => 'Nombre inventado', 'parametro_id' => $excel->id],
            ],
        ])->assertCreated();

        $this->assertSame(['Excel'], $respuesta->json('data.skills'));
    }

    public function test_una_skill_de_catalogo_y_otra_libre_conviven(): void
    {
        $instructor = $this->instructor($this->ficha());
        $excel = $this->parametro('skill_catalogo', 'excel');

        $respuesta = $this->actingAs($instructor)->postJson('/api/convocatorias', [
            'nombre' => 'Mixta',
            'skills' => [
                ['nombre' => 'Excel', 'parametro_id' => $excel->id],
                ['nombre' => 'SAP HR'],
            ],
        ])->assertCreated();

        $this->assertSame(['Excel', 'SAP HR'], $respuesta->json('data.skills'));

        $detalle = collect($respuesta->json('data.skills_detalle'))->keyBy('nombre');
        $this->assertSame((int) $excel->id, $detalle['Excel']['parametro_id']);
        $this->assertNull($detalle['SAP HR']['parametro_id']);
    }

    public function test_el_texto_libre_sigue_siendo_valido_sin_parametro_id(): void
    {
        $instructor = $this->instructor($this->ficha());

        $respuesta = $this->actingAs($instructor)->postJson('/api/convocatorias', [
            'nombre' => 'Solo texto libre',
            'skills' => ['Excel avanzado'],
        ])->assertCreated();

        $this->assertSame(['Excel avanzado'], $respuesta->json('data.skills'));
        $this->assertNull(ConvocatoriaSkill::sole()->parametro_id);
    }

    public function test_parametro_id_inexistente_devuelve_422(): void
    {
        $instructor = $this->instructor($this->ficha());

        $respuesta = $this->actingAs($instructor)
            ->postJson('/api/convocatorias', [
                'nombre' => 'X',
                'skills' => [['nombre' => 'Inventada', 'parametro_id' => 999999]],
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['skills.0']]);

        $this->assertSame('La skill seleccionada no es válida.', $respuesta->json('errors')['skills.0'][0]);
        $this->assertSame(0, ConvocatoriaSkill::count());
    }

    public function test_parametro_id_de_otro_tema_devuelve_422(): void
    {
        $instructor = $this->instructor($this->ficha());
        $ajeno = $this->parametroId('sexo', 'hombre');

        $respuesta = $this->actingAs($instructor)
            ->postJson('/api/convocatorias', [
                'nombre' => 'X',
                'skills' => [['nombre' => 'Hombre', 'parametro_id' => $ajeno]],
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['skills.0']]);

        $this->assertSame('La skill seleccionada no es válida.', $respuesta->json('errors')['skills.0'][0]);
        $this->assertSame(0, ConvocatoriaSkill::count());
    }

    public function test_parametro_id_de_un_skill_inactivo_devuelve_422(): void
    {
        $instructor = $this->instructor($this->ficha());
        $parametro = $this->parametro('skill_catalogo', 'excel');
        $parametro->forceFill(['activo' => false])->save();

        $respuesta = $this->actingAs($instructor)
            ->postJson('/api/convocatorias', [
                'nombre' => 'X',
                'skills' => [['nombre' => 'Excel', 'parametro_id' => $parametro->id]],
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['skills.0']]);

        $this->assertSame('La skill seleccionada no es válida.', $respuesta->json('errors')['skills.0'][0]);
    }

    public function test_editar_reemplaza_las_skills_y_conserva_el_parametro_id(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $excel = $this->parametro('skill_catalogo', 'excel');
        $liderazgo = $this->parametro('skill_catalogo', 'liderazgo');

        $convocatoria = $this->crearConvocatoria($ficha, $instructor);

        $this->actingAs($instructor)
            ->patchJson('/api/convocatorias/'.$convocatoria->id, [
                'skills' => [
                    ['nombre' => 'Liderazgo', 'parametro_id' => $liderazgo->id],
                ],
            ])->assertOk();

        $this->assertSame(['Liderazgo'], $convocatoria->fresh()->skills->pluck('nombre')->all());
        $this->assertSame((int) $liderazgo->id, (int) ConvocatoriaSkill::sole()->parametro_id);

        // Y al revés: quitar la skill del catálogo deja la fila en NULL.
        $this->actingAs($instructor)
            ->patchJson('/api/convocatorias/'.$convocatoria->id, [
                'skills' => [
                    ['nombre' => 'Excel', 'parametro_id' => $excel->id],
                ],
            ])->assertOk();

        $this->assertSame((int) $excel->id, (int) ConvocatoriaSkill::sole()->parametro_id);
    }

    public function test_el_listado_incluye_los_nombres_de_las_skills(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->publicada($this->crearConvocatoria($ficha, $instructor));
        $convocatoria->skills()->create(['nombre' => 'Excel avanzado']);

        $respuesta = $this->actingAs($instructor)
            ->getJson('/api/convocatorias')
            ->assertOk();

        $fila = collect($respuesta->json('data'))->firstWhere('id', $convocatoria->id);
        $this->assertSame(['Excel avanzado'], $fila['skills']);
        $this->assertSame('Excel avanzado', $fila['skills_detalle'][0]['nombre']);
        $this->assertNull($fila['skills_detalle'][0]['parametro_id']);
    }

    // ── Ficha: solo instructor y super admin ──────────────────────────

    public function test_el_instructor_recibe_la_ficha_de_la_convocatoria(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->publicada($this->crearConvocatoria($ficha, $instructor));

        $respuesta = $this->actingAs($instructor)
            ->getJson('/api/convocatorias/'.$convocatoria->id)
            ->assertOk();

        $this->assertSame($ficha->id, $respuesta->json('data.ficha.id'));
        $this->assertSame($ficha->codigo, $respuesta->json('data.ficha.codigo'));
    }

    public function test_el_aspirante_no_recibe_la_ficha_de_la_convocatoria(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->publicada($this->crearConvocatoria($ficha, $instructor));
        $aspirante = $this->aspirante($ficha);

        $respuesta = $this->actingAs($aspirante)
            ->getJson('/api/convocatorias/'.$convocatoria->id)
            ->assertOk();

        // La clave sigue existiendo (no se rompe el contrato), pero va en null.
        $this->assertNull($respuesta->json('data.ficha'));
    }

    public function test_el_gestor_de_convocatorias_no_recibe_la_ficha(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->publicada($this->crearConvocatoria($ficha, $instructor));
        $gestor = $this->gestorConvocatorias($ficha);

        // El subrol gestor_convocatorias SÍ tiene convocatorias:gestionar.
        $this->assertTrue($gestor->can('convocatorias:gestionar'));

        $respuesta = $this->actingAs($gestor)
            ->getJson('/api/convocatorias/'.$convocatoria->id)
            ->assertOk();

        $this->assertNull($respuesta->json('data.ficha'));
    }

    public function test_el_super_admin_recibe_la_ficha(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->publicada($this->crearConvocatoria($ficha, $instructor));
        $superAdmin = $this->usuario(Role::SuperAdmin);

        $respuesta = $this->actingAs($superAdmin)
            ->getJson('/api/convocatorias/'.$convocatoria->id)
            ->assertOk();

        $this->assertSame($ficha->id, $respuesta->json('data.ficha.id'));
    }

    // ── Conteo de postulantes: solo quien gestiona ─────────────────────

    public function test_el_conteo_de_postulantes_solo_lo_recibe_quien_gestiona(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->publicada($this->crearConvocatoria($ficha, $instructor));
        $aspirante = $this->aspirante($ficha);

        Postulacion::create([
            'convocatoria_id' => $convocatoria->id,
            'user_id' => $aspirante->id,
            'estado_id' => $this->parametroId('estado_postulacion', 'postulada'),
            'fecha_postulacion' => now(),
        ]);

        // Quien gestiona: recibe el conteo.
        $delInstructor = $this->actingAs($instructor)
            ->getJson('/api/convocatorias/'.$convocatoria->id)
            ->assertOk();
        $this->assertSame(1, $delInstructor->json('data.postulantes'));

        $delGestor = $this->actingAs($this->gestorConvocatorias($ficha))
            ->getJson('/api/convocatorias/'.$convocatoria->id)
            ->assertOk();
        $this->assertSame(1, $delGestor->json('data.postulantes'));

        // El aspirante: ni conteo ni ficha.
        $delAspirante = $this->actingAs($aspirante)
            ->getJson('/api/convocatorias/'.$convocatoria->id)
            ->assertOk();
        $this->assertNull($delAspirante->json('data.postulantes'));
        $this->assertNull($delAspirante->json('data.ficha'));
    }
}
