<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Models\EmailOtp;
use App\Models\Ficha;
use App\Http\Controllers\Controller;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\ContrasenaDeCuenta;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Facades\Socialite;

class SocialAuthController extends Controller
{
    private const PROVIDERS = ['google'];

    /** Clave de sesión con los datos de Google de un aspirante que todavía no eligió ficha. */
    private const CLAVE_PENDIENTE = 'google_pendiente';

    /** Clave donde Socialite guarda el state al iniciar. */
    private const CLAVE_STATE = 'state';

    /** Hora (timestamp) en que este navegador inició el acceso con Google. */
    private const CLAVE_INICIO = 'google_oauth_inicio';

    /** Vigencia de un inicio de acceso con Google: pasado este tiempo hay que volver a empezar. */
    private const VIGENCIA_INICIO_SEGUNDOS = 600;

    public function redirect(Request $request, string $provider)
    {
        abort_unless(in_array($provider, self::PROVIDERS, true), 404);

        // El inicio deja en la SESIÓN de este navegador el state (lo guarda Socialite) y la hora. El callback solo se atiende
        // si trae ese mismo state, una sola vez y dentro de la vigencia; ver estadoDelInicioValido().
        $respuesta = Socialite::driver($provider)->redirect();
        $request->session()->put(self::CLAVE_INICIO, now()->timestamp);

        return $respuesta;
    }

    public function callback(Request $request, string $provider): RedirectResponse
    {
        abort_unless(in_array($provider, self::PROVIDERS, true), 404);
        $front = rtrim(config('app.frontend_url'), '/');

        // Con la sesión ya abierta (doble clic, «atrás», recargar, o un enlace ajeno) el callback NO canjea el código ni vincula
        // nada: se consume el state y se vuelve al front. Así un callback recibido no puede cambiar la cuenta de la sesión.
        if (Auth::guard('web')->check()) {
            $this->estadoDelInicioValido($request);

            return redirect($front);
        }

        // Antes de canjear el código con Google: el intento tiene que continuar un inicio hecho desde ESTE navegador.
        if (! $this->estadoDelInicioValido($request)) {
            Log::warning('Acceso con Google rechazado: el inicio no es válido (state ausente, distinto, ya usado o caducado).', [
                'provider' => $provider,
            ]);

            return redirect("$front/login?error=oauth_estado");
        }

        try {
            // La validación del state ya se hizo arriba (y se consumió), por eso el canje no repite la de Socialite.
            $social = Socialite::driver($provider)->stateless()->user();
        } catch (\Throwable $e) {
            // Lo más común es que el código ya se haya gastado: Google responde invalid_grant y no hay nada que recuperar.
            // No se registran el mensaje, la URL ni el código: pueden contener valores sensibles.
            Log::warning('No se pudo canjear el código de Google.', [
                'provider' => $provider,
                'excepcion' => class_basename($e),
            ]);

            return redirect("$front/login?error=oauth_codigo");
        }

        $account = SocialAccount::where('provider', $provider)->where('provider_id', (string) $social->getId())->first();

        if ($account) {
            $user = $account->user;
        } else {
            if (! $social->getEmail()) {
                return redirect("$front/login?error=sin_correo");
            }

            // Solo se enlaza o crea una cuenta con un correo que el proveedor confirmó. Sin esto, una cuenta social con
            // un correo sin verificar podía tomar el usuario existente que tiene ese mismo correo.
            if ((($social->user['email_verified'] ?? false) === true) === false) {
                return redirect("$front/login?error=correo_no_verificado");
            }

            // Coincidir el correo NO basta para vincular. La identidad de una cuenta de Google es su identificador (getId), no el
            // correo, que puede cambiar de dueño. Se vincula a una cuenta existente solo cuando hay evidencia de control:
            //  - Google confirmó el correo (comprobado arriba) y
            //  - la cuenta no está desactivada ni ya vinculada a OTRA identidad de Google, y
            //  - si el correo de la cuenta NO estaba verificado, quien la creó pudo ser otra persona (pre-secuestro): se vincula,
            //    pero antes se le retiran todas las credenciales que dejó (contraseña, sesiones, tokens y códigos).
            $user = User::where('email', $social->getEmail())->first();

            if (! $user) {
                // Aspirante nuevo: la cuenta NO se crea todavía. Sin ficha no puede existir, así que los datos de Google
                // quedan en la sesión hasta que el aspirante elige su ficha (ver completarPendiente).
                $request->session()->put(self::CLAVE_PENDIENTE, [
                    'provider' => $provider,
                    'provider_id' => (string) $social->getId(),
                    'email' => (string) $social->getEmail(),
                    'name' => (string) ($social->getName() ?: ($social->getNickname() ?: $social->getEmail())),
                ]);

                return redirect("$front/completar-ficha?pendiente=google");
            }

            if (! $user->active) {
                return redirect("$front/login?error=desactivado");
            }

            if ($user->socialAccounts()->where('provider', $provider)->exists()) {
                Log::warning('Intento de vincular una segunda identidad de Google a una cuenta ya vinculada.', ['user_id' => $user->id]);

                return redirect("$front/login?error=google_distinto");
            }

            try {
                DB::transaction(function () use ($user, $provider, $social) {
                    if ($user->email_verified_at === null) {
                        $this->retirarCredencialesPrevias($user, (string) ($social->getName() ?: $social->getEmail()));
                    }
                    $user->socialAccounts()->create(['provider' => $provider, 'provider_id' => (string) $social->getId()]);
                });
            } catch (UniqueConstraintViolationException) {
                // Dos callbacks simultáneos con la misma identidad: el otro ya la vinculó.
                return redirect("$front/login?error=oauth");
            }
        }

        if (! $user->active) {
            return redirect("$front/login?error=desactivado");
        }

        // Google ya confirmó el correo: se marca como verificado (también
        // rescata a cuentas creadas por callbacks antiguos que quedaron en
        // 'null') y logueo directo, sin OTP por volver a entrar.
        $user->forceFill(['email_verified_at' => now()])->save();

        // Sesión con cookie, igual que login(): el token ya no viaja en la URL.
        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        // El front decide qué hacer según el perfil (ej. completar la ficha).
        return redirect($front);
    }

