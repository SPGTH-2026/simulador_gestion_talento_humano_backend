<?php

// Recorridos de acceso con transporte real de cookies (ver Tests\Support\NavegadorReal): cerrar sesión y volver a entrar con
// Google, acceso con contraseña y recuperación pública de contraseña de una cuenta de Google. El CSRF no se comprueba aquí
// (Laravel lo omite en las pruebas unitarias): se verifica contra el servidor local con curl.

namespace Tests\Feature;

use App\Enums\Role;
use App\Mail\OtpMail;
use App\Models\Ficha;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Support\InicioOAuth;
use Tests\Support\NavegadorReal;
use Tests\TestCase;

class AccesoNavegadorTest extends TestCase
{
    use InicioOAuth;
    use NavegadorReal;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararNavegador();
    }

    private function cuentaDeGoogle(): User
    {
        $ficha = Ficha::create(['codigo' => 'AN'.getmypid(), 'nombre_programa' => 'Programa de prueba', 'estado' => 'activa']);
        $user = User::forceCreate([
            'name' => 'Persona Google', 'email' => 'an.'.getmypid().'@test.com', 'password' => 'ClaveGenerada-NoSeUsa-2026',
            'password_estado' => 'sin_definir', 'role' => Role::Aspirante, 'active' => true, 'ficha_id' => $ficha->id,
            'email_verified_at' => now(),
        ]);
        SocialAccount::forceCreate(['user_id' => $user->id, 'provider' => 'google', 'provider_id' => 'google-an-1']);

        return $user;
    }

    private function entrarConGoogle(User $user): void
    {
        $this->cargarLaSpa();
        $estado = $this->iniciarDesdeElFront();
        $this->googleResponde('google-an-1', $user->email);
        $this->volverDeGoogle($estado)->assertRedirect('http://localhost:5173');
    }

    public function test_cerrar_sesion_y_volver_a_entrar_con_google(): void
    {
        $user = $this->cuentaDeGoogle();
        $this->entrarConGoogle($user);
        $this->desdeLaSpa('GET', '/api/auth/me')->assertOk();

        $this->desdeLaSpa('POST', '/api/auth/logout')->assertOk();
        $this->desdeLaSpa('GET', '/api/auth/me')->assertUnauthorized();

        $this->entrarConGoogle($user);
        $this->desdeLaSpa('GET', '/api/auth/me')->assertOk()->assertJsonPath('user.email', $user->email);
    }

    public function test_acceso_con_contrasena_mantiene_la_sesion_entre_peticiones(): void
    {
        $user = User::factory()->create(['email' => 'clave.'.getmypid().'@test.com', 'password' => 'ClaveSegura2026', 'email_verified_at' => now()]);
        $this->cargarLaSpa()->assertUnauthorized();

        $this->desdeLaSpa('POST', '/api/auth/login', ['email' => $user->email, 'password' => 'ClaveSegura2026'])->assertOk();

        $this->desdeLaSpa('GET', '/api/auth/me')->assertOk()->assertJsonPath('user.email', $user->email);
    }

    public function test_cuenta_de_google_recupera_la_contrasena_y_entra_con_ella_o_con_google(): void
    {
        Mail::fake();
        $user = $this->cuentaDeGoogle();
        $this->cargarLaSpa();

        $this->desdeLaSpa('POST', '/api/auth/forgot-password', ['email' => $user->email])->assertOk();
        $codigo = Mail::sent(OtpMail::class)->last()->code;
        $this->desdeLaSpa('POST', '/api/auth/reset-password', [
            'email' => $user->email, 'code' => $codigo, 'password' => 'NuevaClave2026', 'password_confirmation' => 'NuevaClave2026',
        ])->assertOk();

        $this->desdeLaSpa('POST', '/api/auth/login', ['email' => $user->email, 'password' => 'NuevaClave2026'])->assertOk();
        $this->desdeLaSpa('GET', '/api/auth/me')->assertOk();
        $this->desdeLaSpa('POST', '/api/auth/logout')->assertOk();

        $this->entrarConGoogle($user);
        $this->desdeLaSpa('GET', '/api/auth/me')->assertOk()->assertJsonPath('user.email', $user->email);
    }
}
