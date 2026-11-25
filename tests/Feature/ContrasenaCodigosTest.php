<?php

// Códigos de «establecer contraseña desde la sesión»: incorrectos, vencidos, reutilizados, de otro propósito o de otra cuenta; el
// request no puede cambiar el destinatario ni la cuenta; y la recuperación pública sigue igual para cualquier correo.

namespace Tests\Feature;

use App\Mail\ContrasenaCambiadaMail;
use App\Mail\OtpMail;
use App\Models\EmailOtp;
use App\Models\SocialAccount;
use App\Services\OtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\Support\ConCuentaDeSesion;
use Tests\Support\InicioOAuth;
use Tests\TestCase;

class ContrasenaCodigosTest extends TestCase
{
    use ConCuentaDeSesion;
    use InicioOAuth;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararContrasenaDeSesion();
    }

    // ── Códigos inválidos ───────────────────────────────────────────────────

    public function test_codigo_incorrecto_no_cambia_nada(): void
    {
        $user = $this->cuenta();
        $this->pedirCodigo($user);

        $this->establecer($user, '000000')->assertStatus(422)->assertJsonValidationErrors('code');

        $this->assertTrue(Hash::check('ClaveVieja2026', $user->fresh()->password));
        $this->assertNull($user->fresh()->password_estado);
        Mail::assertNotSent(ContrasenaCambiadaMail::class);
    }

    public function test_codigo_de_un_solo_uso(): void
    {
        $user = $this->cuenta();
        $codigo = $this->pedirCodigo($user);
        $this->establecer($user, $codigo)->assertOk();

        $this->establecer($user, $codigo, ['password' => 'Segunda2026a', 'password_confirmation' => 'Segunda2026a'])
            ->assertStatus(422)->assertJsonValidationErrors('code');
        $this->assertTrue(Hash::check(self::NUEVA, $user->fresh()->password));
    }

    public function test_codigo_vencido(): void
    {
        $user = $this->cuenta();
        $codigo = $this->pedirCodigo($user);
        $this->travel(OtpService::TTL_MINUTES + 1)->minutes();

        $this->establecer($user, $codigo)->assertStatus(422)->assertJsonValidationErrors('code');
        $this->assertTrue(Hash::check('ClaveVieja2026', $user->fresh()->password));
    }

    public function test_tres_codigos_mal_inutilizan_el_codigo_verdadero(): void
    {
        $user = $this->cuenta();
        $codigo = $this->pedirCodigo($user);
        for ($i = 0; $i < OtpService::MAX_ATTEMPTS; $i++) {
            $this->establecer($user, '111111')->assertStatus(422);
        }

        $this->establecer($user, $codigo)->assertStatus(422);
        $this->assertTrue(Hash::check('ClaveVieja2026', $user->fresh()->password));
    }

    public function test_un_codigo_de_otro_proposito_no_sirve(): void
    {
        $user = $this->cuenta();
        $this->postJson('/api/auth/forgot-password', ['email' => $user->email]);
        $codigoRestablecer = Mail::sent(OtpMail::class)->last()->code;

        $this->establecer($user, $codigoRestablecer)->assertStatus(422)->assertJsonValidationErrors('code');
        $this->assertTrue(Hash::check('ClaveVieja2026', $user->fresh()->password));
    }

    public function test_el_codigo_de_otra_cuenta_no_sirve(): void
    {
        $a = $this->cuenta();
        $b = $this->cuenta(null, ['email' => 'b.'.getmypid().'@test.com', 'password' => 'ClaveDeB2026']);
        $codigoDeA = $this->pedirCodigo($a);
        $this->comoNueva($b);

        $this->establecer($b, $codigoDeA)->assertStatus(422);
        $this->assertTrue(Hash::check('ClaveDeB2026', $b->fresh()->password));
    }

    public function test_un_email_en_el_request_se_ignora(): void
    {
        $user = $this->cuenta();
        $otro = $this->cuenta(null, ['email' => 'victima.'.getmypid().'@test.com', 'password' => 'ClaveDeLaOtra2026']);
        $codigo = $this->pedirCodigo($user);

        $this->establecer($user, $codigo, ['email' => $otro->email])->assertOk();

        $this->assertTrue(Hash::check(self::NUEVA, $user->fresh()->password));
        $this->assertTrue(Hash::check('ClaveDeLaOtra2026', $otro->fresh()->password));
    }

    public function test_una_contrasena_debil_o_distinta_no_gasta_el_codigo(): void
    {
        $user = $this->cuenta();
        $codigo = $this->pedirCodigo($user);

        $this->establecer($user, $codigo, ['password' => 'corta', 'password_confirmation' => 'corta'])->assertStatus(422)->assertJsonValidationErrors('password');
        $this->establecer($user, $codigo, ['password_confirmation' => 'NoCoincide2026'])->assertStatus(422)->assertJsonValidationErrors('password');

        $this->establecer($user, $codigo)->assertOk();
    }

    // ── Recuperación pública ────────────────────────────────────────────────

    public function test_la_recuperacion_publica_responde_igual_para_cualquier_correo(): void
    {
        $conGoogle = $this->cuenta('sin_definir', ['email' => 'cg.'.getmypid().'@test.com']);
        SocialAccount::forceCreate(['user_id' => $conGoogle->id, 'provider' => 'google', 'provider_id' => 'g-x']);
        $conClave = $this->cuenta('definida', ['email' => 'cc.'.getmypid().'@test.com']);

        $respuestas = collect([$conGoogle->email, $conClave->email, 'no.existe.'.getmypid().'@test.com'])
            ->map(fn ($correo) => $this->postJson('/api/auth/forgot-password', ['email' => $correo]))
            ->map(fn ($r) => [$r->status(), $r->json()]);

        $this->assertCount(1, $respuestas->unique(fn ($r) => json_encode($r)), 'La respuesta pública revela qué cuentas o métodos existen.');
    }

    public function test_el_codigo_de_establecer_no_deja_filas_con_otro_proposito(): void
    {
        $user = $this->cuenta();
        $this->pedirCodigo($user);

        $this->assertSame(1, EmailOtp::where('email', $user->email)->count());
        $this->assertSame(OtpService::SET_PASSWORD, EmailOtp::where('email', $user->email)->value('purpose'));
    }
}
