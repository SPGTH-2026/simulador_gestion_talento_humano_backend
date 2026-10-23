<?php

namespace App\Tests\Feature;

use App\Enums\Role;
use App\Models\Ficha;
use App\Models\User;
use Database\Seeders\ParametroSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Decisión del equipo sobre el aprendiz con subrol 'general':
 *
 *  - SÍ gestiona convocatorias: el permiso 'convocatorias:gestionar' lo tiene
 *    (config/permissions.php) y por eso crea, edita, cambia de estado y cierra.
 *  - NO gestiona fichas ni usuarios: esas rutas exigen 'usuarios:gestionar',
 *    que el aprendiz no tiene. Responde 403.
 *
 * Esta prueba fija ese contrato; si el comportamiento cambia, falla y obliga a
 * revisar la decisión.
 */
class AprendizGeneralPermisosTest extends TestCase
{
    use RefreshDatabase;

    private static int $codigoSecuencia = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ParametroSeeder::class);
    }

    // ── Helpers (mismos que las pruebas existentes) ─────────────────────

    private function ficha(string $codigo = '3173334'): Ficha
    {
        return Ficha::create([
            'codigo' => $codigo,
            'nombre_programa' => 'Análisis y Desarrollo de Software',
        ]);
    }

    private function usuario(Role $role, array $extra = []): User
    {
        return User::factory()->create($extra + [
            'role' => $role,
            'subrole' => null,
            'active' => true,
            'email_verified_at' => now(),
        ]);
    }

    private function aprendizGeneral(Ficha $ficha): User
    {
        return $this->usuario(Role::Aprendiz, [
            'subrole' => 'general',
            'ficha_id' => $ficha->id,
        ]);
    }

    /** Cuerpo de alta con todo lo que necesita el ciclo completo hasta cerrar. */
    private function cuerpoConvocatoria(): array
    {
        $hoy = now('America/Bogota');

        return [
            'nombre' => 'Convocatoria del aprendiz general',
            'cargo' => 'Instructor de software',
            'area' => 'Formación',
            'descripcion' => 'Convocatoria para fijar los permisos del aprendiz general.',
            'vacantes' => 1,
            'fecha_apertura' => $hoy->toDateString(),
            'fecha_cierre' => $hoy->copy()->addDays(7)->toDateString(),
        ];
    }

    // ── Convocatorias: sí puede gestionar ───────────────────────────────

    public function test_el_aprendiz_general_crea_edita_y_cierra_una_convocatoria(): void
    {
        $aprendiz = $this->aprendizGeneral($this->ficha());

        $creada = $this->actingAs($aprendiz)
            ->postJson('/api/convocatorias', $this->cuerpoConvocatoria())
            ->assertCreated()
            ->json('data');

        $id = $creada['id'];

        // Editar mientras está en borrador.
        $this->actingAs($aprendiz)
            ->patchJson("/api/convocatorias/{$id}", ['nombre' => 'Nombre editado'])
            ->assertOk()
            ->assertJsonPath('data.nombre', 'Nombre editado');

        // Avanzar el candado un paso por vez hasta cerrar.
        $this->actingAs($aprendiz)
            ->patchJson("/api/convocatorias/{$id}/estado", ['estado' => 'publicada'])
            ->assertOk()
            ->assertJsonPath('data.estado.codigo', 'publicada');

        $this->actingAs($aprendiz)
            ->patchJson("/api/convocatorias/{$id}/estado", ['estado' => 'en_proceso'])
            ->assertOk()
            ->assertJsonPath('data.estado.codigo', 'en_proceso');

        $this->actingAs($aprendiz)
            ->patchJson("/api/convocatorias/{$id}/estado", ['estado' => 'cerrada'])
            ->assertOk()
            ->assertJsonPath('data.estado.codigo', 'cerrada');
    }

    // ── Fichas: no puede gestionarlas ───────────────────────────────────

    public function test_el_aprendiz_general_no_puede_crear_una_ficha(): void
    {
        $this->actingAs($this->aprendizGeneral($this->ficha()))
            ->postJson('/api/fichas', [
                'codigo' => (string) (900000 + ++self::$codigoSecuencia),
                'nombre_programa' => 'Ficha que no debe crearse',
            ])
            ->assertForbidden();
    }

    public function test_el_aprendiz_general_no_puede_editar_ni_cerrar_una_ficha(): void
    {
        $ficha = $this->ficha();
        $aprendiz = $this->aprendizGeneral($ficha);

        $this->actingAs($aprendiz)
            ->patchJson("/api/fichas/{$ficha->id}", ['nombre_programa' => 'Renombrada'])
            ->assertForbidden();

        // "Cerrar" una ficha es PATCH estado -> 'cerrada' (las fichas no se borran).
        $this->actingAs($aprendiz)
            ->patchJson("/api/fichas/{$ficha->id}", ['estado' => 'cerrada'])
            ->assertForbidden();
    }

    public function test_el_aprendiz_general_no_puede_asignar_instructores(): void
    {
        $ficha = $this->ficha();
        $aprendiz = $this->aprendizGeneral($ficha);
        $instructor = $this->usuario(Role::Instructor);

        $this->actingAs($aprendiz)
            ->postJson("/api/fichas/{$ficha->id}/instructores", ['usuario_id' => $instructor->id])
            ->assertForbidden();
    }

    // ── Usuarios: no puede listarlos ────────────────────────────────────

    public function test_el_aprendiz_general_no_accede_al_listado_de_usuarios(): void
    {
        $this->actingAs($this->aprendizGeneral($this->ficha()))
            ->getJson('/api/usuarios')
            ->assertForbidden();
    }
}
