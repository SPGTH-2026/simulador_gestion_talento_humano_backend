<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

class SocialAuthController extends Controller
{
    private const PROVIDERS = ['google'];

    public function redirect(string $provider)
    {
        abort_unless(in_array($provider, self::PROVIDERS, true), 404);

        // Con estado: el 'state' se guarda en la sesión y se valida en el
        // callback. Si la sesión no llega al callback (front y back en
        // dominios distintos) el callback sigue funcionando en modo
        // stateless; ver usuarioSocialite().
        return Socialite::driver($provider)->redirect();
    }

    public function callback(Request $request, string $provider): RedirectResponse
    {
        abort_unless(in_array($provider, self::PROVIDERS, true), 404);
        $front = rtrim(config('app.frontend_url'), '/');

        try {
            $social = $this->usuarioSocialite($request, $provider);
        } catch (\Throwable $e) {
            // Lo más común es que el código ya se haya gastado: doble clic en
            // "Entrar con Google", botón atrás o recargar el callback. Google
            // responde invalid_grant y no hay nada que recuperar, pero el
            // usuario ya tiene sesión: se le lleva al front en vez de
            // mostrarle un error.
            if (Auth::guard('web')->check()) {
                return redirect($front);
            }

            Log::warning('No se pudo completar el inicio de sesión con Google.', [
                'provider' => $provider,
                'motivo' => $e->getMessage(),
                'url' => $request->fullUrl(),
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

            // Google ya confirmó que el correo pertenece a quien entra, así que si
            // existe un usuario con ese correo (ej. el super admin creado con
            // app:make-admin) se le enlaza esta cuenta social en lugar de crear
            // otro usuario. Así "Entrar con Google" reconoce al mismo dueño y
            // conserva su rol; el rol nunca se toma del proveedor.
            $user = User::where('email', $social->getEmail())->first();

            if (! $user) {
                // Aspirante nuevo: se crea verificado (Google confirmó el correo)
                // y sin OTP. El rol inicial es aspirante; el instructor lo ubica
                // en su ficha y le asigna el rol después.
                $user = User::forceCreate([
                    'name' => $social->getName() ?: ($social->getNickname() ?: $social->getEmail()),
                    'email' => $social->getEmail(),
                    'password' => Str::random(40), // no se usa; la cuenta entra por el proveedor
                    'role' => Role::Aspirante,     // el rol NUNCA viene del proveedor
                    'active' => true,
                    'email_verified_at' => now(),
                ]);
            }

            $user->socialAccounts()->create(['provider' => $provider, 'provider_id' => (string) $social->getId()]);
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

    /**
     * Usuario de Google del callback.
     *
     * El flujo normal es CON estado: el 'state' se guardó en la sesión al
     * redirigir y aquí se valida (protección CSRF). Si la sesión no llegó al
     * callback —típico cuando el front y el back se sirven en dominios
     * distintos— se sigue en modo stateless: el código de Google sigue
     * siendo de un solo uso y está atado a nuestro client_id y redirect_uri.
     */
    private function usuarioSocialite(Request $request, string $provider): \Laravel\Socialite\Two\User
    {
        $driver = Socialite::driver($provider);
        $estadoSesion = (string) $request->session()->get('state', '');
        $estadoUrl = (string) $request->input('state', '');

        if ($estadoSesion === '') {
            return $driver->stateless()->user();
        }

        if ($estadoUrl === '' || ! hash_equals($estadoSesion, $estadoUrl)) {
            throw new \RuntimeException('El estado de la autenticación no coincide.');
        }

        return $driver->user();
    }
}
