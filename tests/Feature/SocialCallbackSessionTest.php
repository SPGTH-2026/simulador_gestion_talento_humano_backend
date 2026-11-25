<?php

// Prueba del callback de Google SIN red: se falsea Socialite y se verifica que
// la ruta abre sesión con cookie y NO manda OTP (Google ya confirma el correo).

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\OtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Tests\Support\InicioOAuth;
use Tests\TestCase;

class SocialCallbackSessionTest extends TestCase
{
    use InicioOAuth;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Cada prueba empieza con el recorrido real: el inicio (/redirect) guarda el state en la sesión y el callback lo
        // devuelve; solo se simula el canje del código con Google. Ver Tests\Support\InicioOAuth.
    }

    private function correoDeAna(): string
    {
        return 'ana.'.getmypid().'@test.com';
    }

    /** Google «responde» con la cuenta de Ana (correo verificado por Google). */
    private function fakeSocialite(): void
    {
        $this->googleResponde('google-abc123', $this->correoDeAna());
    }

    /** Recorrido válido completo: inicio real, Google responde, y el callback llega con el state del inicio. */
    private function callbackValido(): \Illuminate\Testing\TestResponse
    {
        $estado = $this->iniciarOAuth();
        $this->fakeSocialite();

        return $this->callbackConEstado($estado);
    }

    /** Google rechaza el canje del código (invalid_grant). */
    private function fakeSocialiteConError(string $mensaje = 'Malformed auth code.'): void
    {
        $provider = \Mockery::mock(Provider::class);
        $provider->shouldReceive('stateless')->andReturnSelf();
        $provider->shouldReceive('user')->andThrow(new \RuntimeException($mensaje));

        Socialite::shouldReceive('driver')->andReturn($provider);
    }

    public function test_callback_google_nuevo_no_crea_cuenta_hasta_elegir_ficha(): void
    {
        $response = $this->callbackValido();

        $response->assertRedirect(config('app.frontend_url').'/completar-ficha?pendiente=google');
        $this->assertGuest();

        // La cuenta no existe todavía: sin ficha no puede haber aspirante.
        $this->assertDatabaseMissing('users', ['email' => 'ana.'.getmypid().'@test.com']);
    }

    public function test_completar_pendiente_crea_aspirante_con_ficha_y_abre_sesion(): void
    {
        $ficha = \App\Models\Ficha::create(['codigo' => '3173334', 'nombre_programa' => 'ADSO']);
        $this->callbackValido();

        $this->getJson('/api/auth/google/pendiente')
            ->assertOk()
            ->assertJsonPath('data.email', 'ana.'.getmypid().'@test.com');

        $this->postJson('/api/auth/google/pendiente/ficha', ['ficha_codigo' => '3173334'])
            ->assertCreated();

        $user = User::where('email', 'ana.'.getmypid().'@test.com')->first();
        $this->assertNotNull($user);
        $this->assertSame(Role::Aspirante, $user->role);
        $this->assertSame($ficha->id, $user->ficha_id);
        $this->assertNotNull($user->email_verified_at);
        $this->assertDatabaseHas('social_accounts', ['user_id' => $user->id, 'provider' => 'google', 'provider_id' => 'google-abc123']);
        $this->assertAuthenticatedAs($user);
    }

    public function test_completar_pendiente_sin_ficha_valida_o_sin_registro_no_crea_nada(): void
    {
        \App\Models\Ficha::create(['codigo' => '3173334', 'nombre_programa' => 'ADSO']);

        $this->postJson('/api/auth/google/pendiente/ficha', ['ficha_codigo' => '3173334'])
            ->assertNotFound();

        $this->callbackValido();

        $this->postJson('/api/auth/google/pendiente/ficha', ['ficha_codigo' => '00000000'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('ficha_codigo');

        $this->assertDatabaseMissing('users', ['email' => 'ana.'.getmypid().'@test.com']);
    }

    public function test_cuenta_existente_por_correo_se_enlaza_y_conserva_el_rol(): void
    {
        // Ya existe un usuario con ese correo (p. ej. el super admin creado con
        // app:make-admin), pero sin cuenta social vinculada. Al colisionar el
        // correo de Google (ya verificado) se enlaza a ESE usuario: no se crea
        // otro y el rol no se pierde.
        $admin = User::factory()->create([
            'email' => 'ana.'.getmypid().'@test.com',
            'role' => Role::SuperAdmin,
            'email_verified_at' => now(),
        ]);

        $response = $this->callbackValido();

        $response->assertRedirect();
        $this->assertSame(config('app.frontend_url'), $response->headers->get('Location'));

        // Mismo usuario, mismo rol, sin duplicados y con cuenta social enlazada.
        $this->assertSame($admin->id, $admin->fresh()->id);
        $this->assertSame(Role::SuperAdmin, $admin->fresh()->role);
        $this->assertSame(1, User::where('email', $admin->email)->count());
        $this->assertDatabaseHas('social_accounts', [
            'user_id' => $admin->id,
            'provider' => 'google',
            'provider_id' => 'google-abc123',
        ]);
        $this->assertAuthenticatedAs($admin);
    }

    public function test_cuenta_google_existente_reutiliza_usuario_sin_otp(): void
    {
        $user = User::factory()->create([
            'email' => 'ana.'.getmypid().'@test.com',
            'email_verified_at' => now(),
        ]);
        SocialAccount::forceCreate([
            'user_id' => $user->id,
            'provider' => 'google',
            'provider_id' => 'google-abc123',
        ]);

        $response = $this->callbackValido();

        $response->assertRedirect();

        // Va directo al inicio del front.
        $this->assertSame(config('app.frontend_url'), $response->headers->get('Location'));

        $this->assertAuthenticatedAs($user);

        // Se reutilizó la misma cuenta y el correo sigue verificado.
        $this->assertSame($user->id, User::where('email', $user->email)->first()->id);
        $this->assertNotNull($user->fresh()->email_verified_at);

        // Sin OTP: Google ya verificó el correo.
        $this->assertDatabaseMissing('email_otps', [
            'email' => $user->email,
            'purpose' => OtpService::VERIFY_EMAIL,
        ]);
    }

    public function test_cuenta_inactiva_no_entra(): void
    {
        $user = User::factory()->create([
            'email' => 'ana.'.getmypid().'@test.com',
            'active' => false,
        ]);
        SocialAccount::forceCreate([
            'user_id' => $user->id,
            'provider' => 'google',
            'provider_id' => 'google-abc123',
        ]);

        $response = $this->callbackValido();

        $response->assertRedirect();
        $this->assertStringContainsString('error=desactivado', $response->headers->get('Location'));
        $this->assertGuest();
    }

    public function test_el_redirect_guarda_el_estado_en_la_sesion(): void
    {
        // El flujo de Google es stateful: el 'state' se guarda en la sesión
        // para poder validarlo en el callback (protección CSRF).
        $response = $this->get('/api/auth/google/redirect');

        $response->assertRedirect();

        $estado = session('state');
        $this->assertNotEmpty($estado);
        $this->assertStringContainsString('state='.$estado, $response->headers->get('Location'));
    }

    public function test_callback_con_estado_valido_inicia_sesion(): void
    {
        User::factory()->create(['email' => $this->correoDeAna(), 'email_verified_at' => now()]);

        $response = $this->callbackValido();

        $response->assertRedirect();
        $this->assertSame(config('app.frontend_url'), $response->headers->get('Location'));
        $this->assertAuthenticated();
    }

    public function test_callback_con_estado_que_no_coincide_avisa_y_no_entra(): void
    {
        // Enlace reenviado o de otra pestaña: el state de la URL no es el del inicio de este navegador.
        $this->iniciarOAuth();
        $this->googleNoDebeConsultarse();

        $response = $this->callbackConEstado('otro-estado');

        $response->assertRedirect();
        $this->assertStringContainsString('error=oauth_estado', $response->headers->get('Location'));
        $this->assertGuest();
    }

    public function test_codigo_ya_usado_muestra_error_amigable_y_no_500(): void
    {
        // El inicio es válido, pero Google responde invalid_grant: el código ya se gastó.
        $estado = $this->iniciarOAuth();
        $this->fakeSocialiteConError('Malformed auth code.');

        $response = $this->callbackConEstado($estado);

        $response->assertRedirect();
        $this->assertStringContainsString('error=oauth_codigo', $response->headers->get('Location'));
        $this->assertGuest();
    }

    public function test_codigo_ya_usado_con_sesion_abierta_continua_al_front(): void
    {
        // El usuario ya entró con el primer clic; el segundo callback no debe dejarle fuera: se le lleva al front, sin
        // canjear nada.
        $user = User::factory()->create(['email' => $this->correoDeAna()]);
        $estado = $this->iniciarOAuth();
        $this->googleNoDebeConsultarse();

        $response = $this->actingAs($user)->callbackConEstado($estado);

        $response->assertRedirect();
        $this->assertSame(config('app.frontend_url'), $response->headers->get('Location'));
        $this->assertAuthenticatedAs($user);
    }
}
