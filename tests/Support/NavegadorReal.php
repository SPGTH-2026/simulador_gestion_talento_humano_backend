<?php

namespace Tests\Support;

use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Testing\TestResponse;
use Laravel\Socialite\Contracts\Factory as SocialiteFactory;
use Mockery\MockInterface;

/**
 * Imita a un navegador entre peticiones, en lugar de compartir la sesión en memoria como hace el cliente de pruebas:
 *  - guarda las cookies tal como el servidor las emitió (cifradas) y solo las devuelve en peticiones posteriores;
 *  - usa el driver de sesión 'database', como producción, y entre peticiones descarta la sesión, la autenticación y el
 *    manejador cargados en memoria, como si cada petición la atendiera un proceso nuevo;
 *  - envía Referer/Origin como el navegador: las peticiones de la SPA vienen del front (Sanctum las trata como stateful) y
 *    la vuelta de Google viene de accounts.google.com.
 * Nunca se imprimen los valores de las cookies.
 */
trait NavegadorReal
{
    /** @var array<string, string> */
    private array $tarro = [];

    private string $front = 'http://localhost:5173';

    protected function prepararNavegador(): void
    {
        config([
            'session.driver' => 'database',
            'app.frontend_url' => $this->front,
            'sanctum.stateful' => ['localhost:5173'],
        ]);
        $this->reiniciarServidor();
    }

    /** Petición de la SPA (axios con withCredentials): JSON, desde el origen del front y con el token XSRF si lo hay. */
    protected function desdeLaSpa(string $metodo, string $uri, array $datos = []): TestResponse
    {
        $cabeceras = ['Accept' => 'application/json', 'Referer' => $this->front.'/login', 'Origin' => $this->front];
        if (isset($this->tarro['XSRF-TOKEN'])) {
            $cabeceras['X-XSRF-TOKEN'] = urldecode($this->tarro['XSRF-TOKEN']);
        }

        return $this->enviar($metodo, $uri, $datos, $cabeceras);
    }

    /** Navegación completa desde una página del front (window.location.href = '/api/auth/google/redirect'). */
    protected function navegarDesdeElFront(string $uri): TestResponse
    {
        return $this->enviar('GET', $uri, [], ['Referer' => $this->front.'/login']);
    }

    /** Google devuelve el navegador al callback: navegación de nivel superior que llega desde otro sitio. */
    protected function volverDeGoogle(?string $estado, string $codigo = 'codigo-de-prueba'): TestResponse
    {
        $consulta = array_filter(['code' => $codigo, 'state' => $estado], fn ($v) => $v !== null);

        return $this->enviar('GET', '/api/auth/google/callback?'.http_build_query($consulta), [], [
            'Referer' => 'https://accounts.google.com/',
        ]);
    }

    /** Inicio real desde el front; devuelve el state que viajaría a Google en la URL de autorización. */
    protected function iniciarDesdeElFront(): string
    {
        $respuesta = $this->navegarDesdeElFront('/api/auth/google/redirect')->assertRedirect();
        parse_str((string) parse_url((string) $respuesta->headers->get('Location'), PHP_URL_QUERY), $consulta);
        $this->assertNotEmpty($consulta['state'] ?? '', 'La URL de Google no lleva state.');

        return (string) $consulta['state'];
    }

    /** La SPA carga la página de acceso: pide la cookie CSRF y comprueba si hay sesión (como AuthContext). */
    protected function cargarLaSpa(): TestResponse
    {
        $this->desdeLaSpa('GET', '/sanctum/csrf-cookie');

        return $this->desdeLaSpa('GET', '/api/auth/me');
    }

    /** Id de la sesión que el navegador tiene ahora en su cookie (se descifra solo para compararlo; nunca se imprime). */
    protected function idDeSesionDelNavegador(): string
    {
        $cifrada = $this->tarro[config('session.cookie')] ?? null;
        $this->assertNotNull($cifrada, 'El navegador no tiene cookie de sesión.');

        return CookieValuePrefix::remove($this->app['encrypter']->decrypt($cifrada, false));
    }

    private function enviar(string $metodo, string $uri, array $datos, array $cabeceras): TestResponse
    {
        $this->reiniciarServidor();

        // Las cookies van tal como las emitió el servidor (cifradas): call() no las vuelve a cifrar.
        $esJson = ($cabeceras['Accept'] ?? null) === 'application/json' && $metodo !== 'GET';
        $servidor = $this->transformHeadersToServerVars($cabeceras + ($esJson ? ['Content-Type' => 'application/json'] : []));
        $respuesta = $this->call($metodo, $uri, $esJson ? [] : $datos, $this->tarro, [], $servidor, $esJson ? json_encode($datos) : null);

        foreach ($respuesta->headers->getCookies() as $cookie) {
            if ($cookie->isCleared()) {
                unset($this->tarro[$cookie->getName()]);
            } else {
                $this->tarro[$cookie->getName()] = (string) $cookie->getValue();
            }
        }

        return $respuesta;
    }

    /** Lo que un proceso nuevo de PHP no tendría: sesión, manejador de sesión y usuario autenticado de la petición anterior. */
    private function reiniciarServidor(): void
    {
        $this->app['session']->forgetDrivers();
        $this->app->forgetInstance('session.store');
        $this->app['auth']->forgetGuards();

        // Socialite guarda su driver con la Request de la primera petición; un proceso nuevo no la tendría. Si ya se simuló
        // a Google (mock), no hay nada que olvidar.
        $socialite = $this->app->make(SocialiteFactory::class);
        if (! $socialite instanceof MockInterface && method_exists($socialite, 'forgetDrivers')) {
            $socialite->forgetDrivers();
        }
    }
}
