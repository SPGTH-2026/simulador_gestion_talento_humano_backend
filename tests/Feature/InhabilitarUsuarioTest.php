<?php

namespace App\Tests\Feature;

use App\Enums\Role;
use App\Models\Convocatoria;
use App\Models\Ficha;
use App\Models\Parametro;
use App\Models\Postulacion;
use App\Models\User;
use Database\Seeders\ParametroSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Inhabilitar un usuario (active = false):
 *  - sus postulaciones se ocultan del portafolio y del conteo de postulantes;
 *  - las filas NO se borran: al reactivarlo vuelven a aparecer;
 *  - la respuesta del PATCH trae el estado nuevo, para que la tabla se actualice.
 */
class InhabilitarUsuarioTest extends TestCase
{
    use RefreshDatabase;

    private static int $codigoSecuencia = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ParametroSeeder::class);
    }

    private function ficha(string $codigo = '3173334'): Ficha
    {
        return Ficha::create(['codigo' => $codigo, 'nombre_programa' => 'Análisis y Desarrollo de Software']);
    }

    private function usuario(Role $role, array $extra = []): User
    {
        return User::factory()->create($extra + [
            'role' => $role,
            'active' => true,
            'email_verified_at' => now(),
        ]);
    }

    private function instructor(Ficha $ficha): User
    {
        $instructor = $this->usuario(Role::Instructor);
        $instructor->fichas()->attach($ficha->id);

        return $instructor->fresh();
    }

    private function parametroId(string $tema, string $codigo): int
    {
        return (int) Parametro::porTemaYCodigo($tema, $codigo)->id;
    }

    private function convocatoria(Ficha $ficha, User $creador): Convocatoria
    {
        return Convocatoria::unguarded(fn () => Convocatoria::create([
            'ficha_id' => $ficha->id,
            'codigo' => sprintf('CONV-INH-%03d', ++self::$codigoSecuencia),
            'nombre' => 'Convocatoria base',
            'vacantes' => 1,
            'tipo_convocatoria_id' => $this->parametroId('tipo_convocatoria', 'externo'),
            'tipo_empleo_id' => $this->parametroId('tipo_empleo', 'tiempo_completo'),
            'tipo_contrato_id' => $this->parametroId('tipo_contrato', 'termino_fijo'),
            'modalidad_id' => $this->parametroId('modalidad', 'presencial'),
            'estado_id' => $this->parametroId('estado_convocatoria', 'publicada'),
            'fecha_apertura' => now('America/Bogota')->subDay()->toDateString(),
            'fecha_cierre' => now('America/Bogota')->addDay()->toDateString(),
            'creado_por' => $creador->id,
        ]));
    }

    private function postulacion(Convocatoria $convocatoria, User $aspirante): Postulacion
    {
        return Postulacion::create([
            'convocatoria_id' => $convocatoria->id,
            'user_id' => $aspirante->id,
            'estado_id' => $this->parametroId('estado_postulacion', 'postulada'),
            'fecha_postulacion' => now(),
        ]);
    }

    private function inhabilitar(User $actor, User $objetivo, bool $activo): void
    {
        $this->actingAs($actor)
            ->patchJson("/api/usuarios/{$objetivo->id}", [
                'role' => $objetivo->role->value,
                'subrole' => null,
                'active' => $activo,
                'ficha_id' => $objetivo->ficha_id,
            ])
            ->assertOk()
            ->assertJsonPath('data.active', $activo);
    }

    public function test_el_portafolio_oculta_las_postulaciones_de_un_inactivo_y_al_reactivarlo_vuelven(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->usuario(Role::Aspirante, ['ficha_id' => $ficha->id]);
        $this->postulacion($this->convocatoria($ficha, $instructor), $aspirante);

        $this->actingAs($instructor)
            ->getJson("/api/portafolios/{$aspirante->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.postulaciones');

        $this->inhabilitar($instructor, $aspirante, false);

        $this->actingAs($instructor)
            ->getJson("/api/portafolios/{$aspirante->id}")
            ->assertOk()
            ->assertJsonCount(0, 'data.postulaciones');

        // La fila sigue en la base de datos: no se borró.
        $this->assertSame(1, Postulacion::where('user_id', $aspirante->id)->count());

        $this->inhabilitar($instructor, $aspirante, true);

        $this->actingAs($instructor)
            ->getJson("/api/portafolios/{$aspirante->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.postulaciones');
    }

    public function test_el_conteo_de_postulantes_excluye_a_los_inactivos(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->usuario(Role::Aspirante, ['ficha_id' => $ficha->id]);
        $convocatoria = $this->convocatoria($ficha, $instructor);
        $this->postulacion($convocatoria, $aspirante);

        $this->actingAs($instructor)
            ->getJson('/api/convocatorias')
            ->assertOk()
            ->assertJsonPath('data.0.postulantes', 1);

        $this->inhabilitar($instructor, $aspirante, false);

        $this->actingAs($instructor)
            ->getJson('/api/convocatorias')
            ->assertOk()
            ->assertJsonPath('data.0.postulantes', 0);
    }

    public function test_el_instructor_inhabilita_a_un_aspirante_de_su_ficha(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->usuario(Role::Aspirante, ['ficha_id' => $ficha->id]);

        $this->inhabilitar($instructor, $aspirante, false);

        $this->assertFalse($aspirante->fresh()->active);
    }

    public function test_un_inactivo_no_puede_iniciar_sesion(): void
    {
        $ficha = $this->ficha();
        $aspirante = $this->usuario(Role::Aspirante, [
            'ficha_id' => $ficha->id,
            'password' => 'Dev12345',
            'active' => false,
        ]);

        $this->postJson('/api/auth/login', [
            'email' => $aspirante->email,
            'password' => 'Dev12345',
        ])->assertUnprocessable();
    }
}
