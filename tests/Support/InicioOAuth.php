<?php

namespace Tests\Support;

use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as UsuarioGoogle;
use Mockery;

/**
 * Representa el recorrido real del acceso con Google en los tests: el inicio (GET /api/auth/google/redirect) lo atiende el
 * controlador y Socialite de verdad, que guardan el state en la sesión. Lo ÚNICO que se simula es lo que ocurre fuera de
 * SPGTH: el canje del código con Google (Socialite::driver(..)->stateless()->user()). Estas pruebas NO prueban un acceso real
 * con Google (consentimiento, tokens, correo verificado por Google): eso solo se comprueba a mano con una cuenta real.
 */
trait InicioOAuth
{
    /** Hace el inicio real y devuelve el state que quedó en la sesión (y que Google devolvería en el callback). */
    protected function iniciarOAuth(): string
    {
        $this->get('/api/auth/google/redirect')->assertRedirect();
        $estado = (string) session('state');
        $this->assertNotSame('', $estado, 'El inicio no guardó el state en la sesión.');

        return $estado;
    }

    /** Google «responde» con este usuario al canjear el código. */
    protected function googleResponde(string $sub, string $correo, bool $verificado = true, ?string $nombre = 'Ana Google'): void
    {
        $social = new UsuarioGoogle;
        $social->id = $sub;
        $social->name = $nombre;
        $social->email = $correo;
        $social->user = ['email' => $correo, 'email_verified' => $verificado];

        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('stateless')->andReturnSelf();
        $provider->shouldReceive('user')->andReturn($social);
        Socialite::shouldReceive('driver')->andReturn($provider);
    }

    /** Google no debe llegar a consultarse: el callback tiene que rechazar antes de canjear el código. */
    protected function googleNoDebeConsultarse(): void
    {
        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('stateless')->andReturnSelf();
        $provider->shouldNotReceive('user');
        Socialite::shouldReceive('driver')->andReturn($provider);
    }

    protected function callbackConEstado(?string $estado, string $codigo = 'codigo-secreto-123')
    {
        $consulta = ['code' => $codigo];
        if ($estado !== null) {
            $consulta['state'] = $estado;
        }

        return $this->get('/api/auth/google/callback?'.http_build_query($consulta));
    }
}
