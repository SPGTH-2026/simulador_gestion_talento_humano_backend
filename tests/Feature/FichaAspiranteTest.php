<?php

// La ficha del aspirante: se valida que exista al registrarse (opcional) y se
// puede asociar después con POST /api/auth/ficha. Asociar la ficha NO cambia el
// rol: el instructor lo convierte en aprendiz desde Usuarios.

namespace App\Tests\Feature;

use App\Enums\Role;
use App\Models\Ficha;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FichaAspiranteTest extends TestCase
{
    use RefreshDatabase;

    private function ficha(): Ficha
    {
        return Ficha::create(['codigo' => '3173334', 'nombre_programa' => 'ADSO DESARROLLADORES']);
    }

    private function aspirante(array $extra = []): User
    {
        return User::factory()->create($extra + [
            'role' => Role::Aspirante,
            'active' => true,
            'email_verified_at' => now(),
        ]);
    }

    // ── Registro con ficha (opcional) ──────────────────────────────────

    public function test_registro_sin_ficha_deja_el_aspirante_libre(): void
    {
        $this->postJson('/api/auth/register', [
            'name' => 'Nuevo Aspirante',
            'email' => 'nuevo@test.com',
            'password' => 'Clave12345',
        ])->assertStatus(201)->assertJsonPath('user.ficha', null);

        $this->assertDatabaseHas('users', ['email' => 'nuevo@test.com', 'ficha_id' => null]);
    }

    public function test_registro_con_ficha_valida_la_asocia(): void
    {
        $ficha = $this->ficha();

        $this->postJson('/api/auth/register', [
            'name' => 'Nuevo Aspirante',
            'email' => 'nuevo@test.com',
            'password' => 'Clave12345',
            'ficha_codigo' => $ficha->codigo,
        ])->assertStatus(201)->assertJsonPath('user.ficha.id', $ficha->id);

        $this->assertDatabaseHas('users', ['email' => 'nuevo@test.com', 'ficha_id' => $ficha->id]);
    }

    public function test_registro_con_ficha_inexistente_rechaza(): void
    {
        $this->postJson('/api/auth/register', [
            'name' => 'Nuevo Aspirante',
            'email' => 'nuevo@test.com',
            'password' => 'Clave12345',
            'ficha_codigo' => '99999999',
        ])->assertStatus(422)->assertJsonValidationErrors('ficha_codigo');

        $this->assertDatabaseMissing('users', ['email' => 'nuevo@test.com']);
    }

    // ── POST /api/auth/ficha ───────────────────────────────────────────

    public function test_ficha_exige_sesion(): void
    {
        $this->postJson('/api/auth/ficha', ['ficha_codigo' => '3173334'])->assertUnauthorized();
    }

    public function test_ficha_asocia_y_conserva_el_rol_aspirante(): void
    {
        $ficha = $this->ficha();
        $aspirante = $this->aspirante();

        $this->actingAs($aspirante)
            ->postJson('/api/auth/ficha', ['ficha_codigo' => $ficha->codigo])
            ->assertOk()
            ->assertJsonPath('user.ficha.id', $ficha->id)
            ->assertJsonPath('user.role', Role::Aspirante->value);

        $this->assertSame($ficha->id, $aspirante->fresh()->ficha_id);
    }

    public function test_ficha_inexistente_rechaza(): void
    {
        $aspirante = $this->aspirante();

        $this->actingAs($aspirante)
            ->postJson('/api/auth/ficha', ['ficha_codigo' => '00000000'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('ficha_codigo');

        $this->assertNull($aspirante->fresh()->ficha_id);
    }

    public function test_ficha_rechaza_a_aprendiz(): void
    {
        $ficha = $this->ficha();
        $aprendiz = $this->aspirante([
            'role' => Role::Aprendiz,
            'subrole' => 'general',
            'ficha_id' => $ficha->id,
        ]);

        $this->actingAs($aprendiz)
            ->postJson('/api/auth/ficha', ['ficha_codigo' => $ficha->codigo])
            ->assertStatus(422);

        $this->assertSame($ficha->id, $aprendiz->fresh()->ficha_id);
    }

    public function test_registro_con_ficha_obliga_a_verificacion_para_asociar(): void
    {
        $this->ficha();
        $u = $this->aspirante(['email_verified_at' => null]);

        $this->actingAs($u)
            ->postJson('/api/auth/ficha', ['ficha_codigo' => '3173334'])
            ->assertForbidden();
    }
}