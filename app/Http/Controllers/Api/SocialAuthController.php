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

        return Socialite::driver($provider)->stateless()->redirect();
    }

    public function callback(Request $request, string $provider): RedirectResponse
    {
        abort_unless(in_array($provider, self::PROVIDERS, true), 404);
        $front = rtrim(config('app.frontend_url'), '/');

        try {
            $social = Socialite::driver($provider)->stateless()->user();
        } catch (\Throwable $e) {
            Log::error('Social login error: '.$e->getMessage());

            return redirect("$front/login?error=oauth");
        }

        $account = SocialAccount::where('provider', $provider)->where('provider_id', (string) $social->getId())->first();

        if ($account) {
            $user = $account->user;
        } else {
            if (! $social->getEmail()) {
                return redirect("$front/login?error=sin_correo");
            }
            // No se vincula por correo a cuentas existentes: evita que alguien tome una cuenta ajena.
            if (User::where('email', $social->getEmail())->exists()) {
                return redirect("$front/login?error=correo_en_uso");
            }

            // Google ya confirma el correo, así que se crea verificado y NO se
            // pide OTP. El rol inicial es aspirante; el instructor lo ubica en
            // su ficha y le asigna el rol después.
            $user = User::forceCreate([
                'name' => $social->getName() ?: ($social->getNickname() ?: $social->getEmail()),
                'email' => $social->getEmail(),
                'password' => Str::random(40), // no se usa; la cuenta entra por el proveedor
                'role' => Role::Aspirante,     // el rol NUNCA viene del proveedor
                'active' => true,
                'email_verified_at' => now(),
            ]);
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
}
