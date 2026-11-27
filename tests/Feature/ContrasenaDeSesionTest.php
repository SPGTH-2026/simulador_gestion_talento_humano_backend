<?php

// Establecer o cambiar la contraseña desde una sesión abierta, con código al correo de ESA sesión, y la marca de estado de la
// contraseña: solo cambia con eventos comprobados, las cuentas históricas son «desconocido» y la marca no decide quién entra.

namespace Tests\Feature;

use App\Mail\ContrasenaCambiadaMail;
use App\Mail\OtpMail;
use App\Models\Ficha;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\Support\ConCuentaDeSesion;
use Tests\Support\InicioOAuth;
use Tests\TestCase;

class ContrasenaDeSesionTest extends TestCase
{
    use ConCuentaDeSesion;
    use InicioOAuth;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararContrasenaDeSesion();
    }

    // ── Estado de la contraseña ─────────────────────────────────────────────

    public function test_las_cuentas_historicas_son_desconocidas_y_nada_se_infiere(): void
    {
        $user = $this->cuenta(null);
        // Ni siquiera una cuenta de Google creada en el mismo instante se deduce: sin marca, desconocido.
        SocialAccount::forceCreate(['user_id' => $user->id, 'provider' => 'google', 'provider_id' => 'g-1']);

        $this->actingAs($user)->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('user.acceso', ['google' => true, 'contrasena' => 'desconocido']);
    }

    public function test_me_expone_solo_los_metodos_de_la_propia_cuenta_en_sus_tres_estados(): void
    {
        foreach ([[null, 'desconocido'], ['definida', 'definida'], ['sin_definir', 'sin_definir']] as [$marca, $esperado]) {
            $user = $this->cuenta($marca, ['email' => "estado.$esperado.".getmypid().'@test.com']);
            $this->comoNueva($user);
            $this->getJson('/api/auth/me')
                ->assertJsonPath('user.acceso.contrasena', $esperado)
                ->assertJsonPath('user.acceso.google', false);
        }
    }

    public function test_el_registro_y_la_recuperacion_publica_marcan_definida(): void
    {
        $ficha = Ficha::create(['codigo' => 'P'.getmypid(), 'nombre_programa' => 'Programa', 'estado' => 'activa']);
        $this->postJson('/api/auth/register', [
            'name' => 'Persona Nueva', 'email' => 'nueva.'.getmypid().'@test.com', 'password' => 'ClaveRegistro2026',
            'ficha_codigo' => $ficha->codigo,
        ])->assertCreated();
        $this->assertSame('definida', User::where('email', 'nueva.'.getmypid().'@test.com')->value('password_estado'));

        // Cuenta de Google (sin definir): la recuperación pública sigue funcionando y la deja definida.
        $google = $this->cuenta('sin_definir', ['email' => 'google.'.getmypid().'@test.com']);
        $this->postJson('/api/auth/forgot-password', ['email' => $google->email])->assertOk();
        $codigo = Mail::sent(OtpMail::class)->last()->code;
        $this->postJson('/api/auth/reset-password', [
            'email' => $google->email, 'code' => $codigo, 'password' => self::NUEVA, 'password_confirmation' => self::NUEVA,
        ])->assertOk();
        $google->refresh();
        $this->assertTrue(Hash::check(self::NUEVA, $google->password));
        $this->assertSame('definida', $google->password_estado);
    }

    public function test_una_cuenta_nueva_de_google_queda_sin_definir(): void
    {
        $ficha = Ficha::create(['codigo' => 'G'.getmypid(), 'nombre_programa' => 'Programa', 'estado' => 'activa']);
        $estado = $this->iniciarOAuth();
        $this->googleResponde('google-nuevo', 'gnuevo.'.getmypid().'@test.com');
        $this->callbackConEstado($estado);
        $this->postJson('/api/auth/google/pendiente/ficha', ['ficha_codigo' => $ficha->codigo])->assertCreated();

        $this->assertSame('sin_definir', User::where('email', 'gnuevo.'.getmypid().'@test.com')->value('password_estado'));
    }

    public function test_la_marca_no_decide_quien_inicia_sesion(): void
    {
        // «sin_definir» con una contraseña que SÍ se conoce entra; «definida» con una incorrecta no entra.
        $a = $this->cuenta('sin_definir', ['email' => 'a.'.getmypid().'@test.com', 'password' => 'ClaveConocida2026']);
        $b = $this->cuenta('definida', ['email' => 'b.'.getmypid().'@test.com', 'password' => 'OtraClave2026']);

        $this->postJson('/api/auth/login', ['email' => $a->email, 'password' => 'ClaveConocida2026'])->assertOk();
        $this->app['auth']->guard('web')->logout();
        $this->postJson('/api/auth/login', ['email' => $b->email, 'password' => 'ClaveIncorrecta2026'])->assertStatus(422);
    }

    // ── Función apagada, sesión y correo verificado ─────────────────────────

    public function test_apagada_por_defecto_las_rutas_no_existen(): void
    {
        config(['seguridad.contrasena_desde_sesion' => false]);
        $user = $this->cuenta();

        $this->actingAs($user)->postJson('/api/auth/password/codigo')->assertNotFound();
        $this->actingAs($user)->postJson('/api/auth/password/establecer', ['code' => '123456', 'password' => self::NUEVA, 'password_confirmation' => self::NUEVA])->assertNotFound();
        Mail::assertNothingSent();
    }

    public function test_exige_sesion_y_correo_verificado(): void
    {
        $this->postJson('/api/auth/password/codigo')->assertUnauthorized();
        $this->postJson('/api/auth/password/establecer', [])->assertUnauthorized();

        $sinVerificar = $this->cuenta(null, ['email_verified_at' => null]);
        $this->actingAs($sinVerificar)->postJson('/api/auth/password/codigo')->assertForbidden();
        Mail::assertNothingSent();
    }

    public function test_el_codigo_solo_se_envia_al_correo_de_la_sesion_aunque_el_request_diga_otro(): void
    {
        $user = $this->cuenta();
        $otro = $this->cuenta(null, ['email' => 'otro.'.getmypid().'@test.com']);

        $this->actingAs($user)->postJson('/api/auth/password/codigo', ['email' => $otro->email, 'to' => $otro->email])->assertOk();

        Mail::assertSent(OtpMail::class, 1);
        Mail::assertSent(OtpMail::class, fn ($m) => $m->hasTo($user->email) && ! $m->hasTo($otro->email));
        $this->assertDatabaseMissing('email_otps', ['email' => $otro->email]);
    }

    // ── Establecer / cambiar ────────────────────────────────────────────────

    public function test_en_los_tres_estados_se_establece_con_el_codigo_y_queda_definida(): void
    {
        foreach ([null, 'sin_definir', 'definida'] as $i => $estadoInicial) {
            $user = $this->cuenta($estadoInicial, ['email' => "tres.$i.".getmypid().'@test.com']);
            SocialAccount::forceCreate(['user_id' => $user->id, 'provider' => 'google', 'provider_id' => "g-$i"]);

            $this->comoNueva($user);
            $codigo = $this->pedirCodigo($user);
            $this->establecer($user, $codigo)
                ->assertOk()
                ->assertJsonPath('acceso', ['google' => true, 'contrasena' => 'definida']);

            $user->refresh();
            $this->assertTrue(Hash::check(self::NUEVA, $user->password));
            $this->assertSame('definida', $user->password_estado);
            // Google se conserva.
            $this->assertSame(1, $user->socialAccounts()->count());
        }
    }

    public function test_conserva_la_sesion_actual_y_cierra_las_demas_sesiones_y_tokens(): void
    {
        $user = $this->cuenta('sin_definir');
        $user->createToken('otro-dispositivo');
        DB::table('sessions')->insert([
            ['id' => 'sesion-ajena-1', 'user_id' => $user->id, 'ip_address' => '203.0.113.7', 'user_agent' => 'otro', 'payload' => '', 'last_activity' => time()],
            ['id' => 'sesion-ajena-2', 'user_id' => $user->id, 'ip_address' => '203.0.113.8', 'user_agent' => 'otro', 'payload' => '', 'last_activity' => time()],
        ]);
        $recuerdoAntes = $user->remember_token;
        $codigo = $this->pedirCodigo($user);

        DB::enableQueryLog();
        $this->establecer($user, $codigo)->assertOk();
        $consultas = collect(DB::getQueryLog())->filter(fn ($q) => str_starts_with($q['query'], 'delete from "sessions"') || str_starts_with($q['query'], 'delete from `sessions`'));

        $this->assertDatabaseMissing('sessions', ['id' => 'sesion-ajena-1']);
        $this->assertDatabaseMissing('sessions', ['id' => 'sesion-ajena-2']);
        // La sesión actual queda fuera del borrado: la consulta excluye el id de ESTA sesión.
        $this->assertCount(1, $consultas);
        $this->assertStringContainsString('!= ?', $consultas->first()['query']);
        $this->assertContains(session()->getId(), $consultas->first()['bindings']);
        $this->assertSame(0, $user->tokens()->count());
        $this->assertNotSame($recuerdoAntes, $user->fresh()->remember_token);
    }

    public function test_con_token_se_conserva_el_token_en_uso_y_se_revocan_los_demas(): void
    {
        $user = $this->cuenta();
        $enUso = $user->createToken('en-uso')->plainTextToken;
        $user->createToken('ajeno');
        $this->withToken($enUso)->postJson('/api/auth/password/codigo')->assertOk();
        $codigo = Mail::sent(OtpMail::class)->last()->code;

        $this->withToken($enUso)->postJson('/api/auth/password/establecer', [
            'code' => $codigo, 'password' => self::NUEVA, 'password_confirmation' => self::NUEVA,
        ])->assertOk();

        $this->assertSame(1, $user->tokens()->count());
        $this->assertSame('en-uso', $user->tokens()->first()->name);
    }

    public function test_avisa_del_cambio_sin_contrasena_ni_codigo(): void
    {
        $user = $this->cuenta();
        $codigo = $this->pedirCodigo($user);

        $this->establecer($user, $codigo)->assertOk();

        Mail::assertSent(ContrasenaCambiadaMail::class, 1);
        Mail::assertSent(ContrasenaCambiadaMail::class, function ($mail) use ($user, $codigo) {
            $html = $mail->render();

            return $mail->hasTo($user->email)
                && ! str_contains($html, self::NUEVA) && ! str_contains($html, $codigo) && ! str_contains($html, 'ClaveVieja2026');
        });
    }

    public function test_invalida_los_codigos_pendientes_incompatibles(): void
    {
        $user = $this->cuenta();
        // Un código de recuperación pedido antes (p. ej. por otra persona con el correo a la vista) no debe servir después.
        $this->postJson('/api/auth/forgot-password', ['email' => $user->email])->assertOk();
        $codigoReset = Mail::sent(OtpMail::class)->last()->code;
        $codigoSet = $this->pedirCodigo($user);

        $this->establecer($user, $codigoSet)->assertOk();

        $this->assertDatabaseMissing('email_otps', ['email' => $user->email]);
        $this->postJson('/api/auth/reset-password', [
            'email' => $user->email, 'code' => $codigoReset, 'password' => 'OtraNueva2026', 'password_confirmation' => 'OtraNueva2026',
        ])->assertStatus(422)->assertJsonValidationErrors('code');
        $this->assertTrue(Hash::check(self::NUEVA, $user->fresh()->password));
    }

    public function test_la_recuperacion_publica_tambien_invalida_el_codigo_de_establecer_pendiente(): void
    {
        $user = $this->cuenta();
        $codigoSet = $this->pedirCodigo($user);
        $this->postJson('/api/auth/forgot-password', ['email' => $user->email]);
        $codigoReset = Mail::sent(OtpMail::class)->last()->code;
        $this->postJson('/api/auth/reset-password', [
            'email' => $user->email, 'code' => $codigoReset, 'password' => 'ViaPublica2026', 'password_confirmation' => 'ViaPublica2026',
        ])->assertOk();

        $this->establecer($user, $codigoSet)->assertStatus(422)->assertJsonValidationErrors('code');
        $this->assertTrue(Hash::check('ViaPublica2026', $user->fresh()->password));
    }
}
