<?php

// El callback de Google solo se atiende si continúa un inicio hecho desde este navegador: state presente en la sesión y en la URL,
// coincidente, de un solo uso y con vigencia de 10 minutos, comprobado ANTES de canjear el código. Un rechazo no crea cuentas, no
// vincula Google, no toca la sesión autenticada y no deja valores sensibles en mensajes ni registros.

namespace Tests\Feature;

use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\Support\InicioOAuth;
use Tests\TestCase;

class SocialEstadoOAuthTest extends TestCase
{
    use InicioOAuth;
    use RefreshDatabase;

    private string $correo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->correo = 'estado.'.getmypid().'@test.com';
    }

    private function assertRechazado($respuesta): void
    {
        $respuesta->assertRedirect(config('app.frontend_url').'/login?error=oauth_estado');
        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('social_accounts', 0);
        $this->assertNull(session('google_pendiente'), 'Un rechazo no puede dejar un alta pendiente.');
    }

    public function test_el_inicio_guarda_el_state_y_la_hora_de_inicio(): void
    {
        $respuesta = $this->get('/api/auth/google/redirect');

        $respuesta->assertRedirect();
        $this->assertStringContainsString('state='.session('state'), $respuesta->headers->get('Location'));
        $this->assertEqualsWithDelta(now()->timestamp, (int) session('google_oauth_inicio'), 5);
    }

    public function test_sin_state_en_la_sesion_se_rechaza_antes_de_canjear_el_codigo(): void
    {
        // Es el ataque: un callback con el código del atacante abierto en el navegador de la víctima, sin inicio previo.
        $this->googleNoDebeConsultarse();

        $this->assertRechazado($this->callbackConEstado('estado-del-atacante'));
    }

    public function test_sin_state_en_la_url_se_rechaza(): void
    {
        $this->iniciarOAuth();
        $this->googleNoDebeConsultarse();

        $this->assertRechazado($this->callbackConEstado(null));
    }

    public function test_state_distinto_se_rechaza(): void
    {
        $this->iniciarOAuth();
        $this->googleNoDebeConsultarse();

        $this->assertRechazado($this->callbackConEstado('otro-state'));
    }

    public function test_el_state_es_de_un_solo_uso(): void
    {
        $estado = $this->iniciarOAuth();
        $this->googleResponde('google-1', $this->correo);

        $this->callbackConEstado($estado)->assertRedirect(config('app.frontend_url').'/completar-ficha?pendiente=google');

        // Segundo intento con el mismo state (reenvío del enlace, atrás, otra pestaña): ya no sirve.
        $this->flushSession();
        $this->googleNoDebeConsultarse();
        $this->assertRechazado($this->callbackConEstado($estado));
    }

    public function test_un_state_distinto_no_invalida_el_inicio_vigente(): void
    {
        $estado = $this->iniciarOAuth();
        // Si el rechazo llegara a canjear el código, assertRechazado fallaría: no crea cuenta ni redirige a la ficha.
        $this->googleResponde('google-1', $this->correo);
        $this->assertRechazado($this->callbackConEstado('otro-state'));

        // El inicio que sigue en curso (otra pestaña, un callback viejo que llegó antes) todavía se puede completar.
        $this->callbackConEstado($estado)->assertRedirect(config('app.frontend_url').'/completar-ficha?pendiente=google');
    }

    public function test_un_callback_de_un_inicio_viejo_no_rompe_el_inicio_nuevo(): void
    {
        // Dos inicios desde el mismo navegador: el callback del primero llega después del segundo.
        $this->iniciarOAuth();
        $estadoNuevo = $this->iniciarOAuth();
        $this->googleResponde('google-1', $this->correo);

        $this->assertRechazado($this->callbackConEstado('state-del-primer-inicio'));

        $this->callbackConEstado($estadoNuevo)->assertRedirect(config('app.frontend_url').'/completar-ficha?pendiente=google');
    }

    public function test_el_motivo_del_rechazo_queda_en_el_registro_sin_valores(): void
    {
        $this->iniciarOAuth();
        $this->googleNoDebeConsultarse();
        Log::spy();

        $this->callbackConEstado('state-distinto-secreto');

        Log::shouldHaveReceived('warning')->withArgs(function ($mensaje, $contexto = []) {
            return ($contexto['motivo'] ?? null) === 'distinto'
                && ! str_contains(json_encode($contexto).$mensaje, 'state-distinto-secreto');
        })->once();
    }

    public function test_sin_state_en_la_sesion_el_motivo_es_ausente(): void
    {
        // Lo que ocurre cuando el state ya se usó (reenvío del enlace, atrás): la sesión ya no lo tiene.
        $estado = $this->iniciarOAuth();
        $this->googleResponde('google-1', $this->correo);
        $this->callbackConEstado($estado);
        $this->flushSession();
        $this->googleNoDebeConsultarse();
        Log::spy();

        $this->callbackConEstado($estado);

        Log::shouldHaveReceived('warning')->withArgs(fn ($mensaje, $contexto = []) => ($contexto['motivo'] ?? null) === 'ausente')->once();
    }

    public function test_un_inicio_de_mas_de_diez_minutos_caduca(): void
    {
        $estado = $this->iniciarOAuth();
        $this->googleNoDebeConsultarse();
        $this->travel(11)->minutes();

        $this->assertRechazado($this->callbackConEstado($estado));
    }

    public function test_un_inicio_dentro_de_los_diez_minutos_sigue_vigente(): void
    {
        $estado = $this->iniciarOAuth();
        $this->googleResponde('google-1', $this->correo);
        $this->travel(9)->minutes();

        $this->callbackConEstado($estado)->assertRedirect(config('app.frontend_url').'/completar-ficha?pendiente=google');
    }

    public function test_un_inicio_sin_hora_registrada_no_se_acepta(): void
    {
        $estado = $this->iniciarOAuth();
        session()->forget('google_oauth_inicio');
        $this->googleNoDebeConsultarse();

        $this->assertRechazado($this->callbackConEstado($estado));
    }

    public function test_el_rechazo_no_deja_state_codigo_ni_tokens_en_mensajes_ni_registros(): void
    {
        $this->iniciarOAuth();
        $this->googleNoDebeConsultarse();
        Log::spy();

        $respuesta = $this->callbackConEstado('state-que-no-debe-aparecer', 'codigo-que-no-debe-aparecer');

        $ubicacion = (string) $respuesta->headers->get('Location');
        $this->assertStringNotContainsString('state-que-no-debe-aparecer', $ubicacion);
        $this->assertStringNotContainsString('codigo-que-no-debe-aparecer', $ubicacion);
        Log::shouldHaveReceived('warning')->atLeast()->once();
        Log::shouldHaveReceived('warning')->withArgs(function ($mensaje, $contexto = []) {
            $todo = $mensaje.' '.json_encode($contexto);

            return ! str_contains($todo, 'state-que-no-debe-aparecer') && ! str_contains($todo, 'codigo-que-no-debe-aparecer');
        })->atLeast()->once();
    }

    public function test_con_sesion_abierta_el_callback_no_canjea_el_codigo_ni_cambia_de_cuenta(): void
    {
        $usuario = User::factory()->create(['email' => 'abierta.'.getmypid().'@test.com', 'email_verified_at' => now()]);
        $estado = $this->iniciarOAuth();
        $this->googleNoDebeConsultarse();

        // Un callback ajeno (otro código, quizá de otra identidad de Google) llega a un navegador con sesión abierta.
        $respuesta = $this->actingAs($usuario)->callbackConEstado($estado);

        $respuesta->assertRedirect(config('app.frontend_url'));
        $this->assertAuthenticatedAs($usuario);
        $this->assertDatabaseCount('social_accounts', 0);
        $this->assertSame(1, User::count());
        $this->assertNull(session('state'), 'El state también se consume con sesión abierta.');
    }

    public function test_con_sesion_abierta_y_state_invalido_tambien_vuelve_al_front_sin_tocar_nada(): void
    {
        $usuario = User::factory()->create(['email' => 'abierta.'.getmypid().'@test.com', 'email_verified_at' => now()]);
        $this->googleNoDebeConsultarse();

        $this->actingAs($usuario)->callbackConEstado('state-falso')->assertRedirect(config('app.frontend_url'));

        $this->assertAuthenticatedAs($usuario);
        $this->assertDatabaseCount('social_accounts', 0);
    }

    public function test_un_inicio_valido_deja_el_alta_nueva_pendiente_de_ficha(): void
    {
        $estado = $this->iniciarOAuth();
        $this->googleResponde('google-nuevo', $this->correo);

        $this->callbackConEstado($estado)->assertRedirect(config('app.frontend_url').'/completar-ficha?pendiente=google');

        $this->assertGuest();
        $this->assertNotNull(session('google_pendiente'));
        $this->assertDatabaseCount('users', 0);
    }

    public function test_un_inicio_valido_permite_elegir_ficha_y_crear_la_cuenta(): void
    {
        $ficha = \App\Models\Ficha::create(['codigo' => 'E'.getmypid(), 'nombre_programa' => 'Programa de prueba', 'estado' => 'activa']);
        $estado = $this->iniciarOAuth();
        $this->googleResponde('google-nuevo', $this->correo);
        $this->callbackConEstado($estado);

        $this->postJson('/api/auth/google/pendiente/ficha', ['ficha_codigo' => $ficha->codigo])->assertCreated();

        $usuario = User::where('email', $this->correo)->firstOrFail();
        $this->assertAuthenticatedAs($usuario);
        $this->assertSame($ficha->id, $usuario->ficha_id);
        $this->assertDatabaseHas('social_accounts', ['user_id' => $usuario->id, 'provider_id' => 'google-nuevo']);
    }

    public function test_un_inicio_valido_deja_entrar_a_la_cuenta_ya_vinculada(): void
    {
        $usuario = User::factory()->create(['email' => $this->correo, 'email_verified_at' => now()]);
        SocialAccount::forceCreate(['user_id' => $usuario->id, 'provider' => 'google', 'provider_id' => 'google-viejo']);
        $estado = $this->iniciarOAuth();
        $this->googleResponde('google-viejo', $this->correo);

        $this->callbackConEstado($estado)->assertRedirect(config('app.frontend_url'));

        $this->assertAuthenticatedAs($usuario);
    }
}
