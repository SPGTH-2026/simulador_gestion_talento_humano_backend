<?php

// «¿Olvidaste tu contraseña?» es el único camino para establecer o restablecer la contraseña. Debe servir también a quien se registró
// con Google (cuenta con contraseña generada por el sistema), sin revelar a nadie qué métodos de acceso tiene un correo, y sin
// quitarle el acceso con Google. Con la función de contraseña desde sesión apagada (la bandera por defecto).

namespace Tests\Feature;

use App\Enums\Role;
use App\Mail\OtpMail;
use App\Models\Ficha;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\OtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\Support\InicioOAuth;
use Tests\TestCase;

class RecuperacionConGoogleTest extends TestCase
{
    use InicioOAuth;
    use RefreshDatabase;

    private function cuentaDeGoogle(): User
    {
        $ficha = Ficha::create(['codigo' => 'RG'.getmypid(), 'nombre_programa' => 'Programa de prueba', 'estado' => 'activa']);
        $user = User::forceCreate([
            'name' => 'Persona Google', 'email' => 'rg.'.getmypid().'@test.com', 'password' => 'ClaveGenerada-NoSeUsa-2026',
            'password_estado' => 'sin_definir', 'role' => Role::Aspirante, 'active' => true, 'ficha_id' => $ficha->id,
            'email_verified_at' => now(),
        ]);
        SocialAccount::forceCreate(['user_id' => $user->id, 'provider' => 'google', 'provider_id' => 'google-rg-1']);

        return $user;
    }

    public function test_con_la_funcion_apagada_las_rutas_de_contrasena_desde_sesion_no_existen(): void
    {
        $this->assertFalse(config('seguridad.contrasena_desde_sesion'));
        $user = $this->cuentaDeGoogle();

        $this->actingAs($user)->postJson('/api/auth/password/codigo')->assertNotFound();
        $this->actingAs($user)->postJson('/api/auth/password/establecer', ['code' => '123456'])->assertNotFound();
    }

    public function test_quien_se_registro_con_google_restablece_su_contrasena_por_la_recuperacion_publica(): void
    {
        Mail::fake();
        $user = $this->cuentaDeGoogle();

        $this->postJson('/api/auth/forgot-password', ['email' => $user->email])->assertOk();
        $codigo = Mail::sent(OtpMail::class)->last();
        $this->assertSame(OtpService::RESET_PASSWORD, $codigo->purpose);

        $this->postJson('/api/auth/reset-password', [
            'email' => $user->email, 'code' => $codigo->code, 'password' => 'NuevaClave2026', 'password_confirmation' => 'NuevaClave2026',
        ])->assertOk();

        $user->refresh();
        $this->assertTrue(Hash::check('NuevaClave2026', $user->password));
        $this->assertSame('definida', $user->password_estado);
    }

    public function test_restablecer_la_contrasena_conserva_el_acceso_con_google(): void
    {
        Mail::fake();
        $user = $this->cuentaDeGoogle();
        $this->postJson('/api/auth/forgot-password', ['email' => $user->email])->assertOk();
        $this->postJson('/api/auth/reset-password', [
            'email' => $user->email, 'code' => Mail::sent(OtpMail::class)->last()->code,
            'password' => 'NuevaClave2026', 'password_confirmation' => 'NuevaClave2026',
        ])->assertOk();

        // El vínculo con Google sigue ahí y el acceso con Google entra a la misma cuenta.
        $this->assertDatabaseHas('social_accounts', ['user_id' => $user->id, 'provider' => 'google', 'provider_id' => 'google-rg-1']);
        $estado = $this->iniciarOAuth();
        $this->googleResponde('google-rg-1', $user->email);
        $this->callbackConEstado($estado)->assertRedirect(config('app.frontend_url'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_la_respuesta_publica_es_igual_para_cuenta_de_google_con_clave_o_inexistente(): void
    {
        Mail::fake();
        $google = $this->cuentaDeGoogle();
        $respuestas = collect([$google->email, 'no.existe.'.getmypid().'@test.com'])
            ->map(fn ($correo) => $this->postJson('/api/auth/forgot-password', ['email' => $correo])->assertOk()->json());

        $this->assertCount(1, $respuestas->map(fn ($r) => json_encode($r))->unique(), 'La respuesta revela qué cuentas o métodos existen.');
    }
}
