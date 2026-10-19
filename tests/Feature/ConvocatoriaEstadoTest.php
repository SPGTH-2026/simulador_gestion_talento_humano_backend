<?php

namespace App\Tests\Feature;

use App\Enums\Role;
use App\Models\Convocatoria;
use App\Models\Ficha;
use App\Models\Parametro;
use App\Models\User;
use Database\Seeders\ParametroSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pruebas del Paso 2c: cambio de estado de una convocatoria.
 *
 * Cubren autorización (401/403/404), resolución del estado SIEMPRE por código
 * (nunca por id), transiciones de a un paso (sin repetir, saltar ni retroceder),
 * la validación de publicación campo por campo y la frontera de ficha.
 */
class ConvocatoriaEstadoTest extends TestCase
{
    use RefreshDatabase;

    private static int $codigoSecuencia = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ParametroSeeder::class);
    }

    // ── Utilidades ─────────────────────────────────────────────────────

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

    private function instructor(Ficha ...$fichas): User
    {
        $instructor = $this->usuario(Role::Instructor);
        foreach ($fichas as $ficha) {
            $instructor->fichas()->attach($ficha->id);
        }

        return $instructor->fresh();
    }

    private function aspirante(Ficha $ficha): User
    {
        return $this->usuario(Role::Aspirante, ['ficha_id' => $ficha->id]);
    }

    private function parametroId(string $tema, string $codigo): int
    {
        $parametro = Parametro::porTemaYCodigo($tema, $codigo);
        $this->assertNotNull($parametro, "Falta el parámetro {$tema}.{$codigo}");

        return (int) $parametro->id;
    }

    private function crearConvocatoria(Ficha $ficha, User $creador, array $overrides = []): Convocatoria
    {
        return Convocatoria::unguarded(fn () => Convocatoria::create($overrides + [
            'ficha_id' => $ficha->id,
            'codigo' => sprintf('CONV-TEST-%03d', ++self::$codigoSecuencia),
            'nombre' => 'Convocatoria base',
            'vacantes' => 1,
            'tipo_convocatoria_id' => $this->parametroId('tipo_convocatoria', 'externo'),
            'tipo_empleo_id' => $this->parametroId('tipo_empleo', 'tiempo_completo'),
            'tipo_contrato_id' => $this->parametroId('tipo_contrato', 'termino_fijo'),
            'modalidad_id' => $this->parametroId('modalidad', 'presencial'),
            'estado_id' => $this->parametroId('estado_convocatoria', 'borrador'),
            'creado_por' => $creador->id,
        ]));
    }

    /** Datos mínimos para poder publicar (todos los obligatorios). */
    private function publicable(array $overrides = []): array
    {
        $hoy = now('America/Bogota')->toDateString();

        return $overrides + [
            'cargo' => 'Instructor',
            'area' => 'Sistemas',
            'descripcion' => 'Dictar formación técnica.',
            'fecha_apertura' => $hoy,
            'fecha_cierre' => $hoy,
        ];
    }

    private function conEstado(Ficha $ficha, User $creador, string $estado): Convocatoria
    {
        return $this->crearConvocatoria($ficha, $creador, [
            'estado_id' => $this->parametroId('estado_convocatoria', $estado),
        ]);
    }

    // ── Autorización ───────────────────────────────────────────────────

    public function test_sin_autenticacion_responde_401(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->crearConvocatoria($ficha, $instructor);

        $this->patchJson('/api/convocatorias/'.$convocatoria->id.'/estado', ['estado' => 'publicada'])
            ->assertStatus(401);
    }

    public function test_aspirante_sin_permiso_responde_403(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->conEstado($ficha, $instructor, 'publicada');

        $this->actingAs($aspirante)
            ->patchJson('/api/convocatorias/'.$convocatoria->id.'/estado', ['estado' => 'en_proceso'])
            ->assertStatus(403);
    }

    public function test_fuera_de_alcance_responde_404(): void
    {
        $fichaA = $this->ficha('3173334');
        $fichaB = $this->ficha('2631620');
        $instructorA = $this->instructor($fichaA);
        $instructorB = $this->instructor($fichaB);
        $ajena = $this->crearConvocatoria($fichaB, $instructorB);

        $this->actingAs($instructorA)
            ->patchJson('/api/convocatorias/'.$ajena->id.'/estado', ['estado' => 'publicada'])
            ->assertStatus(404);
    }

    public function test_convocatoria_inexistente_responde_404(): void
    {
        $instructor = $this->instructor($this->ficha());

        $this->actingAs($instructor)
            ->patchJson('/api/convocatorias/999999/estado', ['estado' => 'publicada'])
            ->assertStatus(404);
    }

    // ── Resolución por código ──────────────────────────────────────────

    public function test_estado_numerico_responde_422(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->crearConvocatoria($ficha, $instructor);

        $this->actingAs($instructor)
            ->patchJson('/api/convocatorias/'.$convocatoria->id.'/estado', ['estado' => 2])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['estado']);

        $this->actingAs($instructor)
            ->patchJson('/api/convocatorias/'.$convocatoria->id.'/estado', ['estado' => '2'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['estado']);
    }

    public function test_estado_inexistente_responde_422(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->crearConvocatoria($ficha, $instructor);

        $this->actingAs($instructor)
            ->patchJson('/api/convocatorias/'.$convocatoria->id.'/estado', ['estado' => 'inexistente'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['estado']);
    }

    // ── Transiciones ───────────────────────────────────────────────────

    public function test_publicar_borrador_es_valido(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->crearConvocatoria($ficha, $instructor, $this->publicable());

        $respuesta = $this->actingAs($instructor)
            ->patchJson('/api/convocatorias/'.$convocatoria->id.'/estado', ['estado' => 'publicada'])
            ->assertOk();

        $this->assertSame('publicada', $respuesta->json('data.estado.codigo'));
        $this->assertSame(
            $this->parametroId('estado_convocatoria', 'publicada'),
            (int) $convocatoria->fresh()->estado_id,
        );
    }

    public function test_publicada_a_en_proceso_es_valido(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->conEstado($ficha, $instructor, 'publicada');

        $respuesta = $this->actingAs($instructor)
            ->patchJson('/api/convocatorias/'.$convocatoria->id.'/estado', ['estado' => 'en_proceso'])
            ->assertOk();

        $this->assertSame('en_proceso', $respuesta->json('data.estado.codigo'));
    }

    public function test_en_proceso_a_cerrada_es_valido(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->conEstado($ficha, $instructor, 'en_proceso');

        $respuesta = $this->actingAs($instructor)
            ->patchJson('/api/convocatorias/'.$convocatoria->id.'/estado', ['estado' => 'cerrada'])
            ->assertOk();

        $this->assertSame('cerrada', $respuesta->json('data.estado.codigo'));
    }

    public function test_repetir_el_estado_actual_responde_422(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->conEstado($ficha, $instructor, 'borrador');

        $this->actingAs($instructor)
            ->patchJson('/api/convocatorias/'.$convocatoria->id.'/estado', ['estado' => 'borrador'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['estado']);
    }

    public function test_saltar_un_paso_responde_422_con_mensaje_claro(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->crearConvocatoria($ficha, $instructor, $this->publicable());

        $respuesta = $this->actingAs($instructor)
            ->patchJson('/api/convocatorias/'.$convocatoria->id.'/estado', ['estado' => 'cerrada'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['estado']);

        $this->assertStringContainsString(
            'No se puede pasar de borrador a cerrada. El siguiente estado permitido es publicada.',
            $respuesta->json('errors.estado.0'),
        );
    }

    public function test_retroceder_responde_422(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->conEstado($ficha, $instructor, 'publicada');

        $this->actingAs($instructor)
            ->patchJson('/api/convocatorias/'.$convocatoria->id.'/estado', ['estado' => 'borrador'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['estado']);
    }

    public function test_cerrada_no_admite_mas_cambios(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->conEstado($ficha, $instructor, 'cerrada');

        $this->actingAs($instructor)
            ->patchJson('/api/convocatorias/'.$convocatoria->id.'/estado', ['estado' => 'en_proceso'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['estado']);
    }

    // ── Validación al publicar ─────────────────────────────────────────

    public function test_publicar_sin_campos_responde_un_error_por_campo(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->crearConvocatoria($ficha, $instructor);

        $this->actingAs($instructor)
            ->patchJson('/api/convocatorias/'.$convocatoria->id.'/estado', ['estado' => 'publicada'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['cargo', 'area', 'descripcion', 'fecha_apertura', 'fecha_cierre'])
            ->assertJsonMissingValidationErrors(['nombre']);
    }

    public function test_publicar_con_campo_en_blanco_responde_422(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->crearConvocatoria($ficha, $instructor, $this->publicable([
            'cargo' => '   ',
        ]));

        $this->actingAs($instructor)
            ->patchJson('/api/convocatorias/'.$convocatoria->id.'/estado', ['estado' => 'publicada'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['cargo']);
    }

    public function test_publicar_con_fecha_cierre_en_el_pasado_responde_422(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $ayer = now('America/Bogota')->subDay()->toDateString();
        $convocatoria = $this->crearConvocatoria($ficha, $instructor, $this->publicable([
            'fecha_apertura' => $ayer,
            'fecha_cierre' => $ayer,
        ]));

        $this->actingAs($instructor)
            ->patchJson('/api/convocatorias/'.$convocatoria->id.'/estado', ['estado' => 'publicada'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['fecha_cierre'])
            ->assertJsonMissingValidationErrors(['fecha_apertura']);
    }

    public function test_publicar_con_fecha_cierre_hoy_es_valido(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $hoy = now('America/Bogota')->toDateString();
        $convocatoria = $this->crearConvocatoria($ficha, $instructor, $this->publicable([
            'fecha_apertura' => $hoy,
            'fecha_cierre' => $hoy,
        ]));

        $this->actingAs($instructor)
            ->patchJson('/api/convocatorias/'.$convocatoria->id.'/estado', ['estado' => 'publicada'])
            ->assertOk();
    }

    public function test_respuesta_incluye_el_detalle(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->crearConvocatoria($ficha, $instructor, $this->publicable());

        $this->actingAs($instructor)
            ->patchJson('/api/convocatorias/'.$convocatoria->id.'/estado', ['estado' => 'publicada'])
            ->assertOk()
            ->assertJsonStructure(['data' => [
                'id', 'codigo', 'ficha', 'nombre', 'cargo', 'area', 'descripcion', 'estado',
                'fecha_apertura', 'fecha_cierre',
            ]]);
    }
}
