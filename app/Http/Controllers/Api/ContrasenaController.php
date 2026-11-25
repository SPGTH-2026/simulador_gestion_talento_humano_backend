<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\ContrasenaCambiadaMail;
use App\Services\ContrasenaDeCuenta;
use App\Services\OtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Establecer o cambiar la contraseña DESDE una sesión abierta. Tener la sesión no basta: siempre hace falta el código que se
 * envía al correo verificado de ESA sesión (el destinatario nunca sale del request), con propósito propio, vencimiento, un solo
 * uso y los límites existentes de envío y comprobación. Apagado por defecto (config seguridad.contrasena_desde_sesion).
 */
class ContrasenaController extends Controller
{
    public function __construct(private readonly OtpService $otp, private readonly ContrasenaDeCuenta $contrasenas) {}

    /** Paso 1: envía el código al correo de la sesión. Si el correo no puede enviarse se dice, porque quien pide es el dueño. */
    public function codigo(Request $request): JsonResponse
    {
        $this->exigirFuncionActiva();

        if (! $this->otp->send($request->user()->email, OtpService::SET_PASSWORD)) {
            return response()->json(['message' => 'No pudimos enviar el correo. Inténtalo de nuevo en unos minutos.'], 503);
        }

        return response()->json(['message' => 'Te enviamos un código a tu correo.']);
    }

    /** Paso 2: valida el código y fija la contraseña. */
    public function establecer(Request $request): JsonResponse
    {
        $this->exigirFuncionActiva();

        // Solo se validan estos campos: cualquier «email» que venga en el request se ignora por completo.
        $datos = $request->validate([
            'code' => ['required', 'string', 'size:6'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ]);

        $user = $request->user();

        if (! $this->otp->verify($user->email, OtpService::SET_PASSWORD, $datos['code'])) {
            throw ValidationException::withMessages(['code' => 'El código no es válido o expiró']);
        }

        DB::transaction(function () use ($user, $datos, $request) {
            // El cast 'password' => 'hashed' hace el hash solo. Evento comprobado: la persona eligió esta contraseña.
            $user->forceFill([
                'password' => $datos['password'],
                'password_estado' => ContrasenaDeCuenta::DEFINIDA,
                'remember_token' => Str::random(60),
            ])->save();

            // Cualquier otro código pendiente (restablecer o establecer) podría permitir un cambio posterior no autorizado.
            $this->otp->clear($user->email, OtpService::RESET_PASSWORD);
            $this->otp->clear($user->email, OtpService::SET_PASSWORD);

            // Se conserva la sesión actual (su hash de contraseña lo actualiza Sanctum al terminar esta petición) y se cierran
            // las demás: sus filas y sus tokens. Google sigue vinculado: no se toca social_accounts.
            // Con un token en lugar de cookie no hay sesión «actual»: todas las filas de sesión son ajenas.
            $sesionActual = $request->hasSession() ? $request->session()->getId() : null;
            DB::table('sessions')->where('user_id', $user->id)->when($sesionActual, fn ($q) => $q->where('id', '!=', $sesionActual))->delete();
            $actual = $user->currentAccessToken();
            $user->tokens()
                ->when($actual instanceof PersonalAccessToken, fn ($q) => $q->where('id', '!=', $actual->id))
                ->delete();
        });

        $this->avisarDelCambio($user->email);

        return response()->json([
            'message' => 'Tu contraseña quedó guardada. Cerramos tus otras sesiones.',
            'acceso' => $this->contrasenas->acceso($user->fresh()),
        ]);
    }

    private function exigirFuncionActiva(): void
    {
        abort_unless(config('seguridad.contrasena_desde_sesion'), 404);
    }

    /** El aviso nunca incluye la contraseña ni el código, y un fallo del correo no deshace el cambio. */
    private function avisarDelCambio(string $correo): void
    {
        try {
            Mail::to($correo)->send(new ContrasenaCambiadaMail(now()->timezone(config('app.timezone'))->format('d/m/Y H:i')));
        } catch (\Throwable $e) {
            Log::warning('No se pudo enviar el aviso de cambio de contraseña.', ['excepcion' => class_basename($e)]);
        }
    }
}
