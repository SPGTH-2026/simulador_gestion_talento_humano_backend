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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Paso 2: el aspirante se postula a una convocatoria.
 *
 * Cubre POST /api/convocatorias/{convocatoria}/postulaciones: rol, aislamiento
 * por ficha, ventana (apertura y cierre inclusive), alta, re-postulación con
 * UPDATE condicionado, respaldo del UNIQUE (envío doble y carrera) y que los
 * documentos no se exigen.
 */
class PostulacionAspiranteTest extends TestCase
{
    use RefreshDatabase;

    private static int $codigoSecuencia = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ParametroSeeder::class);
    }

    // ── Helpers ────────────────────────────────────────────────────────

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

    /** Convocatoria publicada y con la ventana abierta hoy (America/Bogota). */
    private function convocatoriaAbierta(Ficha $ficha, User $creador, array $overrides = []): Convocatoria
    {
        return $this->crearConvocatoria($ficha, $creador, $overrides + [
            'estado_id' => $this->parametroId('estado_convocatoria', 'publicada'),
            'fecha_apertura' => now('America/Bogota')->subDay()->toDateString(),
            'fecha_cierre' => now('America/Bogota')->addDay()->toDateString(),
        ]);
    }

    private function crearPostulacion(Convocatoria $convocatoria, User $usuario, array $overrides = []): Postulacion
    {
        return Postulacion::create($overrides + [
            'convocatoria_id' => $convocatoria->id,
            'user_id' => $usuario->id,
            'estado_id' => $this->parametroId('estado_postulacion', 'postulada'),
            'fecha_postulacion' => now(),
        ]);
    }

    // ── Caso feliz y roles ─────────────────────────────────────────────

    public function test_aspirante_se_postula_y_recibe_201(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->convocatoriaAbierta($ficha, $instructor);

        $respuesta = $this->actingAs($aspirante)
            ->postJson("/api/convocatorias/{$convocatoria->id}/postulaciones")
            ->assertCreated();

        $respuesta->assertJsonStructure(['data' => [
            'id',
            'estado' => ['id', 'codigo', 'nombre'],
            'fecha_postulacion',
            'fecha_retiro',
            'convocatoria' => ['id', 'codigo', 'nombre', 'cargo', 'area', 'estado' => ['id', 'codigo', 'nombre'], 'fecha_apertura', 'fecha_cierre'],
        ]]);

        $this->assertSame('borrador', $respuesta->json('data.estado.codigo'));
        $this->assertNull($respuesta->json('data.fecha_retiro'));
        $this->assertNotNull($respuesta->json('data.fecha_postulacion'));
        $this->assertSame($convocatoria->id, $respuesta->json('data.convocatoria.id'));

        $this->assertDatabaseHas('postulaciones', [
            'convocatoria_id' => $convocatoria->id,
            'user_id' => $aspirante->id,
            'estado_id' => $this->parametroId('estado_postulacion', 'borrador'),
        ]);
    }

    public function test_instructor_no_puede_postularse_devuelve_403(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->convocatoriaAbierta($ficha, $instructor);

        $this->actingAs($instructor)
            ->postJson("/api/convocatorias/{$convocatoria->id}/postulaciones")
            ->assertForbidden()
            ->assertJsonPath('message', 'Solo los aspirantes pueden postularse a las convocatorias.');

        $this->assertDatabaseCount('postulaciones', 0);
    }

    public function test_super_admin_no_puede_postularse_devuelve_403(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $superAdmin = $this->usuario(Role::SuperAdmin);
        $convocatoria = $this->convocatoriaAbierta($ficha, $instructor);

        $this->actingAs($superAdmin)
            ->postJson("/api/convocatorias/{$convocatoria->id}/postulaciones")
            ->assertForbidden()
            ->assertJsonPath('message', 'Solo los aspirantes pueden postularse a las convocatorias.');

        $this->assertDatabaseCount('postulaciones', 0);
    }

    public function test_aprendiz_no_puede_postularse_devuelve_403(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->convocatoriaAbierta($ficha, $instructor);
        $aprendiz = $this->usuario(Role::Aprendiz, ['subrole' => 'general', 'ficha_id' => $ficha->id]);

        // Lo corta el permiso 'convocatorias:postular' antes del controlador.
        $this->actingAs($aprendiz)
            ->postJson("/api/convocatorias/{$convocatoria->id}/postulaciones")
            ->assertForbidden();

        $this->assertDatabaseCount('postulaciones', 0);
    }

    public function test_sin_sesion_devuelve_401(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->convocatoriaAbierta($ficha, $instructor);

        $this->postJson("/api/convocatorias/{$convocatoria->id}/postulaciones")
            ->assertUnauthorized();
    }

    // ── Ventana ────────────────────────────────────────────────────────

    public function test_convocatoria_no_publicada_devuelve_404(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $borrador = $this->crearConvocatoria($ficha, $instructor, [
            'estado_id' => $this->parametroId('estado_convocatoria', 'borrador'),
        ]);

        $this->actingAs($aspirante)
            ->postJson("/api/convocatorias/{$borrador->id}/postulaciones")
            ->assertNotFound()
            ->assertJsonPath('message', 'Convocatoria no encontrada.');
    }

    public function test_no_se_puede_postular_antes_de_la_apertura_devuelve_422(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->crearConvocatoria($ficha, $instructor, [
            'estado_id' => $this->parametroId('estado_convocatoria', 'publicada'),
            'fecha_apertura' => now('America/Bogota')->addDay()->toDateString(),
            'fecha_cierre' => now('America/Bogota')->addDays(10)->toDateString(),
        ]);

        $this->actingAs($aspirante)
            ->postJson("/api/convocatorias/{$convocatoria->id}/postulaciones")
            ->assertStatus(422)
            ->assertJsonPath(
                'errors.postulacion.0',
                'La convocatoria aún no está abierta. Abre el '.$convocatoria->fecha_apertura->toDateString().'.',
            );
    }

    public function test_no_se_puede_postular_despues_del_cierre_devuelve_422(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->crearConvocatoria($ficha, $instructor, [
            'estado_id' => $this->parametroId('estado_convocatoria', 'publicada'),
            'fecha_apertura' => now('America/Bogota')->subDays(10)->toDateString(),
            'fecha_cierre' => now('America/Bogota')->subDay()->toDateString(),
        ]);

        $this->actingAs($aspirante)
            ->postJson("/api/convocatorias/{$convocatoria->id}/postulaciones")
            ->assertStatus(422)
            ->assertJsonPath(
                'errors.postulacion.0',
                'La convocatoria ya cerró el '.$convocatoria->fecha_cierre->toDateString().'.',
            );
    }

    public function test_se_puede_postular_el_dia_de_apertura_inclusive(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->crearConvocatoria($ficha, $instructor, [
            'estado_id' => $this->parametroId('estado_convocatoria', 'publicada'),
            'fecha_apertura' => now('America/Bogota')->toDateString(),
            'fecha_cierre' => now('America/Bogota')->addDays(5)->toDateString(),
        ]);

        $this->actingAs($aspirante)
            ->postJson("/api/convocatorias/{$convocatoria->id}/postulaciones")
            ->assertCreated();
    }

    public function test_se_puede_postular_el_dia_de_cierre_inclusive(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->crearConvocatoria($ficha, $instructor, [
            'estado_id' => $this->parametroId('estado_convocatoria', 'publicada'),
            'fecha_apertura' => now('America/Bogota')->subDays(5)->toDateString(),
            'fecha_cierre' => now('America/Bogota')->toDateString(),
        ]);

        $this->actingAs($aspirante)
            ->postJson("/api/convocatorias/{$convocatoria->id}/postulaciones")
            ->assertCreated();
    }

    // ── Postulación previa ─────────────────────────────────────────────

    public function test_ya_postulado_devuelve_422(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->convocatoriaAbierta($ficha, $instructor);
        $this->crearPostulacion($convocatoria, $aspirante);

        $this->actingAs($aspirante)
            ->postJson("/api/convocatorias/{$convocatoria->id}/postulaciones")
            ->assertStatus(422)
            ->assertJsonPath('errors.postulacion.0', 'Ya estás postulado en esta convocatoria.');

        $this->assertDatabaseCount('postulaciones', 1);
    }

    public function test_re_postulacion_tras_retiro_devuelve_200_y_reabre_el_borrador(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->convocatoriaAbierta($ficha, $instructor);
        $previa = $this->crearPostulacion($convocatoria, $aspirante, [
            'estado_id' => $this->parametroId('estado_postulacion', 'retirada'),
            'fecha_retiro' => now()->subDay(),
        ]);

        $respuesta = $this->actingAs($aspirante)
            ->postJson("/api/convocatorias/{$convocatoria->id}/postulaciones")
            ->assertOk();

        $this->assertSame($previa->id, $respuesta->json('data.id'));
        $this->assertSame('borrador', $respuesta->json('data.estado.codigo'));
        $this->assertNull($respuesta->json('data.fecha_retiro'));

        // No se crea otra fila: se reabrió la misma en borrador.
        $this->assertSame(1, Postulacion::query()
            ->where('convocatoria_id', $convocatoria->id)
            ->where('user_id', $aspirante->id)
            ->count());
    }

    // ── Aislamiento ────────────────────────────────────────────────────

    public function test_convocatoria_de_otra_ficha_devuelve_404(): void
    {
        $fichaA = $this->ficha('3173334');
        $fichaB = $this->ficha('2631620');
        $instructorB = $this->instructor($fichaB);
        $aspiranteA = $this->aspirante($fichaA);
        $convocatoriaB = $this->convocatoriaAbierta($fichaB, $instructorB);

        $this->actingAs($aspiranteA)
            ->postJson("/api/convocatorias/{$convocatoriaB->id}/postulaciones")
            ->assertNotFound();
    }

    public function test_convocatoria_inexistente_devuelve_404(): void
    {
        $aspirante = $this->aspirante($this->ficha());

        $this->actingAs($aspirante)
            ->postJson('/api/convocatorias/999999/postulaciones')
            ->assertNotFound()
            ->assertJsonPath('message', 'Convocatoria no encontrada.');
    }

    public function test_aspirante_sin_ficha_no_puede_postularse_devuelve_404(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspiranteSinFicha = $this->usuario(Role::Aspirante);
        $convocatoria = $this->convocatoriaAbierta($ficha, $instructor);

        $this->actingAs($aspiranteSinFicha)
            ->postJson("/api/convocatorias/{$convocatoria->id}/postulaciones")
            ->assertNotFound();
    }

    // ── Documentos (no se exigen al crear el borrador) ─────────────────

    public function test_crear_el_borrador_no_exige_documentos(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->convocatoriaAbierta($ficha, $instructor);
        $convocatoria->documentos()->attach(
            $this->parametroId('tipo_documento', 'hoja_de_vida'),
            ['obligatorio' => true],
        );

        $this->actingAs($aspirante)
            ->postJson("/api/convocatorias/{$convocatoria->id}/postulaciones")
            ->assertCreated();
    }

    // ── Respaldo del UNIQUE: carreras ──────────────────────────────────

    public function test_carrera_por_unique_devuelve_422(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->convocatoriaAbierta($ficha, $instructor);

        // Simula otra petición que gana la carrera justo antes del INSERT real.
        $evento = 'eloquent.creating: '.Postulacion::class;
        Postulacion::creating(function (Postulacion $modelo) {
            DB::table('postulaciones')->insert([
                'convocatoria_id' => $modelo->convocatoria_id,
                'user_id' => $modelo->user_id,
                'estado_id' => $modelo->estado_id,
                'fecha_postulacion' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        try {
            $this->actingAs($aspirante)
                ->postJson("/api/convocatorias/{$convocatoria->id}/postulaciones")
                ->assertStatus(422)
                ->assertJsonPath('errors.postulacion.0', 'Ya estás postulado en esta convocatoria.');
        } finally {
            // Quita SOLO el listener de este evento (no flushEventListeners()).
            Event::forget($evento);
        }
    }

    public function test_reactivacion_concurrente_devuelve_422(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->convocatoriaAbierta($ficha, $instructor);
        $previa = $this->crearPostulacion($convocatoria, $aspirante, [
            'estado_id' => $this->parametroId('estado_postulacion', 'retirada'),
            'fecha_retiro' => now()->subDay(),
        ]);
        $postuladaId = $this->parametroId('estado_postulacion', 'postulada');

        // Otra petición reactiva primero: al leer la postulación, la fila deja de
        // estar retirada. El UPDATE condicionado afecta 0 filas -> 422 (nunca 500).
        $evento = 'eloquent.retrieved: '.Postulacion::class;
        Postulacion::retrieved(function () use ($previa, $postuladaId) {
            DB::table('postulaciones')->where('id', $previa->id)->update([
                'estado_id' => $postuladaId,
                'fecha_retiro' => null,
                'updated_at' => now(),
            ]);
        });

        try {
            $this->actingAs($aspirante)
                ->postJson("/api/convocatorias/{$convocatoria->id}/postulaciones")
                ->assertStatus(422)
                ->assertJsonPath('errors.postulacion.0', 'Ya estás postulado en esta convocatoria.');
        } finally {
            Event::forget($evento);
        }
    }
}
