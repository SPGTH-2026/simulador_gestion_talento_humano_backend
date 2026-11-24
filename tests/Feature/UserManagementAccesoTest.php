<?php

namespace Tests\Feature;

use App\Enums\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\UserManagementAyudas;
use Tests\TestCase;

class UserManagementAccesoTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementAyudas;

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
        $aspirante = $this->usuario(Role::Aspirante, null, ['ficha_id' => $instructor->fichas()->first()->id]);

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
        $aspirante = $this->usuario(Role::Aspirante, null, ['ficha_id' => $ficha->id]);

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
        $fichaId = $instructor->fichas()->first()->id;
        $aprendiz = $this->usuario(Role::Aprendiz, 'general', ['ficha_id' => $fichaId]);

        // El aspirante sigue dentro de su ficha: sin ficha no puede existir.
        $this->actingAs($instructor)->patchJson("/api/usuarios/{$aprendiz->id}", [
            'role' => 'aspirante',
            'active' => true,
            'ficha_id' => $fichaId,
        ])->assertOk();

        $this->assertSame('aspirante', $aprendiz->fresh()->role->value);
        $this->assertSame($fichaId, $aprendiz->fresh()->ficha_id);
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

}
