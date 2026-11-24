<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Ficha;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La ficha es obligatoria para el aspirante en la API, no solo en la pantalla: sin ficha no usa las rutas de negocio.
 */
class FichaObligatoriaTest extends TestCase
{
    use RefreshDatabase;

    private function ficha(): Ficha
    {
        return Ficha::create(['codigo' => '7000001', 'nombre_programa' => 'Programa']);
    }

    private function aspirante(?int $fichaId): \App\Models\User
    {
        return \App\Models\User::factory()->create([
            'role' => Role::Aspirante,
            'active' => true,
            'email_verified_at' => now(),
            'ficha_id' => $fichaId,
        ]);
    }

    public function test_aspirante_sin_ficha_recibe_403_en_rutas_de_negocio(): void
    {
        $sinFicha = $this->aspirante(null);

        $this->actingAs($sinFicha)->withHeaders(['Accept' => 'application/json'])
            ->getJson('/api/postulaciones')
            ->assertForbidden()
            ->assertJsonPath('code', 'ficha_requerida');
    }

    public function test_aspirante_sin_ficha_puede_leer_su_sesion_el_catalogo_y_su_sexo(): void
    {
        $sinFicha = $this->aspirante(null);

        $this->actingAs($sinFicha)->withHeaders(['Accept' => 'application/json'])
            ->getJson('/api/auth/me')
            ->assertOk();

        $this->actingAs($sinFicha)->withHeaders(['Accept' => 'application/json'])
            ->getJson('/api/parametros')
            ->assertOk();

        $this->actingAs($sinFicha)->withHeaders(['Accept' => 'application/json'])
            ->getJson('/api/perfil')
            ->assertOk();
    }

    public function test_aspirante_con_ficha_usa_las_rutas_de_negocio(): void
    {
        $conFicha = $this->aspirante($this->ficha()->id);

        $this->actingAs($conFicha)->withHeaders(['Accept' => 'application/json'])
            ->getJson('/api/postulaciones')
            ->assertOk();
    }
}