    /** Datos de Google pendientes de completar con la ficha (solo lectura, para la pantalla de ficha). */
    public function pendiente(Request $request): JsonResponse
    {
        $datos = $request->session()->get(self::CLAVE_PENDIENTE);
        abort_unless(is_array($datos), 404, 'No hay un registro pendiente con Google.');

        return response()->json(['data' => ['email' => $datos['email'], 'name' => $datos['name']]]);
    }

    /**
     * Crea la cuenta del aspirante que entró con Google, ya con su ficha, verificada y con la sesión abierta. Sin ficha no
     * se crea nada: la ficha es obligatoria.
     */
    public function completarPendiente(Request $request): JsonResponse
    {
        $datos = $request->session()->get(self::CLAVE_PENDIENTE);
        if (! is_array($datos)) {
            return response()->json(['message' => 'No hay un registro pendiente con Google.'], 404);
        }

        $validados = $request->validate([
            'ficha_codigo' => ['required', 'string', 'max:50'],
        ]);

        $ficha = Ficha::where('codigo', $validados['ficha_codigo'])->first();
        if (! $ficha) {
            throw ValidationException::withMessages(['ficha_codigo' => 'La ficha no existe']);
        }

        if (User::where('email', $datos['email'])->exists()) {
            $request->session()->forget(self::CLAVE_PENDIENTE);
            throw ValidationException::withMessages(['ficha_codigo' => 'Ya existe una cuenta con ese correo. Entra de nuevo.']);
        }

        $user = DB::transaction(function () use ($datos, $ficha) {
            $creado = User::forceCreate([
                'name' => $datos['name'],
                'email' => $datos['email'],
                'password' => Str::random(40), // no se usa; la cuenta entra por el proveedor
                'password_estado' => ContrasenaDeCuenta::SIN_DEFINIR, // generada por el sistema, no elegida por la persona
                'role' => Role::Aspirante,     // el rol NUNCA viene del proveedor
                'ficha_id' => $ficha->id,
                'active' => true,
                'email_verified_at' => now(),
            ]);
            $creado->socialAccounts()->create([
                'provider' => $datos['provider'],
                'provider_id' => $datos['provider_id'],
            ]);

            return $creado;
        });

        $request->session()->forget(self::CLAVE_PENDIENTE);
        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return response()->json(['data' => ['id' => $user->id, 'email' => $user->email]], 201);
    }

    /**
     * La cuenta existía sin haber verificado nunca su correo: no hay prueba de que quien la creó controle ese buzón. Se deja
     * sin nada utilizable de esa etapa: contraseña (aleatoria; solo se recupera con el correo), recordar sesión, sesiones,
     * tokens y códigos pendientes. Los datos que eligió esa persona tampoco se heredan: en un aspirante, el nombre sale de
     * Google y la ficha la elige quien entra; en personal creado por un administrador se conservan rol y ficha.
     */
    private function retirarCredencialesPrevias(User $user, string $nombreDeGoogle): void
    {
        $user->forceFill(['password' => Str::random(40), 'password_estado' => ContrasenaDeCuenta::SIN_DEFINIR, 'remember_token' => null]);
        if ($user->role === Role::Aspirante) {
            $user->forceFill(['name' => $nombreDeGoogle, 'ficha_id' => null]);
        }
        $user->save();

        DB::table('sessions')->where('user_id', $user->id)->delete();
        $user->tokens()->delete();
        EmailOtp::where('email', $user->email)->delete();
        DB::table('password_reset_tokens')->where('email', $user->email)->delete();
    }

    /**
     * Comprueba, y CONSUME, el inicio de acceso con Google de esta sesión: el state debe estar en la sesión y en la URL, ser
     * idéntico, no haberse usado antes (se retira de la sesión en esta misma llamada, acierte o falle) y el inicio tener menos
     * de VIGENCIA_INICIO_SEGUNDOS. No hay modo alternativo sin state.
     */
    private function estadoDelInicioValido(Request $request): bool
    {
        $enSesion = (string) $request->session()->pull(self::CLAVE_STATE, '');
        $inicio = (int) $request->session()->pull(self::CLAVE_INICIO, 0);
        $enUrl = (string) $request->query('state', '');

        if ($enSesion === '' || $enUrl === '' || ! hash_equals($enSesion, $enUrl)) {
            return false;
        }

        $transcurrido = now()->timestamp - $inicio;

        return $inicio > 0 && $transcurrido >= 0 && $transcurrido <= self::VIGENCIA_INICIO_SEGUNDOS;
    }
}
