<?php

// Registro público: crea un Aspirante activo y ABRE sesión. El flujo es
// registrarse -> la sesión sin correo verificado cae en /verificar-correo
// (desde ahí se envía el OTP). Los de Google no pasan por aquí: van verificados.

namespace App\Tests\Feature;

use App\Enums\Role;
use App\Models\Ficha;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RegisterTest extends TestCase
{
    use RefreshDatabase;

    private function ficha(): Ficha
    {
        return Ficha::create(['codigo' => '3173334', 'nombre_programa' => 'ADSO DESARROLLADORES']);
    }

    public function test_registro_crea_aspirante_activo_con_sesion_y_ficha(): void
    {
        $ficha = $this->ficha();

        $response = $this->postJson('/api/auth/register', [
            'name' => 'Nuevo Aspirante',
            'email' => 'nuevo@test.com',
            'password' => 'Clave12345',
            'ficha_codigo' => $ficha->codigo,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('user.email', 'nuevo@test.com')
            ->assertJsonPath('user.role', Role::Aspirante->value)
            ->assertJsonPath('user.email_verified', false)
            ->assertJsonPath('user.ficha.id', $ficha->id)
            ->assertJsonMissingPath('token');

        $user = User::where('email', 'nuevo@test.com')->first();
        $this->assertNotNull($user);
        $this->assertTrue($user->active);
        $this->assertTrue(Hash::check('Clave12345', $user->password));

        // El registro abre sesión: así el front cae en /verificar-correo (OTP)
        // sin pasar por el login.
        $this->assertAuthenticatedAs($user);
    }

    public function test_registro_exige_ficha(): void
    {
        $this->postJson('/api/auth/register', [
            'name' => 'Sin Ficha',
            'email' => 'sinficha@test.com',
            'password' => 'Clave12345',
        ])->assertStatus(422)->assertJsonValidationErrors('ficha_codigo');

        $this->assertDatabaseMissing('users', ['email' => 'sinficha@test.com']);
    }

    public function test_no_acepta_correo_duplicado(): void
    {
        User::factory()->create(['email' => 'repetido@test.com']);

        $this->postJson('/api/auth/register', [
            'name' => 'Otro',
            'email' => 'repetido@test.com',
            'password' => 'Clave12345',
            'ficha_codigo' => '3173334',
        ])->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_exige_contrasena_con_letras_y_numeros(): void
    {
        $this->postJson('/api/auth/register', [
            'name' => 'Debil',
            'email' => 'debil@test.com',
            'password' => 'corta',
            'ficha_codigo' => '3173334',
        ])->assertStatus(422)->assertJsonValidationErrors('password');
    }
}
