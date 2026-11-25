<?php

// Vinculación de Google con una cuenta que ya existe por correo. Coincidir el correo NO basta: si la cuenta existente no había
// verificado su correo, quien la creó pudo ser otra persona (pre-secuestro), así que sus credenciales no pueden sobrevivir.

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\EmailOtp;
use App\Models\Ficha;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Tests\Support\InicioOAuth;
use Tests\TestCase;

class SocialVinculacionSeguraTest extends TestCase
{
    use InicioOAuth;
    use RefreshDatabase;

    private const CLAVE_ATACANTE = 'ClaveAtacante2026';

    private string $correo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->correo = 'victima.'.getmypid().'@test.com';
    }

    /** Recorrido real: inicio con state, Google «responde» con esta identidad y el callback llega con el state del inicio. */
    private function callbackGoogle(string $sub = 'google-victima-1', bool $verificado = true, ?string $nombre = 'Víctima Real'): \Illuminate\Testing\TestResponse
    {
        $estado = $this->iniciarOAuth();
        $this->googleResponde($sub, $this->correo, $verificado, $nombre);

        return $this->callbackConEstado($estado);
    }

    /** Cuenta creada por alguien más con el correo de la víctima, con todo lo que un atacante habría dejado preparado. */
    private function cuentaDelAtacante(array $extra = []): User
    {
        $ficha = Ficha::create(['codigo' => 'F'.getmypid(), 'nombre_programa' => 'Programa de prueba', 'estado' => 'activa']);

        $user = User::forceCreate(array_merge([
            'name' => 'Nombre del atacante',
            'email' => $this->correo,
            'password' => self::CLAVE_ATACANTE,
            'role' => Role::Aspirante,
            'active' => true,
            'ficha_id' => $ficha->id,
            'email_verified_at' => null,
        ], $extra));

        // Sesión y token abiertos con la contraseña del atacante, y un código de recuperación pedido por él.
        DB::table('sessions')->insert([
            'id' => 'sesion-atacante', 'user_id' => $user->id, 'ip_address' => '203.0.113.9',
            'user_agent' => 'atacante', 'payload' => '', 'last_activity' => time(),
        ]);
        $user->createToken('atacante');
        EmailOtp::create([
            'email' => $this->correo, 'purpose' => 'reset_password', 'code_hash' => Hash::make('123456'),
            'attempts' => 0, 'expires_at' => now()->addMinutes(10),
        ]);

        return $user;
    }

    public function test_cuenta_no_verificada_pierde_las_credenciales_del_atacante_al_vincular_google(): void
    {
        $user = $this->cuentaDelAtacante();
        $this->callbackGoogle()->assertRedirect(config('app.frontend_url'));

        $user->refresh();
        $this->assertAuthenticatedAs($user);
        // Google confirmó el correo: la cuenta queda verificada y vinculada a la identidad de Google.
        $this->assertNotNull($user->email_verified_at);
        $this->assertDatabaseHas('social_accounts', ['user_id' => $user->id, 'provider' => 'google', 'provider_id' => 'google-victima-1']);

        // Nada del atacante sigue siendo utilizable.
        $this->assertFalse(Hash::check(self::CLAVE_ATACANTE, $user->password), 'La contraseña del atacante sigue valiendo.');
        $this->assertSame(0, $user->tokens()->count(), 'Quedó un token del atacante.');
        $this->assertDatabaseMissing('sessions', ['id' => 'sesion-atacante']);
        $this->assertDatabaseMissing('email_otps', ['email' => $this->correo]);

        // Los datos elegidos por el atacante no se heredan: el nombre sale de Google y la ficha la elige la persona real.
        $this->assertSame('Víctima Real', $user->name);
        $this->assertNull($user->ficha_id);
        // La contraseña ahora es generada por el sistema, no elegida por nadie: la marca lo registra.
        $this->assertSame('sin_definir', $user->password_estado);
    }

    public function test_el_atacante_ya_no_puede_entrar_con_su_contrasena_despues_de_la_vinculacion(): void
    {
        $this->cuentaDelAtacante();
        $this->callbackGoogle();
        $this->app['auth']->guard('web')->logout();

        $this->postJson('/api/auth/login', ['email' => $this->correo, 'password' => self::CLAVE_ATACANTE])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    public function test_cuenta_sin_verificar_de_personal_conserva_rol_y_ficha_pero_pierde_las_credenciales(): void
    {
        // Cuenta creada por un administrador (no por el registro público): el rol y la ficha asignados no se tocan.
        $user = $this->cuentaDelAtacante(['role' => Role::Aprendiz, 'subrole' => 'evaluador', 'name' => 'Nombre asignado']);
        $fichaId = $user->ficha_id;
        $this->callbackGoogle();

        $user->refresh();
        $this->assertSame(Role::Aprendiz, $user->role);
        $this->assertSame($fichaId, $user->ficha_id);
        $this->assertSame('Nombre asignado', $user->name);
        $this->assertFalse(Hash::check(self::CLAVE_ATACANTE, $user->password));
        $this->assertSame(0, $user->tokens()->count());
        $this->assertDatabaseMissing('sessions', ['id' => 'sesion-atacante']);
    }

    public function test_cuenta_verificada_conserva_su_contrasena_sesiones_y_tokens_legitimos(): void
    {
        $user = $this->cuentaDelAtacante(['email_verified_at' => now(), 'password' => 'ClaveLegitima2026', 'name' => 'Dueña real']);
        $this->callbackGoogle()->assertRedirect(config('app.frontend_url'));

        $user->refresh();
        $this->assertTrue(Hash::check('ClaveLegitima2026', $user->password));
        $this->assertSame(1, $user->tokens()->count());
        $this->assertDatabaseHas('sessions', ['id' => 'sesion-atacante']);
        $this->assertSame('Dueña real', $user->name);
        $this->assertNotNull($user->ficha_id);
        $this->assertDatabaseHas('social_accounts', ['user_id' => $user->id, 'provider_id' => 'google-victima-1']);
    }

    public function test_correo_que_google_no_confirma_no_vincula_ni_toca_la_cuenta(): void
    {
        $user = $this->cuentaDelAtacante();
        $this->callbackGoogle(verificado: false)->assertRedirect(config('app.frontend_url').'/login?error=correo_no_verificado');

        $this->assertGuest();
        $this->assertDatabaseCount('social_accounts', 0);
        $user->refresh();
        $this->assertTrue(Hash::check(self::CLAVE_ATACANTE, $user->password));
        $this->assertNull($user->email_verified_at);
    }

    public function test_una_cuenta_ya_vinculada_a_otra_identidad_de_google_no_acepta_una_segunda_por_coincidir_el_correo(): void
    {
        $user = $this->cuentaDelAtacante(['email_verified_at' => now(), 'password' => 'ClaveLegitima2026']);
        SocialAccount::forceCreate(['user_id' => $user->id, 'provider' => 'google', 'provider_id' => 'google-original']);
        $this->callbackGoogle(sub: 'google-otra-persona')->assertRedirect(config('app.frontend_url').'/login?error=google_distinto');

        $this->assertGuest();
        $this->assertDatabaseMissing('social_accounts', ['provider_id' => 'google-otra-persona']);
        $this->assertSame(1, $user->socialAccounts()->count());
        $this->assertTrue(Hash::check('ClaveLegitima2026', $user->fresh()->password));
    }

    public function test_cuenta_inactiva_no_se_vincula(): void
    {
        $user = $this->cuentaDelAtacante(['email_verified_at' => now(), 'active' => false]);
        $this->callbackGoogle()->assertRedirect(config('app.frontend_url').'/login?error=desactivado');

        $this->assertGuest();
        $this->assertSame(0, $user->socialAccounts()->count());
    }

    public function test_la_identidad_de_google_manda_sobre_el_correo(): void
    {
        // El dueño legítimo ya vinculado entra por su identificador aunque su correo en el sistema sea otro.
        $user = $this->cuentaDelAtacante(['email_verified_at' => now(), 'email' => 'otro.'.getmypid().'@test.com']);
        SocialAccount::forceCreate(['user_id' => $user->id, 'provider' => 'google', 'provider_id' => 'google-victima-1']);
        $this->callbackGoogle()->assertRedirect(config('app.frontend_url'));

        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseCount('social_accounts', 1);
    }
}
