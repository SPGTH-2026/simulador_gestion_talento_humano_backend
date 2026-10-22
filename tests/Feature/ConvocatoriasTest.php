<?php

namespace App\Tests\Feature;

use App\Enums\Role;
use App\Models\Parametro;
use App\Models\User;
use Database\Seeders\ParametroSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pruebas de acceso, permisos y catálogo de parámetros que se mantienen tras el
 * Paso 1 (schema y catálogo nuevos). Las pruebas de creación, estados, edición,
 * aislamiento y paginación dependen de la API del módulo y vuelven reescritas
 * en los pasos 2a–2c (cada paso entrega sus propias pruebas).
 */
class ConvocatoriasTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ParametroSeeder::class);
    }

    private function usuario(Role $role, ?string $subrole = null, array $extra = []): User
    {
        return User::factory()->create($extra + [
            'role' => $role,
            'subrole' => $subrole,
            'active' => true,
            'email_verified_at' => now(),
        ]);
    }

    // ── Acceso y permisos ──────────────────────────────────────────────

    public function test_guest_recibe_401_en_parametros_y_convocatorias(): void
    {
        $this->getJson('/api/parametros')->assertUnauthorized();
        $this->getJson('/api/convocatorias')->assertUnauthorized();
        $this->postJson('/api/convocatorias', [])->assertUnauthorized();
    }

    public function test_correo_sin_verificar_recibe_403(): void
    {
        $u = User::factory()->unverified()->create([
            'role' => Role::Aprendiz,
            'subrole' => 'general',
            'active' => true,
        ]);

        $this->actingAs($u)->getJson('/api/parametros')->assertForbidden();
    }

    public function test_aspirante_puede_ver_parametros_pero_no_crear_convocatorias(): void
    {
        $aspirante = $this->usuario(Role::Aspirante);

        $this->actingAs($aspirante)->getJson('/api/parametros')->assertOk();
        $this->actingAs($aspirante)->postJson('/api/convocatorias', [])->assertForbidden();
    }

    // ── Parámetros ─────────────────────────────────────────────────────

    public function test_parametros_por_tema_lista_solo_activos_ordenados(): void
    {
        // Desactivar uno para comprobar que se excluye.
        $cerrada = Parametro::porTemaYCodigo('estado_convocatoria', 'cerrada');
        $cerrada?->update(['activo' => false]);

        $respuesta = $this->actingAs($this->usuario(Role::Aspirante))
            ->getJson('/api/parametros?tema=estado_convocatoria')
            ->assertOk();

        $data = $respuesta->json('data');
        $this->assertCount(3, $data);
        $this->assertSame(['borrador', 'publicada', 'en_proceso'], array_column($data, 'codigo'));
    }

    public function test_parametros_con_tema_desconocido_recibe_404(): void
    {
        $this->actingAs($this->usuario(Role::Aspirante))
            ->getJson('/api/parametros?tema=no_existe')
            ->assertNotFound();
    }

    public function test_parametros_sin_tema_devuelve_el_catalogo_agrupado_por_tema(): void
    {
        $respuesta = $this->actingAs($this->usuario(Role::Aspirante))
            ->getJson('/api/parametros')
            ->assertOk();

        $grupos = $respuesta->json('data');
        $this->assertCount(10, $grupos);

        $total = array_sum(array_map(fn (array $g) => count($g['parametros']), $grupos));
        $this->assertSame(43, $total);

        foreach ($grupos as $grupo) {
            $this->assertSame(['tema', 'nombre', 'parametros'], array_keys($grupo));
            foreach ($grupo['parametros'] as $parametro) {
                // Solo campos públicos: sin tema_id ni timestamps.
                $this->assertSame(
                    ['id', 'codigo', 'nombre', 'es_sistema', 'es_defecto'],
                    array_keys($parametro)
                );
            }
        }
    }

}