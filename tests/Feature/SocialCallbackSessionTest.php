<?php

// Prueba del callback de Google SIN red: se falsea Socialite y se verifica que
// la ruta abre sesión con cookie y NO manda OTP (Google ya confirma el correo).

namespace App\Tests\Feature;

use App\Enums\Role;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\OtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Tests\TestCase;

class SocialCallbackSessionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // El callback de Google es un GET sin sesión previa (viene del navegador).
        // Este test comprueba que aun así se crea la sesión.
    }

    private function fakeSocialite(): void
    {
        $social = new \Laravel\Socialite\Two\User;
        $social->id = 'google-abc123';
        $social->name = 'Ana Google';
        $social->email = 'ana.'.getmypid().'@test.com';
        $social->user = ['email' => $social->email];

        $provider = \Mockery::mock(Provider::class);
        $provider->shouldReceive('stateless')->andReturnSelf();
        $provider->shouldReceive('user')->andReturn($social);

        Socialite::shouldReceive('driver')->andReturn($provider);
    }

    public function test_callback_google_nuevo_crea_aspirante_verificado_sin_otp(): void
    {
        $this->fakeSocialite();

        $response = $this->get('/api/auth/google/callback');

        $response->assertRedirect();

        $url = $response->headers->get('Location');

        // 1. NO debe haber token en la URL ni en el fragmento.
        $this->assertStringNotContainsString('token', $url);
        $this->assertStringNotContainsString('#', $url);

        // 2. Redirige al inicio del front, no a la verificación.
        $this->assertSame(config('app.frontend_url'), $url);

        // 3. Se creó el usuario, la cuenta social y quedó verificado.
        $user = User::where('email', 'ana.'.getmypid().'@test.com')->first();
        $this->assertNotNull($user);
        $this->assertSame(Role::Aspirante, $user->role);
        $this->assertNotNull($user->email_verified_at);

        // 4. NO se generó OTP: Google ya confirmó el correo.
        $this->assertDatabaseMissing('email_otps', [
            'email' => $user->email,
            'purpose' => OtpService::VERIFY_EMAIL,
        ]);
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

        $this->fakeSocialite();

        $response = $this->get('/api/auth/google/callback');

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

        $this->fakeSocialite();

        $response = $this->get('/api/auth/google/callback');

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

        $this->fakeSocialite();

        $response = $this->get('/api/auth/google/callback');

        $response->assertRedirect();
        $this->assertStringContainsString('error=desactivado', $response->headers->get('Location'));
        $this->assertGuest();
    }
}