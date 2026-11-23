<?php

use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Sesión + cookies para peticiones que vengan del front (lista SANCTUM_STATEFUL_DOMAINS).
        $middleware->statefulApi();
        // Solo confían en las cabeceras X-Forwarded-* los proxies de la red privada (o los que
        // indique TRUSTED_PROXIES). Con '*', cualquier cliente falsificaba su IP y burlaba los rate limiters.
        $middleware->trustProxies(at: array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('TRUSTED_PROXIES', '10.0.0.0/8,172.16.0.0/12,192.168.0.0/16,127.0.0.1/32')),
        ))));
        // Server-Timing: solo agrega la cabecera si SERVER_TIMING=true.
        $middleware->append(\App\Http\Middleware\ServerTiming::class);
        // Sesión por cookie: inactividad y usuario desactivado (ver VerificarSesionActiva).
        $middleware->appendToGroup('api', \App\Http\Middleware\VerificarSesionActiva::class);

        $middleware->alias([
            'verified' => EnsureEmailIsVerified::class,
        ]);

        // Backend solo-API: si un invitado (p. ej. sesión expirada) llama a /api,
        // debe recibir 401 JSON. Sin esto, Laravel intenta redirigir a la ruta web
        // 'login' (que no existe) y responde 500. Los navegadores van al login del front.
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('api/*')
            ? null
            : rtrim((string) config('app.frontend_url'), '/').'/login');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
