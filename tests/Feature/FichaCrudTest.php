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
 * CRUD de fichas con auto-ownership.
 *
 *  - La ficha que registra un instructor queda a su nombre (fichas.creado_por)
 *    y el instructor la administra; el super admin las administra todas.
 *  - Fuera del alcance del usuario la ficha responde 404 (no se revela que
 *    existe); un instructor con alcance pero que no la creó recibe 403.
 *  - Asignar/quitar instructor es solo del super admin.
 *  - No se puede borrar una ficha con convocatorias ni con personas.
 *  - GET /api/fichas conserva la forma que usan los selectores del front.
 */
class FichaCrudTest extends TestCase
{
    use RefreshDatabase;

    private static int $codigoSecuencia = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ParametroSeeder::class);
    }

    // ── Helpers ────────────────────────────────────────────────────────

    private function ficha(string $codigo = '3173334', array $extra = []): Ficha
    {
        return Ficha::create($extra + [
            'codigo' => $codigo,
            'nombre_programa' => 'Análisis y Desarrollo de Software',
        ]);
    }

    private function usuario(Role $role, array $extra = []): User
    {
        return User::factory()->create($extra + [
            'role' => $role,
            'active' => true,
            'email_verified_at' => now(),
        ]);
    }

    private function superAdmin(): User
    {
        return $this->usuario(Role::SuperAdmin);
    }

    private function instructor(Ficha ...$fichas): User
    {
        $instructor = $this->usuario(Role::Instructor);
        foreach ($fichas as $ficha) {
            $instructor->fichas()->attach($ficha->id);
        }

        return $instructor->fresh();
    }

    private function parametroId(string $tema, string $codigo): int
    {
        $parametro = Parametro::porTemaYCodigo($tema, $codigo);
        $this->assertNotNull($parametro, "Falta el parámetro {$tema}.{$codigo}");

        return (int) $parametro->id;
    }

    private function crearConvocatoria(Ficha $ficha, User $creador): Convocatoria
    {
        return Convocatoria::unguarded(fn () => Convocatoria::create([
            'ficha_id' => $ficha->id,
            'codigo' => sprintf('CONV-FIC-%03d', ++self::$codigoSecuencia),
            'nombre' => 'Convocatoria base',
            'vacantes' => 1,
            'tipo_convocatoria_id' => $this->parametroId('tipo_convocatoria', 'externo'),
            'tipo_empleo_id' => $this->parametroId('tipo_empleo', 'tiempo_completo'),
            'tipo_contrato_id' => $this->parametroId('tipo_contrato', 'termino_fijo'),
            'modalidad_id' => $this->parametroId('modalidad', 'presencial'),
            'estado_id' => $this->parametroId('estado_convocatoria', 'publicada'),
            'creado_por' => $creador->id,
        ]));
    }

    // ── Listado ────────────────────────────────────────────────────────

    public function test_el_instructor_ve_sus_fichas_del_pivot_y_las_que_creo(): void
    {
        $delPivot = $this->ficha('1111111');
        $ajena = $this->ficha('2222222');

        $instructor = $this->instructor($delPivot);

        $creada = $this->actingAs($instructor)->postJson('/api/fichas', [
            'codigo' => '3333333',
            'nombre_programa' => 'Programación de Software',
        ])->assertCreated()->json('data');

        $this->actingAs($instructor->fresh())
            ->getJson('/api/fichas')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $delPivot->id)
            ->assertJsonPath('data.1.id', $creada['id']);

        $this->assertNotContains($ajena->id, $instructor->fresh()->fichaIds());
    }

    public function test_el_super_admin_ve_todas_las_fichas(): void
    {
        $this->ficha('1111111');
        $this->ficha('2222222');

        $this->actingAs($this->superAdmin())
            ->getJson('/api/fichas')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_el_listado_conserva_la_forma_de_los_selectores(): void
    {
        $ficha = $this->ficha('3173334');

        $this->actingAs($this->instructor($ficha))
            ->getJson('/api/fichas')
            ->assertOk()
            ->assertJsonPath('data.0.id', $ficha->id)
            ->assertJsonPath('data.0.codigo', '3173334')
            ->assertJsonPath('data.0.nombre_programa', 'Análisis y Desarrollo de Software')
            ->assertJsonPath('data.0.estado', 'activa');
    }

    public function test_el_aspirante_no_puede_listar_fichas(): void
    {
        $this->actingAs($this->usuario(Role::Aspirante))
            ->getJson('/api/fichas')
            ->assertForbidden();
    }

    // ── Alta ───────────────────────────────────────────────────────────

    public function test_el_instructor_crea_una_ficha_y_queda_dueno_de_ella(): void
    {
        $instructor = $this->instructor();

        $this->actingAs($instructor)
            ->postJson('/api/fichas', [
                'codigo' => '3173334',
                'nombre_programa' => 'Análisis y Desarrollo de Software',
            ])
            ->assertCreated()
            ->assertJsonPath('data.codigo', '3173334')
            ->assertJsonPath('data.estado', 'activa')
            ->assertJsonPath('data.creado_por.id', $instructor->id)
            ->assertJsonPath('data.puede_gestionar', true);

        // Auto-ownership: la ficha creada entra en su alcance.
        $this->assertContains(
            Ficha::where('codigo', '3173334')->firstOrFail()->id,
            $instructor->fresh()->fichaIds(),
        );
    }

    public function test_el_codigo_de_la_ficha_es_unico(): void
    {
        $this->ficha('3173334');

        $this->actingAs($this->instructor())
            ->postJson('/api/fichas', [
                'codigo' => '3173334',
                'nombre_programa' => 'Análisis y Desarrollo de Software',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('codigo');
    }

    public function test_la_ficha_no_acepta_campos_reservados(): void
    {
        $this->actingAs($this->instructor())
            ->postJson('/api/fichas', [
                'codigo' => '3173334',
                'nombre_programa' => 'Análisis y Desarrollo de Software',
                'creado_por' => 999,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('campos');
    }

    public function test_la_ficha_exige_codigo_y_nombre(): void
    {
        $this->actingAs($this->instructor())
            ->postJson('/api/fichas', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['codigo', 'nombre_programa']);
    }

    // ── Detalle y edición ──────────────────────────────────────────────

    public function test_el_detalle_de_una_ficha_fuera_de_alcance_responde_404(): void
    {
        $ajena = $this->ficha('2222222');

        $this->actingAs($this->instructor())
            ->getJson("/api/fichas/{$ajena->id}")
            ->assertNotFound();
    }

    public function test_el_instructor_actualiza_la_ficha_que_creo(): void
    {
        $instructor = $this->instructor();
        $ficha = $this->ficha('3173334', ['creado_por' => $instructor->id]);

        $this->actingAs($instructor)
            ->patchJson("/api/fichas/{$ficha->id}", [
                'nombre_programa' => 'Programación de Software',
                'estado' => 'cerrada',
            ])
            ->assertOk()
            ->assertJsonPath('data.nombre_programa', 'Programación de Software')
            ->assertJsonPath('data.estado', 'cerrada');
    }

    public function test_el_instructor_no_actualiza_una_ficha_ajena_su_alcance(): void
    {
        $ficha = $this->ficha('3173334');
        $instructor = $this->instructor($ficha);

        $this->actingAs($instructor)
            ->patchJson("/api/fichas/{$ficha->id}", ['nombre_programa' => 'Otro programa'])
            ->assertForbidden();
    }

    public function test_actualizar_una_ficha_fuera_de_alcance_responde_404(): void
    {
        $ajena = $this->ficha('2222222');

        $this->actingAs($this->instructor())
            ->patchJson("/api/fichas/{$ajena->id}", ['nombre_programa' => 'Otro programa'])
            ->assertNotFound();
    }

    public function test_una_ficha_inexistente_responde_404(): void
    {
        $this->actingAs($this->superAdmin())
            ->getJson('/api/fichas/999999')
            ->assertNotFound();
    }

    // ── Baja ───────────────────────────────────────────────────────────

    public function test_no_se_puede_eliminar_una_ficha_con_convocatorias(): void
    {
        $ficha = $this->ficha('3173334');
        $this->crearConvocatoria($ficha, $this->superAdmin());

        $this->actingAs($this->superAdmin())
            ->deleteJson("/api/fichas/{$ficha->id}")
            ->assertStatus(422)
            ->assertJsonValidationErrors('ficha');

        $this->assertDatabaseHas('fichas', ['id' => $ficha->id]);
    }

    public function test_no_se_puede_eliminar_una_ficha_con_personas(): void
    {
        $ficha = $this->ficha('3173334');
        $this->usuario(Role::Aspirante, ['ficha_id' => $ficha->id]);

        $this->actingAs($this->superAdmin())
            ->deleteJson("/api/fichas/{$ficha->id}")
            ->assertStatus(422)
            ->assertJsonValidationErrors('ficha');

        $this->assertDatabaseHas('fichas', ['id' => $ficha->id]);
    }

    public function test_el_super_admin_elimina_una_ficha_vacia(): void
    {
        $ficha = $this->ficha('3173334');

        $this->actingAs($this->superAdmin())
            ->deleteJson("/api/fichas/{$ficha->id}")
            ->assertOk()
            ->assertJsonPath('data.eliminada', true);

        $this->assertDatabaseMissing('fichas', ['id' => $ficha->id]);
    }

    public function test_el_instructor_elimina_la_ficha_que_creo(): void
    {
        $instructor = $this->instructor();
        $ficha = $this->ficha('3173334', ['creado_por' => $instructor->id]);

        $this->actingAs($instructor)
            ->deleteJson("/api/fichas/{$ficha->id}")
            ->assertOk();

        $this->assertDatabaseMissing('fichas', ['id' => $ficha->id]);
    }

    public function test_el_instructor_no_elimina_una_ficha_ajena(): void
    {
        $ficha = $this->ficha('3173334');
        $instructor = $this->instructor($ficha);

        $this->actingAs($instructor)
            ->deleteJson("/api/fichas/{$ficha->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('fichas', ['id' => $ficha->id]);
    }

    // ── Instructores de la ficha (solo super admin) ─────────────────────

    public function test_el_instructor_no_puede_asignar_instructores(): void
    {
        $ficha = $this->ficha('3173334');
        $instructor = $this->instructor($ficha);
        $otro = $this->usuario(Role::Instructor);

        $this->actingAs($instructor)
            ->postJson("/api/fichas/{$ficha->id}/instructores", ['usuario_id' => $otro->id])
            ->assertForbidden();

        $this->assertDatabaseMissing('ficha_instructor', [
            'ficha_id' => $ficha->id,
            'usuario_id' => $otro->id,
        ]);
    }

    public function test_el_super_admin_asigna_un_instructor_a_la_ficha(): void
    {
        $ficha = $this->ficha('3173334');
        $instructor = $this->usuario(Role::Instructor);

        $this->actingAs($this->superAdmin())
            ->postJson("/api/fichas/{$ficha->id}/instructores", ['usuario_id' => $instructor->id])
            ->assertOk()
            ->assertJsonPath('data.instructores.0.id', $instructor->id);

        $this->assertDatabaseHas('ficha_instructor', [
            'ficha_id' => $ficha->id,
            'usuario_id' => $instructor->id,
        ]);

        // La asignación también amplía el alcance del instructor.
        $this->assertContains($ficha->id, $instructor->fresh()->fichaIds());
    }

    public function test_el_super_admin_quita_un_instructor_de_la_ficha(): void
    {
        $ficha = $this->ficha('3173334');
        $instructor = $this->usuario(Role::Instructor);
        $ficha->instructores()->attach($instructor->id);

        $this->actingAs($this->superAdmin())
            ->deleteJson("/api/fichas/{$ficha->id}/instructores", ['usuario_id' => $instructor->id])
            ->assertOk()
            ->assertJsonCount(0, 'data.instructores');

        $this->assertDatabaseMissing('ficha_instructor', [
            'ficha_id' => $ficha->id,
            'usuario_id' => $instructor->id,
        ]);
    }

    public function test_no_se_puede_asignar_un_usuario_que_no_es_instructor(): void
    {
        $ficha = $this->ficha('3173334');
        $aspirante = $this->usuario(Role::Aspirante);

        $this->actingAs($this->superAdmin())
            ->postJson("/api/fichas/{$ficha->id}/instructores", ['usuario_id' => $aspirante->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('usuario_id');
    }

    public function test_asignar_instructor_exige_un_usuario_existente(): void
    {
        $ficha = $this->ficha('3173334');

        $this->actingAs($this->superAdmin())
            ->postJson("/api/fichas/{$ficha->id}/instructores", ['usuario_id' => 999999])
            ->assertStatus(422)
            ->assertJsonValidationErrors('usuario_id');
    }
}
