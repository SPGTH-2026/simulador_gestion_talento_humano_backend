<?php

namespace Tests\Feature;

use App\Enums\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\UserManagementAyudas;
use Tests\TestCase;

class UserManagementValidacionTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementAyudas;

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
