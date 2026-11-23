<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sesiones por cookie: un usuario desactivado o una sesión inactiva deja de tener acceso (VerificarSesionActiva).
 */
class SesionActivaTest extends TestCase
{
    use RefreshDatabase;

    private const MENSAJE = 'Tu sesión ya no es válida. Vuelve a iniciar sesión.';

    public function test_usuario_desactivado_pierde_la_sesion(): void
    {
        $usuario = User::factory()->create(['active' => false, 'role' => Role::Aspirante]);

        $this->actingAs($usuario)->getJson('/api/auth/me')
            ->assertStatus(401)
            ->assertJsonPath('message', self::MENSAJE);
    }

    public function test_sesion_inactiva_mas_de_quince_minutos_se_cierra(): void
    {
        $usuario = User::factory()->create(['active' => true, 'role' => Role::Aspirante]);

        $this->actingAs($usuario)
            ->withSession(['ultimo_acceso' => now()->subMinutes(20)->timestamp])
            ->getJson('/api/auth/me')
            ->assertStatus(401)
            ->assertJsonPath('message', self::MENSAJE);
    }

    public function test_sesion_con_actividad_reciente_sigue_abierta_y_renueva_el_ultimo_acceso(): void
    {
        $usuario = User::factory()->create(['active' => true, 'role' => Role::Aspirante]);

        $this->actingAs($usuario)
            ->withSession(['ultimo_acceso' => now()->subMinutes(2)->timestamp])
            ->getJson('/api/auth/me')
            ->assertOk();
    }
}
