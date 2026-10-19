<?php

namespace App\Tests\Feature;

use App\Enums\Role;
use App\Models\Parametro;
use App\Models\PerfilAspirante;
use App\Models\User;
use Database\Seeders\ParametroSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Paso 4: perfil mínimo del aspirante (sexo).
 *
 * Cubre GET /api/perfil y PUT /api/perfil/sexo: solo el aspirante, alta
 * idempotente (una fila por usuario), validación por CÓDIGO del tema 'sexo' y
 * que el sexo se guarde SIN postulación (es del perfil, no de la convocatoria).
 */
class PerfilAspiranteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ParametroSeeder::class);
    }

    private function usuario(Role $role, array $extra = []): User
    {
        return User::factory()->create($extra + [
            'role' => $role,
            'active' => true,
            'email_verified_at' => now(),
        ]);
    }

    // ── GET /api/perfil ────────────────────────────────────────────────

    public function test_get_perfil_sin_sexo_devuelve_null_y_no_necesita_postulacion(): void
    {
        $aspirante = $this->usuario(Role::Aspirante);

        $this->assertDatabaseCount('postulaciones', 0);

        $this->actingAs($aspirante)
            ->getJson('/api/perfil')
            ->assertOk()
            ->assertJsonPath('data.sexo', null);
    }

    public function test_get_perfil_con_sexo_devuelve_el_parametro(): void
    {
        $aspirante = $this->usuario(Role::Aspirante);
        $this->actingAs($aspirante)->putJson('/api/perfil/sexo', ['sexo' => 'mujer'])->assertOk();

        $respuesta = $this->actingAs($aspirante)->getJson('/api/perfil')->assertOk();

        $this->assertSame('mujer', $respuesta->json('data.sexo.codigo'));
        $this->assertSame('Femenino', $respuesta->json('data.sexo.nombre'));
        $this->assertSame($this->parametroSexo('mujer'), $respuesta->json('data.sexo.id'));
    }

    public function test_get_perfil_instructor_devuelve_403(): void
    {
        $instructor = $this->usuario(Role::Instructor);

        $this->actingAs($instructor)
            ->getJson('/api/perfil')
            ->assertForbidden()
            ->assertJsonPath('message', 'Solo los aspirantes tienen perfil de aspirante.');
    }

    public function test_get_perfil_sin_sesion_devuelve_401(): void
    {
        $this->getJson('/api/perfil')->assertUnauthorized();
    }

    // ── PUT /api/perfil/sexo ───────────────────────────────────────────

    /**
     * El sexo es del perfil del usuario: se guarda aunque NO exista postulación
     * y aunque ninguna convocatoria pida la libreta militar.
     */
    public function test_put_sexo_sin_libreta_ni_postulacion_guarda_el_perfil(): void
    {
        $aspirante = $this->usuario(Role::Aspirante);

        $this->actingAs($aspirante)
            ->putJson('/api/perfil/sexo', ['sexo' => 'hombre'])
            ->assertOk()
            ->assertJsonPath('data.sexo.codigo', 'hombre');

        $this->assertDatabaseCount('postulaciones', 0);
        $this->assertDatabaseHas('perfiles_aspirante', [
            'user_id' => $aspirante->id,
            'sexo_id' => $this->parametroSexo('hombre'),
        ]);

        // Y el GET lo devuelve sin haber creado ninguna postulación.
        $this->actingAs($aspirante)
            ->getJson('/api/perfil')
            ->assertOk()
            ->assertJsonPath('data.sexo.codigo', 'hombre');
    }

    public function test_aspirante_registra_su_sexo_y_recibe_200(): void
    {
        $aspirante = $this->usuario(Role::Aspirante);

        $respuesta = $this->actingAs($aspirante)
            ->putJson('/api/perfil/sexo', ['sexo' => 'hombre'])
            ->assertOk();

        $this->assertSame('hombre', $respuesta->json('data.sexo.codigo'));
        $this->assertDatabaseHas('perfiles_aspirante', [
            'user_id' => $aspirante->id,
            'sexo_id' => $this->parametroSexo('hombre'),
        ]);
    }

    public function test_actualizar_el_sexo_no_duplica_el_perfil(): void
    {
        $aspirante = $this->usuario(Role::Aspirante);

        $this->actingAs($aspirante)->putJson('/api/perfil/sexo', ['sexo' => 'hombre'])->assertOk();
        $this->actingAs($aspirante)->putJson('/api/perfil/sexo', ['sexo' => 'mujer'])->assertOk();

        $this->assertSame(1, PerfilAspirante::count());
        $this->assertDatabaseHas('perfiles_aspirante', [
            'user_id' => $aspirante->id,
            'sexo_id' => $this->parametroSexo('mujer'),
        ]);
    }

    public function test_sexo_invalido_devuelve_422(): void
    {
        $aspirante = $this->usuario(Role::Aspirante);

        $this->actingAs($aspirante)
            ->putJson('/api/perfil/sexo', ['sexo' => 'otro'])
            ->assertStatus(422)
            ->assertJsonPath('errors.sexo.0', 'El sexo debe ser masculino o femenino.');

        $this->assertDatabaseCount('perfiles_aspirante', 0);
    }

    public function test_sexo_requerido_devuelve_422(): void
    {
        $aspirante = $this->usuario(Role::Aspirante);

        $this->actingAs($aspirante)
            ->putJson('/api/perfil/sexo', [])
            ->assertStatus(422)
            ->assertJsonPath('errors.sexo.0', 'El campo sexo es obligatorio.');
    }

    public function test_sin_sesion_devuelve_401(): void
    {
        $this->putJson('/api/perfil/sexo', ['sexo' => 'hombre'])->assertUnauthorized();
    }

    public function test_instructor_devuelve_403(): void
    {
        $instructor = $this->usuario(Role::Instructor);

        $this->actingAs($instructor)
            ->putJson('/api/perfil/sexo', ['sexo' => 'hombre'])
            ->assertForbidden()
            ->assertJsonPath('message', 'Solo los aspirantes tienen perfil de aspirante.');

        $this->assertDatabaseCount('perfiles_aspirante', 0);
    }

    public function test_aprendiz_sin_permiso_devuelve_403(): void
    {
        $aprendiz = $this->usuario(Role::Aprendiz, ['subrole' => 'general']);

        // Lo corta el permiso 'propio:documentos' (el aprendiz no lo tiene).
        $this->actingAs($aprendiz)
            ->putJson('/api/perfil/sexo', ['sexo' => 'hombre'])
            ->assertForbidden();
    }

    private function parametroSexo(string $codigo): int
    {
        return (int) Parametro::porTemaYCodigo('sexo', $codigo)->id;
    }
}
