<?php

namespace Tests\Feature;

use App\Enums\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\UserManagementAyudas;
use Tests\TestCase;

class UserManagementFichasTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementAyudas;

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

    public function test_instructor_no_ve_ni_toca_aspirante_sin_ficha(): void
    {
        // Un aspirante sin ficha no puede existir: no aparece en la lista de ningún instructor ni se le puede asignar.
        [$a, , $fichaA] = $this->dosInstructores();
        $libre = $this->usuario(Role::Aspirante);

        $this->actingAs($a)->patchJson("/api/usuarios/{$libre->id}", [
            'role' => 'aprendiz',
            'subrole' => 'general',
            'active' => true,
            'ficha_id' => $fichaA->id,
        ])->assertNotFound();

        $this->assertNull($libre->fresh()->ficha_id);
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

}
