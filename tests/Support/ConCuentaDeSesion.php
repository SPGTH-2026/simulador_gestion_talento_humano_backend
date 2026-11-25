<?php

namespace Tests\Support;

use App\Enums\Role;
use App\Mail\OtpMail;
use App\Models\User;
use App\Services\OtpService;
use Illuminate\Support\Facades\Mail;

/** Ayudantes de las pruebas de «establecer o cambiar la contraseña desde la sesión». */
trait ConCuentaDeSesion
{
    private const NUEVA = 'NuevaClave2026';

    protected function prepararContrasenaDeSesion(): void
    {
        config(['seguridad.contrasena_desde_sesion' => true]);
        Mail::fake();
    }

    private function cuenta(?string $estado = null, array $extra = []): User
    {
        $user = User::factory()->create(array_merge([
            'email' => 'sesion.'.getmypid().'@test.com',
            'email_verified_at' => now(),
            'password' => 'ClaveVieja2026',
        ], $extra));
        $user->forceFill(['password_estado' => $estado, 'role' => Role::Aspirante, 'active' => true])->save();

        return $user;
    }

    /** Cambia de cuenta dentro de la misma prueba sin arrastrar la sesión (ni el hash de contraseña) de la anterior. */
    private function comoNueva(User $user): void
    {
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->actingAs($user);
    }

    /** Pide el código desde la sesión y lo lee del correo simulado (nunca se imprime). */
    private function pedirCodigo(User $user): string
    {
        $this->actingAs($user)->postJson('/api/auth/password/codigo')->assertOk();
        $correo = Mail::sent(OtpMail::class)->last();
        $this->assertSame(OtpService::SET_PASSWORD, $correo->purpose);

        return $correo->code;
    }

    private function establecer(User $user, string $codigo, array $extra = [])
    {
        return $this->actingAs($user)->postJson('/api/auth/password/establecer', array_merge([
            'code' => $codigo, 'password' => self::NUEVA, 'password_confirmation' => self::NUEVA,
        ], $extra));
    }
}
