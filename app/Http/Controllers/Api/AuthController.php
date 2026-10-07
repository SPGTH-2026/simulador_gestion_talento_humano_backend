<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Ficha;
use App\Models\User;
use App\Services\OtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', 'unique:users,email'],
            'password' => ['required', Password::min(8)->letters()->numbers()],
            // Opcional: el aspirante puede dejar su ficha. Se valida que exista.
            'ficha_codigo' => ['nullable', 'string', 'max:50'],
        ]);

        $ficha = null;
        if (($data['ficha_codigo'] ?? '') !== '') {
            $ficha = Ficha::where('codigo', $data['ficha_codigo'])->first();

            if (! $ficha) {
                throw ValidationException::withMessages(['ficha_codigo' => 'La ficha no existe']);
            }
        }

        // Todo registro público es Aspirante; el rol nunca viene del request.
        $user = User::forceCreate([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'role' => Role::Aspirante,
            'active' => true,
            'ficha_id' => $ficha?->id,
        ]);

        // No se abre sesión aquí: el flujo es registrarse, ir a la pantalla de
        // inicio de sesión y entrar con correo y contraseña. La verificación
        // por OTP ocurre después del login.
        return response()->json($this->body($user, null, null), 201);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => 'Correo o contraseña incorrectos']);
        }
        if (! $user->active) {
            throw ValidationException::withMessages(['email' => 'Tu usuario está desactivado. Contacta al instructor']);
        }

        // Sesión (cookie HttpOnly) en lugar de token Bearer.
        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return response()->json($this->body($user, null, null));
    }

    /** Exige el correo verificado: es lógica de negocio, no de autenticación. */
    public function guardarFicha(Request $request): JsonResponse
    {
        $user = $request->user();

        // Solo el aspirante se auto-asocia a una ficha; instructor y super admin
        // no pertenecen a ninguna, y el aprendiz ya tiene ficha (la asigna el instructor).
        if ($user->role !== Role::Aspirante) {
            throw ValidationException::withMessages(['ficha_codigo' => 'Tu cuenta ya tiene una ficha asignada']);
        }

        $data = $request->validate([
            'ficha_codigo' => ['required', 'string', 'max:50'],
        ]);

        $ficha = Ficha::where('codigo', $data['ficha_codigo'])->first();

        if (! $ficha) {
            throw ValidationException::withMessages(['ficha_codigo' => 'La ficha no existe']);
        }

        // Asociar la ficha NO cambia el rol: el aspirante la deja registrada y
        // el instructor lo convierte en aprendiz desde Usuarios.
        $user->forceFill(['ficha_id' => $ficha->id])->save();

        return response()->json($this->body($user, null, null));
    }

    /** También sirve de "latido": el front lo llama cuando hay actividad. */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json($this->body($user, null, null));
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Sesión cerrada']);
    }

    /**
     * Paso 1 de recuperar contraseña: envía el código.
     * La respuesta es idéntica exista o no el correo: no revelamos qué correos están
     * registrados (si no, cualquiera podría averiguar las cuentas de la app).
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email']]);

        $user = User::where('email', $data['email'])->first();

        if ($user) {
            app(OtpService::class)->send($user->email, OtpService::RESET_PASSWORD);
        }

        return response()->json([
            'message' => 'Si el correo está registrado, te enviamos un código de verificación.',
        ]);
    }

    /** Paso 2: valida el código y cambia la contraseña. */
    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'code' => ['required', 'string', 'size:6'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ]);

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! app(OtpService::class)->verify($user->email, OtpService::RESET_PASSWORD, $data['code'])) {
            throw ValidationException::withMessages(['code' => 'El código no es válido o expiró']);
        }

        // El cast 'password' => 'hashed' hace el hash solo; no usar Hash::make.
        $user->forceFill(['password' => $data['password']])->save();

        // Cerrar cualquier sesión o token abierto: si alguien entró con la contraseña
        // vieja, recuperar la cuenta no sirve si conserva el acceso.
        DB::table('sessions')->where('user_id', $user->id)->delete();
        $user->tokens()->delete();

        return response()->json(['message' => 'Tu contraseña fue actualizada']);
    }

    /** Reenvía el código de confirmación de correo. */
    public function sendVerification(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return response()->json(['message' => 'Tu correo ya está confirmado']);
        }

        if (! app(OtpService::class)->send($user->email, OtpService::VERIFY_EMAIL)) {
            return response()->json([
                'message' => 'No pudimos enviar el correo. Intenta de nuevo en un momento.',
            ], 503);
        }

        return response()->json(['message' => 'Te enviamos un código a tu correo']);
    }

    /** Confirma el correo con el código recibido. */
    public function confirmVerification(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'size:6']]);

        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return response()->json(['ok' => true]);
        }

        if (! app(OtpService::class)->verify($user->email, OtpService::VERIFY_EMAIL, $data['code'])) {
            throw ValidationException::withMessages(['code' => 'El código no es válido o expiró']);
        }

        $user->markEmailAsVerified();

        return response()->json(['ok' => true]);
    }

    public static function issueToken(User $user): array
    {
        $expiresAt = now()->addMinutes((int) config('sanctum.expiration', 480));
        $token = $user->createToken('spa', ['*'], $expiresAt);

        return [$token->plainTextToken, $expiresAt];
    }

    private function issue(User $user): array
    {
        [$plain, $expiresAt] = self::issueToken($user);

        return $this->body($user, $plain, $expiresAt);
    }

    private function body(User $user, ?string $token, $expiresAt): array
    {
        $response = [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role->value,
                'subrole' => $user->subrole,
                'email_verified' => (bool) $user->email_verified_at,
                'permissions' => $user->permissions(),
                // Ficha del usuario (aprendices/aspirantes) y alcance del que
                // gestiona (instructores: las suyas; super admin: todas).
                'ficha' => $user->ficha_id
                    ? [
                        'id' => $user->ficha_id,
                        'codigo' => $user->ficha?->codigo,
                        'nombre_programa' => $user->ficha?->nombre_programa,
                    ]
                    : null,
                'ficha_ids' => $user->fichaIds(),
            ],
        ];

        // Con sesión no hay token: solo se incluye si se emite uno (flujo social heredado).
        if ($token !== null) {
            $response['token'] = $token;
            $response['expires_at'] = $expiresAt?->toIso8601String();
        }

        return $response;
    }
}
