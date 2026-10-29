<?php

namespace App\Tests\Feature;

use App\Enums\Role;
use App\Models\Ficha;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private function ficha(string $codigo, string $programa = 'Análisis y Desarrollo de Software'): Ficha
    {
        return Ficha::create(['codigo' => $codigo, 'nombre_programa' => $programa]);
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

    private function instructor(): User
    {
        $instructor = $this->usuario(Role::Instructor);
        $instructor->fichas()->attach($this->ficha('2896411')->id);

        return $instructor;
    }

    // ── Acceso ─────────────────────────────────────────────────────────

    public function test_invitado_recibe_401(): void
    {
        $this->getJson('/api/usuarios')->assertUnauthorized();
    }

    public function test_sin_permiso_recibe_403(): void
    {
        $u = $this->usuario(Role::Aspirante);
        $this->actingAs($u)->getJson('/api/usuarios')->assertForbidden();
    }

    // ── Lista de usuarios ──────────────────────────────────────────────

    public function test_instructor_ve_solo_sus_fichas_y_aspirantes(): void
    {
        $instructor = $this->instructor();
        $fichaAjena = $this->ficha('2631620');

        $aprendizMio = $this->usuario(Role::Aprendiz, 'general', ['ficha_id' => $instructor->fichas()->first()->id]);
        $aprendizOtro = $this->usuario(Role::Aprendiz, 'general', ['ficha_id' => $fichaAjena->id]);
        $aspirante = $this->usuario(Role::Aspirante);

        $ids = collect($this->actingAs($instructor)->getJson('/api/usuarios')->json('data'))
            ->pluck('id')->all();

        $this->assertContains($aprendizMio->id, $ids);
        $this->assertContains($aspirante->id, $ids);
        $this->assertNotContains($aprendizOtro->id, $ids);
    }

    public function test_super_admin_ve_todo_y_filtra_por_ficha(): void
    {
        $admin = $this->usuario(Role::SuperAdmin);
        $ficha = $this->ficha('2896411');
        $fichaB = $this->ficha('2631620');

        $a = $this->usuario(Role::Aprendiz, 'general', ['ficha_id' => $ficha->id]);
        $b = $this->usuario(Role::Aprendiz, 'general', ['ficha_id' => $fichaB->id]);

        $ids = collect($this->actingAs($admin)->getJson("/api/usuarios?ficha_id={$ficha->id}")->json('data'))
            ->pluck('id')->all();

        $this->assertContains($a->id, $ids);
        $this->assertNotContains($b->id, $ids);
    }

    // ── Matriz de roles ───────────────────────────────────────────────

    public function test_instructor_asigna_aprendiz_con_subrol_en_su_ficha(): void
    {
        $instructor = $this->instructor();
        $ficha = $instructor->fichas()->first();
        $aspirante = $this->usuario(Role::Aspirante);

        $this->actingAs($instructor)->patchJson("/api/usuarios/{$aspirante->id}", [
            'role' => 'aprendiz',
            'subrole' => 'seleccionador',
            'active' => true,
            'ficha_id' => $ficha->id,
        ])->assertOk();

        $this->assertSame('aprendiz', $aspirante->fresh()->role->value);
        $this->assertSame('seleccionador', $aspirante->fresh()->subrole);
        $this->assertSame($ficha->id, $aspirante->fresh()->ficha_id);
    }

    public function test_instructor_revierte_a_aspirante(): void
    {
        $instructor = $this->instructor();
        $aprendiz = $this->usuario(Role::Aprendiz, 'general', ['ficha_id' => $instructor->fichas()->first()->id]);

        $this->actingAs($instructor)->patchJson("/api/usuarios/{$aprendiz->id}", [
            'role' => 'aspirante',
            'active' => true,
        ])->assertOk();

        $this->assertSame('aspirante', $aprendiz->fresh()->role->value);
        $this->assertNull($aprendiz->fresh()->ficha_id);
    }

    public function test_instructor_no_puede_sacar_a_ficha_ajena(): void
    {
        $instructor = $this->instructor();
        $fichaAjena = $this->ficha('9999999');
        $aprendiz = $this->usuario(Role::Aprendiz, 'general', ['ficha_id' => $instructor->fichas()->first()->id]);

        $this->actingAs($instructor)->patchJson("/api/usuarios/{$aprendiz->id}", [
            'role' => 'aprendiz',
            'subrole' => 'general',
            'active' => true,
            'ficha_id' => $fichaAjena->id,
        ])->assertStatus(422)->assertJsonValidationErrors('ficha_id');
    }

    public function test_instructor_no_puede_tocar_a_otro_instructor(): void
    {
        $instructor = $this->instructor();
        $otro = $this->usuario(Role::Instructor);

        $this->actingAs($instructor)->patchJson("/api/usuarios/{$otro->id}", [
            'role' => 'instructor',
            'active' => true,
        ])->assertStatus(422)->assertJsonValidationErrors('role');
    }

    public function test_instructor_no_puede_asignar_instructor(): void
    {
        $instructor = $this->instructor();
        $aprendiz = $this->usuario(Role::Aprendiz, 'general', ['ficha_id' => $instructor->fichas()->first()->id]);

        $this->actingAs($instructor)->patchJson("/api/usuarios/{$aprendiz->id}", [
            'role' => 'instructor',
            'active' => true,
        ])->assertStatus(422)->assertJsonValidationErrors('role');
    }

    public function test_super_admin_puede_hacer_instructor(): void
    {
        $admin = $this->usuario(Role::SuperAdmin);
        $aspirante = $this->usuario(Role::Aspirante);

        $this->actingAs($admin)->patchJson("/api/usuarios/{$aspirante->id}", [
            'role' => 'instructor',
            'active' => true,
        ])->assertOk()->assertJsonPath('data.role', 'instructor');
    }

    public function test_nadie_modifica_su_propio_usuario(): void
    {
        $admin = $this->usuario(Role::SuperAdmin);

        $this->actingAs($admin)->patchJson("/api/usuarios/{$admin->id}", [
            'role' => 'super_admin',
            'active' => true,
        ])->assertStatus(422)->assertJsonValidationErrors('role');
    }

    // ── Alcance entre instructores ─────────────────────────────────────

    /** @return array{0: User, 1: User, 2: Ficha, 3: Ficha} dos instructores, cada uno con su ficha */
    private function dosInstructores(): array
    {
        $fichaA = $this->ficha('1111111');
        $fichaB = $this->ficha('2222222');
        $a = $this->usuario(Role::Instructor);
        $b = $this->usuario(Role::Instructor);
        $a->fichas()->attach($fichaA->id);
        $b->fichas()->attach($fichaB->id);

        return [$a, $b, $fichaA, $fichaB];
    }

    public function test_instructor_no_lista_aspirantes_ni_aprendices_de_ficha_ajena(): void
    {
        [$a, , $fichaA, $fichaB] = $this->dosInstructores();
        $aprendizA = $this->usuario(Role::Aprendiz, 'general', ['ficha_id' => $fichaA->id]);
        $aspiranteA = $this->usuario(Role::Aspirante, null, ['ficha_id' => $fichaA->id]);
        $aprendizB = $this->usuario(Role::Aprendiz, 'general', ['ficha_id' => $fichaB->id]);
        $aspiranteB = $this->usuario(Role::Aspirante, null, ['ficha_id' => $fichaB->id]);
        $libre = $this->usuario(Role::Aspirante);

        $ids = collect($this->actingAs($a)->getJson('/api/usuarios')->json('data'))->pluck('id')->all();

        $this->assertContains($aprendizA->id, $ids);
        $this->assertContains($aspiranteA->id, $ids);
        $this->assertContains($libre->id, $ids);
        $this->assertNotContains($aprendizB->id, $ids);
        $this->assertNotContains($aspiranteB->id, $ids);

        $porRol = collect($this->actingAs($a)->getJson('/api/usuarios?role=aspirante')->json('data'))->pluck('id')->all();
        $this->assertContains($aspiranteA->id, $porRol);
        $this->assertNotContains($aspiranteB->id, $porRol);
    }

    public function test_instructor_no_modifica_objetivo_de_ficha_ajena_y_recibe_404(): void
    {
        [$a, , $fichaA, $fichaB] = $this->dosInstructores();
        $aprendizB = $this->usuario(Role::Aprendiz, 'general', ['ficha_id' => $fichaB->id]);
        $aspiranteB = $this->usuario(Role::Aspirante, null, ['ficha_id' => $fichaB->id]);

        $intentos = [
            // trasladar a una ficha propia
            [$aprendizB, ['role' => 'aprendiz', 'subrole' => 'general', 'active' => true, 'ficha_id' => $fichaA->id]],
            // omitir ficha_id / enviarlo null, cambiando rol o estado
            [$aprendizB, ['role' => 'aspirante', 'active' => true]],
            [$aprendizB, ['role' => 'aspirante', 'active' => false, 'ficha_id' => null]],
            [$aspiranteB, ['role' => 'aspirante', 'active' => false]],
            [$aspiranteB, ['role' => 'aprendiz', 'subrole' => 'general', 'active' => true, 'ficha_id' => $fichaA->id]],
        ];

        foreach ($intentos as [$objetivo, $payload]) {
            $antes = $objetivo->fresh()->only(['role', 'subrole', 'active', 'ficha_id']);

            $this->actingAs($a)->patchJson("/api/usuarios/{$objetivo->id}", $payload)->assertNotFound();

            $this->assertEquals($antes, $objetivo->fresh()->only(['role', 'subrole', 'active', 'ficha_id']));
        }
    }

    public function test_objetivo_ajeno_da_404_con_ficha_omitida_null_o_payload_invalido(): void
    {
        [$a, , , $fichaB] = $this->dosInstructores();
        $aprendizB = $this->usuario(Role::Aprendiz, 'general', ['ficha_id' => $fichaB->id]);
        $aspiranteB = $this->usuario(Role::Aspirante, null, ['ficha_id' => $fichaB->id]);

        $payloads = [
            // role=aprendiz sin ficha_id (antes daba 422)
            ['role' => 'aprendiz', 'subrole' => 'general', 'active' => false],
            ['role' => 'aprendiz', 'subrole' => 'general', 'active' => false, 'ficha_id' => null],
            // payload inválido: sin campos requeridos, rol inexistente, ficha inexistente
            [],
            ['role' => 'inventado', 'active' => 'x'],
            ['role' => 'aprendiz', 'subrole' => 'presidente', 'active' => true, 'ficha_id' => 999999],
        ];

        foreach ([$aprendizB, $aspiranteB] as $objetivo) {
            $antes = $objetivo->fresh()->only(['role', 'subrole', 'active', 'ficha_id']);

            foreach ($payloads as $payload) {
                $this->actingAs($a)->patchJson("/api/usuarios/{$objetivo->id}", $payload)->assertNotFound();
            }

            $this->assertEquals($antes, $objetivo->fresh()->only(['role', 'subrole', 'active', 'ficha_id']));
        }
    }

    public function test_instructor_incorpora_aspirante_sin_ficha_a_ficha_propia(): void
    {
        [$a, , $fichaA] = $this->dosInstructores();
        $libre = $this->usuario(Role::Aspirante);

        $this->actingAs($a)->patchJson("/api/usuarios/{$libre->id}", [
            'role' => 'aprendiz',
            'subrole' => 'general',
            'active' => true,
            'ficha_id' => $fichaA->id,
        ])->assertOk();

        $this->assertSame($fichaA->id, $libre->fresh()->ficha_id);
    }

    public function test_instructor_sigue_gestionando_aspirantes_de_su_ficha(): void
    {
        [$a, , $fichaA] = $this->dosInstructores();
        $aspiranteA = $this->usuario(Role::Aspirante, null, ['ficha_id' => $fichaA->id]);

        $this->actingAs($a)->patchJson("/api/usuarios/{$aspiranteA->id}", [
            'role' => 'aspirante',
            'active' => false,
            'ficha_id' => $fichaA->id,
        ])->assertOk();

        $this->assertFalse((bool) $aspiranteA->fresh()->active);
    }

    public function test_super_admin_modifica_usuario_de_cualquier_ficha(): void
    {
        [, , , $fichaB] = $this->dosInstructores();
        $admin = $this->usuario(Role::SuperAdmin);
        $aprendizB = $this->usuario(Role::Aprendiz, 'general', ['ficha_id' => $fichaB->id]);

        $this->actingAs($admin)->patchJson("/api/usuarios/{$aprendizB->id}", [
            'role' => 'aprendiz',
            'subrole' => 'evaluador',
            'active' => true,
            'ficha_id' => $fichaB->id,
        ])->assertOk();

        $this->assertSame('evaluador', $aprendizB->fresh()->subrole);
    }

    // ── Subrol y ficha consistentes ────────────────────────────────────

    public function test_aprendiz_sin_ficha_rechaza(): void
    {
        $admin = $this->usuario(Role::SuperAdmin);
        $aspirante = $this->usuario(Role::Aspirante);

        $this->actingAs($admin)->patchJson("/api/usuarios/{$aspirante->id}", [
            'role' => 'aprendiz',
            'subrole' => 'general',
            'active' => true,
        ])->assertStatus(422)->assertJsonValidationErrors('ficha_id');
    }

    public function test_subrol_invalido_para_aprendiz_rechaza(): void
    {
        $instructor = $this->instructor();
        $aspirante = $this->usuario(Role::Aspirante);

        $this->actingAs($instructor)->patchJson("/api/usuarios/{$aspirante->id}", [
            'role' => 'aprendiz',
            'subrole' => 'presidente',
            'active' => true,
            'ficha_id' => $instructor->fichas()->first()->id,
        ])->assertStatus(422)->assertJsonValidationErrors('subrole');
    }

    public function test_instructor_con_ficha_rechaza(): void
    {
        $admin = $this->usuario(Role::SuperAdmin);
        $aspirante = $this->usuario(Role::Aspirante);

        $this->actingAs($admin)->patchJson("/api/usuarios/{$aspirante->id}", [
            'role' => 'instructor',
            'active' => true,
            'ficha_id' => $this->ficha('2896411')->id,
        ])->assertStatus(422)->assertJsonValidationErrors('ficha_id');
    }

    // ── Fichas ─────────────────────────────────────────────────────────

    public function test_fichas_solo_el_alcance_del_instructor(): void
    {
        $instructor = $this->instructor();
        $this->ficha('2631620');

        $this->actingAs($instructor)->getJson('/api/fichas')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonFragment(['codigo' => '2896411']);
    }

    public function test_super_admin_ve_todas_las_fichas(): void
    {
        $admin = $this->usuario(Role::SuperAdmin);
        $this->ficha('2896411');
        $this->ficha('2631620');

        $this->actingAs($admin)->getJson('/api/fichas')->assertOk()->assertJsonCount(2, 'data');
    }

    // ── Revocación de acceso ───────────────────────────────────────────

    public function test_cambiar_el_rol_borra_sesiones_y_tokens(): void
    {
        $admin = $this->usuario(Role::SuperAdmin);
        $aprendiz = $this->usuario(Role::Aprendiz, 'general', ['ficha_id' => $this->ficha('2896411')->id]);
        $aprendiz->createToken('demo');
        DB::table('sessions')->insert(['id' => 's1', 'user_id' => $aprendiz->id, 'payload' => '', 'last_activity' => time()]);

        $this->actingAs($admin)->patchJson("/api/usuarios/{$aprendiz->id}", [
            'role' => 'aprendiz',
            'subrole' => 'general',
            'active' => false,
            'ficha_id' => $aprendiz->ficha_id,
        ])->assertOk();

        $this->assertFalse((bool) $aprendiz->fresh()->active);
        $this->assertSame(0, $aprendiz->fresh()->tokens()->count());
        $this->assertSame(0, DB::table('sessions')->where('user_id', $aprendiz->id)->count());
    }
}