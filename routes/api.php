<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\SocialAuthController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('register', [AuthController::class, 'register'])->middleware('throttle:login');
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login');

    // Recuperar contraseña: públicas (no hay sesión todavía).
    Route::post('forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:otp-send');
    Route::post('reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:otp-check');

    // OAuth: el callback llega desde accounts.google.com, no desde el front.
    // Sanctum statefulApi() solo da sesión si el request viene de un dominio
    // stateful, así que aquí se fuerza 'web' para poder iniciar sesión.
    Route::get('{provider}/redirect', [SocialAuthController::class, 'redirect'])->middleware('web');
    Route::get('{provider}/callback', [SocialAuthController::class, 'callback'])->middleware('web');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('me', [AuthController::class, 'me']);
        Route::post('logout', [AuthController::class, 'logout']);

        // Confirmar correo: requieren sesión pero NO 'verified' (si no, no se podría confirmar).
        Route::post('verification/send', [AuthController::class, 'sendVerification'])->middleware('throttle:otp-send');
        Route::post('verification/confirm', [AuthController::class, 'confirmVerification'])->middleware('throttle:otp-check');

        // El aspirante se asocia a su ficha (Google o registro por correo).
        // Exige correo verificado: es una acción de negocio, no de autenticación.
        Route::middleware('verified')->post('ficha', [AuthController::class, 'guardarFicha']);
    });
});

// ── Usuarios y fichas ──────────────────────────────────────────────────
// Lo tienen el super admin y el instructor (usuarios:gestionar). 'verified'
// exige correo confirmado, igual que el resto de rutas de negocio.
Route::middleware(['auth:sanctum', 'verified', 'can:usuarios:gestionar'])->group(function () {
    Route::get('usuarios', [UserController::class, 'index']);
    Route::patch('usuarios/{user}', [UserController::class, 'update']);
    Route::get('fichas', [UserController::class, 'fichas']);
});

// ── Ejemplo: cada ruta de negocio declara el permiso que exige (403 si el rol no lo tiene) ──
// Las rutas de negocio deben llevar 'verified' además de 'can:' para exigir
// que el correo esté confirmado. Al crear cada endpoint:
//   Route::middleware(['auth:sanctum', 'verified'])
//       ->get('convocatorias', ...)->middleware('can:convocatorias:ver');
