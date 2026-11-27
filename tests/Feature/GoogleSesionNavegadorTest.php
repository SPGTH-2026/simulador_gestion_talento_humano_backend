<?php

// Acceso con Google con transporte real de cookies entre peticiones (ver Tests\Support\NavegadorReal). Reproduce el fallo de
// producción: la SPA ya había abierto una sesión (csrf-cookie y /auth/me) y el inicio, que llega desde el front, perdía el
// state porque la sesión se iniciaba dos veces (Sanctum stateful + grupo 'web') y no se guardaba. El callback lo rechazaba
// con motivo 'ausente'.

namespace Tests\Feature;

use App\Models\Ficha;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\InicioOAuth;
use Tests\Support\NavegadorReal;
use Tests\TestCase;

class GoogleSesionNavegadorTest extends TestCase
{
    use InicioOAuth;
    use NavegadorReal;
    use RefreshDatabase;

    private string $correo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararNavegador();
        $this->correo = 'navegador.'.getmypid().'@test.com';
    }

    private function vinculada(): User
    {
        $usuario = User::factory()->create(['email' => $this->correo, 'email_verified_at' => now()]);
        SocialAccount::forceCreate(['user_id' => $usuario->id, 'provider' => 'google', 'provider_id' => 'google-ana']);

        return $usuario;
    }

    private function assertSesionDe(User $usuario): void
    {
        $this->desdeLaSpa('GET', '/api/auth/me')->assertOk()->assertJsonPath('user.email', $usuario->email);
    }

    public function test_cuenta_vinculada_entra_con_la_spa_ya_cargada(): void
    {
        $usuario = $this->vinculada();
        $this->cargarLaSpa()->assertUnauthorized();
        $estado = $this->iniciarDesdeElFront();
        $this->googleResponde('google-ana', $this->correo);

        $this->volverDeGoogle($estado)->assertRedirect($this->front);

        $this->assertSesionDe($usuario);
    }

    public function test_alta_nueva_elige_ficha_y_queda_con_sesion(): void
    {
        $ficha = Ficha::create(['codigo' => 'N'.getmypid(), 'nombre_programa' => 'Programa de prueba', 'estado' => 'activa']);
        $this->cargarLaSpa();
        $estado = $this->iniciarDesdeElFront();
        $this->googleResponde('google-nuevo', $this->correo);

        $this->volverDeGoogle($estado)->assertRedirect($this->front.'/completar-ficha?pendiente=google');
        $this->desdeLaSpa('GET', '/api/auth/google/pendiente')->assertOk()->assertJsonPath('data.email', $this->correo);
        $this->desdeLaSpa('POST', '/api/auth/google/pendiente/ficha', ['ficha_codigo' => $ficha->codigo])->assertCreated();

        $usuario = User::where('email', $this->correo)->firstOrFail();
        $this->assertSame($ficha->id, $usuario->ficha_id);
        $this->assertSesionDe($usuario);
    }

    public function test_callback_sin_inicio_se_canjea_en_modo_permisivo_temporal(): void
    {
        // TEMPORAL: sin state en la sesión se canjea igual (90a9af4). Un código ajeno o gastado lo rechaza Google.
        $this->cargarLaSpa();
        $this->googleRechazaElCodigo();

        $this->volverDeGoogle('state-ajeno')->assertRedirect($this->front.'/login?error=oauth_codigo');
        $this->desdeLaSpa('GET', '/api/auth/me')->assertUnauthorized();
        $this->assertDatabaseCount('social_accounts', 0);
    }

    public function test_state_presente_y_distinto_se_sigue_rechazando(): void
    {
        $this->cargarLaSpa();
        $this->iniciarDesdeElFront();
        $this->googleNoDebeConsultarse();

        $this->volverDeGoogle('state-ajeno')->assertRedirect($this->front.'/login?error=oauth_estado');
        $this->desdeLaSpa('GET', '/api/auth/me')->assertUnauthorized();
    }

    public function test_tras_un_callback_invalido_un_intento_nuevo_funciona(): void
    {
        $usuario = $this->vinculada();
        $this->cargarLaSpa();
        $this->iniciarDesdeElFront();
        $this->volverDeGoogle(null)->assertRedirect($this->front.'/login?error=oauth_estado');

        $estado = $this->iniciarDesdeElFront();
        $this->googleResponde('google-ana', $this->correo);
        $this->volverDeGoogle($estado)->assertRedirect($this->front);

        $this->assertSesionDe($usuario);
    }

    public function test_dos_pestanas_el_callback_viejo_no_rompe_el_nuevo(): void
    {
        $usuario = $this->vinculada();
        $this->cargarLaSpa();
        $estadoViejo = $this->iniciarDesdeElFront();  // pestaña A
        $estadoNuevo = $this->iniciarDesdeElFront();  // pestaña B: su state reemplaza al de A en la sesión
        $this->googleResponde('google-ana', $this->correo);

        $this->volverDeGoogle($estadoViejo)->assertRedirect($this->front.'/login?error=oauth_estado');
        $this->volverDeGoogle($estadoNuevo)->assertRedirect($this->front);

        $this->assertSesionDe($usuario);
    }

    public function test_pestana_antigua_con_sesion_abierta_no_cambia_de_cuenta(): void
    {
        $usuario = $this->vinculada();
        $this->cargarLaSpa();
        $estado = $this->iniciarDesdeElFront();
        $this->googleResponde('google-ana', $this->correo);
        $this->volverDeGoogle($estado)->assertRedirect($this->front);

        // La pestaña vieja (o «atrás») repite el callback: vuelve al front sin canjear otra vez ni cerrar la sesión.
        $this->volverDeGoogle($estado)->assertRedirect($this->front);

        $this->assertSesionDe($usuario);
        $this->assertSame(1, SocialAccount::count());
    }

    public function test_el_inicio_desde_el_front_conserva_la_sesion_que_ya_existia(): void
    {
        $this->cargarLaSpa();
        $antes = $this->idDeSesionDelNavegador();

        $this->iniciarDesdeElFront();

        // Antes del arreglo el inicio descartaba la cookie y emitía la de una sesión nueva que nunca llegaba a la base.
        $this->assertTrue($antes === $this->idDeSesionDelNavegador(), 'El inicio cambió la sesión del navegador.');
        $this->assertDatabaseHas('sessions', ['id' => $antes]);
    }
}
