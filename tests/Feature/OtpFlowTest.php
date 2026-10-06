<?php

namespace App\Tests\Feature;

use App\Enums\Role;
use App\Mail\OtpMail;
use App\Models\EmailOtp;
use App\Models\User;
use App\Services\OtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class OtpFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Se intercepta el correo para leer el codigo directamente del Mailable,
        // en vez de parsear el log (fragil y sucio).
        Mail::fake();
    }

    /** Ultimo codigo enviado por correo. */
    private function codeDeLog(): string
    {
        $codes = [];

        Mail::assertSent(OtpMail::class, function (OtpMail $mail) use (&$codes) {
            $codes[] = $mail->code;

            return true;
        });

        $this->assertNotEmpty($codes, 'No se envio ningun correo OTP');

        return end($codes);
    }

    private function user(array $extra = []): User
    {
        return User::factory()->create($extra + [
            'password' => Hash::make('Dev12345'),
            'role' => Role::Aspirante,
            'active' => true,
        ]);
    }

    // ── Recuperar contrasena ───────────────────────────────────────────

    public function test_forgot_no_revela_si_el_correo_existe(): void
    {
        $this->user(['email' => 'existe@test.com']);

        $a = $this->postJson('/api/auth/forgot-password', ['email' => 'existe@test.com']);
        $b = $this->postJson('/api/auth/forgot-password', ['email' => 'nadie@test.com']);

        $a->assertOk();
        $b->assertOk();

        // Mismo status y mismo mensaje: no se puede enumerar cuentas.
        $this->assertSame($a->json(), $b->json());

        // Pero el OTP solo se genero para el correo real.
        $this->assertDatabaseCount('email_otps', 1);
        $this->assertDatabaseHas('email_otps', ['email' => 'existe@test.com']);
    }

    public function test_reset_cambia_la_contrasena_y_mata_la_sesion(): void
    {
        $u = $this->user(['email' => 'reset@test.com']);

        $this->postJson('/api/auth/forgot-password', ['email' => $u->email]);
        $code = $this->codeDeLog();

        // Sesion abierta antes del reset.
        $this->postJson('/api/auth/login', ['email' => $u->email, 'password' => 'Dev12345'])
            ->assertOk();
        $this->assertAuthenticatedAs($u);

        $this->postJson('/api/auth/reset-password', [
            'email' => $u->email,
            'code' => $code,
            'password' => 'NuevaClave99',
            'password_confirmation' => 'NuevaClave99',
        ])->assertOk();

        // Contrasena nueva activa, vieja muerta.
        $this->assertTrue(Hash::check('NuevaClave99', $u->fresh()->password));
        $this->assertFalse(Hash::check('Dev12345', $u->fresh()->password));

        // La sesion anterior quedo invalidada: la fila en 'sessions' se borro,
        // asi que un nuevo GET a /me ya no autentica. En el test se resetea el
        // guard en memoria porque assertGuest() mira la instancia del guard.
        $this->assertSame(0, DB::table('sessions')
            ->where('user_id', $u->id)->count(), 'la sesion anterior debio borrarse');

        $this->postJson('/api/auth/logout');
        $this->postJson('/api/auth/login', ['email' => $u->email, 'password' => 'Dev12345'])
            ->assertStatus(422);
        $this->postJson('/api/auth/login', ['email' => $u->email, 'password' => 'NuevaClave99'])
            ->assertOk();
    }

    public function test_reset_con_codigo_incorrecto_no_cambia_nada(): void
    {
        $u = $this->user(['email' => 'malo@test.com']);
        $this->postJson('/api/auth/forgot-password', ['email' => $u->email]);

        $this->postJson('/api/auth/reset-password', [
            'email' => $u->email,
            'code' => '000000',
            'password' => 'NuevaClave99',
            'password_confirmation' => 'NuevaClave99',
        ])->assertStatus(422);

        $this->assertTrue(Hash::check('Dev12345', $u->fresh()->password));
    }

    public function test_reset_con_correo_desconocido_no_revela_nada(): void
    {
        $this->postJson('/api/auth/reset-password', [
            'email' => 'nadie@test.com',
            'code' => '123456',
            'password' => 'NuevaClave99',
            'password_confirmation' => 'NuevaClave99',
        ])->assertStatus(422);
    }

    public function test_dos_peticiones_en_rafaga_producen_un_solo_correo(): void
    {
        $u = $this->user(['email' => 'rafaga@test.com']);

        // El mismo doble envio llegado dos veces (doble click, reintento del
        // proxy): la segunda no debe generar otro codigo ni matar el primero.
        $this->postJson('/api/auth/forgot-password', ['email' => $u->email])->assertOk();
        $this->postJson('/api/auth/forgot-password', ['email' => $u->email])->assertOk();

        Mail::assertSent(OtpMail::class, 1);
        $this->assertDatabaseCount('email_otps', 1);

        // El codigo del UNICO correo recibido sigue siendo el valido.
        $this->postJson('/api/auth/reset-password', [
            'email' => $u->email,
            'code' => $this->codeDeLog(),
            'password' => 'NuevaClave99',
            'password_confirmation' => 'NuevaClave99',
        ])->assertOk();
        $this->assertTrue(Hash::check('NuevaClave99', $u->fresh()->password));

        // Pasada la ventana, un envio nuevo si que emite correo.
        $this->travel(OtpService::IDEMPOTENCY_SECONDS + 1)->seconds();
        $this->postJson('/api/auth/forgot-password', ['email' => $u->email])->assertOk();
        Mail::assertSent(OtpMail::class, 2);
    }

    public function test_un_codigo_no_sirve_para_dos_purpositos(): void
    {
        $u = $this->user(['email' => 'mixto@test.com', 'email_verified_at' => null]);

        // Se pide recuperar contrasena...
        $this->postJson('/api/auth/forgot-password', ['email' => $u->email]);
        $code = $this->codeDeLog();

        // ...pero se intenta usar para verificar el correo.
        $this->postJson('/api/auth/login', ['email' => $u->email, 'password' => 'Dev12345']);
        $this->postJson('/api/auth/verification/confirm', ['code' => $code])->assertStatus(422);

        $this->assertNull($u->fresh()->email_verified_at);

        // Y el de verificar tampoco sirve para cambiar la contrasena.
        $this->postJson('/api/auth/verification/send')->assertOk();
        $vcode = $this->codeDeLog();
        $this->postJson('/api/auth/reset-password', [
            'email' => $u->email,
            'code' => $vcode,
            'password' => 'NuevaClave99',
            'password_confirmation' => 'NuevaClave99',
        ])->assertStatus(422);

        $this->assertTrue(Hash::check('Dev12345', $u->fresh()->password));
    }

    // ── Verificar correo ────────────────────────────────────────────────

    public function test_verificacion_exige_sesion(): void
    {
        $this->postJson('/api/auth/verification/send')->assertUnauthorized();
        $this->postJson('/api/auth/verification/confirm', ['code' => '123456'])->assertUnauthorized();
    }

    public function test_confirmar_marca_el_correo_como_verificado(): void
    {
        $u = $this->user(['email' => 'verif@test.com', 'email_verified_at' => null]);
        $this->postJson('/api/auth/login', ['email' => $u->email, 'password' => 'Dev12345']);

        $this->postJson('/api/auth/verification/send')->assertOk();
        $code = $this->codeDeLog();

        $this->postJson('/api/auth/verification/confirm', ['code' => $code])->assertOk();

        $this->assertNotNull($u->fresh()->email_verified_at);
    }

    public function test_reenviar_ya_verificado_no_manda_correo(): void
    {
        $u = $this->user(['email' => 'yaok@test.com', 'email_verified_at' => now()]);
        $this->postJson('/api/auth/login', ['email' => $u->email, 'password' => 'Dev12345']);

        $this->postJson('/api/auth/verification/send')
            ->assertOk()
            ->assertJson(['message' => 'Tu correo ya está confirmado']);

        $this->assertDatabaseMissing('email_otps', [
            'email' => $u->email,
            'purpose' => OtpService::VERIFY_EMAIL,
        ]);
    }

    public function test_codigo_expirado_no_sirve(): void
    {
        $u = $this->user(['email' => 'expira@test.com', 'email_verified_at' => null]);
        $this->postJson('/api/auth/login', ['email' => $u->email, 'password' => 'Dev12345']);
        $this->postJson('/api/auth/verification/send');

        // Se caduca a mano.
        $this->travel(11)->minutes();

        $this->postJson('/api/auth/verification/confirm', ['code' => $this->codeDeLog()])
            ->assertStatus(422);

        $this->assertNull($u->fresh()->email_verified_at);
    }

    public function test_tres_codigos_mal_consecutivos_bloquean(): void
    {
        $u = $this->user(['email' => 'intentos@test.com', 'email_verified_at' => null]);
        $this->postJson('/api/auth/login', ['email' => $u->email, 'password' => 'Dev12345']);
        $this->postJson('/api/auth/verification/send');

        // Fallar 3 veces agota los intentos.
        $this->postJson('/api/auth/verification/confirm', ['code' => '111111'])->assertStatus(422);
        $this->postJson('/api/auth/verification/confirm', ['code' => '222222'])->assertStatus(422);
        $this->postJson('/api/auth/verification/confirm', ['code' => '333333'])->assertStatus(422);

        $this->assertNull($u->fresh()->email_verified_at);
        $this->assertSame(3, EmailOtp::where('email', $u->email)->value('attempts'));
    }

    // ── 'verified' bloquea el acceso ────────────────────────────────────

    public function test_ruta_de_negocio_exige_correo_verificado(): void
    {
        Route::middleware(['auth:sanctum', 'verified'])
            ->get('/_test/negocio', fn () => response()->json(['ok' => true]));

        $u = $this->user(['email_verified_at' => null]);
        $this->actingAs($u)->getJson('/_test/negocio')->assertForbidden();

        $u->forceFill(['email_verified_at' => now()])->save();
        $this->actingAs($u->fresh())->getJson('/_test/negocio')->assertOk();
    }

    public function test_me_no_exige_verificacion_para_poder_comprobar_el_estado(): void
    {
        $u = $this->user(['email_verified_at' => null]);
        $this->actingAs($u)->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('user.email_verified', false);

        $u->forceFill(['email_verified_at' => now()])->save();
        $this->actingAs($u->fresh())->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('user.email_verified', true);
    }
}
