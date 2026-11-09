<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Memo de parámetros por petición: los catálogos (estados, tipos, etc.) casi
        // no cambian, y el listado de convocatorias los resolvía con una consulta
        // por cada llamada. "scoped" se reinicia en cada petición y en cada prueba.
        $this->app->scoped('parametros.memo', fn () => new \ArrayObject());
    }

    public function boot(): void
    {
        // 1) Expiración por INACTIVIDAD. Se ejecuta antes de que Sanctum actualice last_used_at,
        //    así que compara contra el último uso real. La vida máxima la controla
        //    sanctum.expiration / expires_at del token.
        Sanctum::authenticateAccessTokensUsing(function ($token, bool $isValid) {
            if (! $isValid) {
                return false;
            }

            $last = $token->last_used_at ?? $token->created_at;
            if ($last->lt(now()->subMinutes((int) config('sanctum.idle_minutes', 15)))) {
                $token->delete();

                return false;
            }

            return (bool) $token->tokenable?->active; // usuario desactivado = sin acceso
        });

        // 2) Permisos: habilita middleware('can:documentos:validar'), $user->can(...) y @can
        Gate::before(fn (User $user, string $ability) => in_array($ability, $user->permissions(), true) ? true : null);

        // 3) Anti fuerza bruta
        RateLimiter::for('login', fn (Request $r) => Limit::perMinute(5)->by($r->ip().'|'.strtolower((string) $r->input('email'))));

        // 4) OTP: un código de 6 dígitos son 1 millón de combinaciones, así que se limita
        //    el envío (para no inundar el correo) y la comprobación (para no adivinar).
        RateLimiter::for('otp-send', fn (Request $r) => [
            Limit::perMinute(3)->by($r->ip().'|'.strtolower((string) $r->input('email'))),
            Limit::perHour(10)->by($r->ip().'|'.strtolower((string) $r->input('email'))),
        ]);

        RateLimiter::for('otp-check', fn (Request $r) => Limit::perMinute(5)->by($r->ip()));
    }
}
