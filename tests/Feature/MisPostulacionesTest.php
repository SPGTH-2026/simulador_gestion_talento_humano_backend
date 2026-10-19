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
 * Paso 3: "mis postulaciones" (GET) y retiro / reactivacion (PATCH).
 *
 * Cubre GET /api/postulaciones (siempre las propias, paginado, con resumen de la
 * convocatoria, sin N+1 incluso con convocatorias no publicadas) y
 * PATCH /api/postulaciones/{postulacion}/estado (aislamiento 404, estado por
 * codigo, retiro solo si la convocatoria esta publicada, reactivacion con la
 * misma ventana que el POST y UPDATE condicionado ante carreras).
 */
class MisPostulacionesTest extends TestCase
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
            'fecha_cierre' => now('America/Bogota')->addDays(10)->toDateString(),
        ]);
    }

    private function crearPostulacion(Convocatoria $convocatoria, User $aspirante, array $overrides = []): Postulacion
    {
        return Postulacion::unguarded(fn () => Postulacion::create($overrides + [
            'convocatoria_id' => $convocatoria->id,
            'user_id' => $aspirante->id,
            'estado_id' => $this->parametroId('estado_postulacion', 'postulada'),
            'fecha_postulacion' => now(),
        ]));
    }

    private function postulacionRetirada(Convocatoria $convocatoria, User $aspirante, array $overrides = []): Postulacion
    {
        return $this->crearPostulacion($convocatoria, $aspirante, $overrides + [
            'estado_id' => $this->parametroId('estado_postulacion', 'retirada'),
            'fecha_retiro' => now()->subDay(),
        ]);
    }

    private function postulacionBorrador(Convocatoria $convocatoria, User $aspirante, array $overrides = []): Postulacion
    {
        return $this->crearPostulacion($convocatoria, $aspirante, $overrides + [
            'estado_id' => $this->parametroId('estado_postulacion', 'borrador'),
        ]);
    }

    // ── GET: visibilidad y contenido ───────────────────────────────────

    public function test_aspirante_ve_solo_sus_postulaciones(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspiranteA = $this->aspirante($ficha);
        $aspiranteB = $this->aspirante($ficha);
        $convocatoriaA = $this->convocatoriaAbierta($ficha, $instructor);
        $convocatoriaB = $this->convocatoriaAbierta($ficha, $instructor);
        $propia = $this->crearPostulacion($convocatoriaA, $aspiranteA);
        $this->crearPostulacion($convocatoriaB, $aspiranteB);

        $respuesta = $this->actingAs($aspiranteA)
            ->getJson('/api/postulaciones')
            ->assertOk();

        $this->assertSame(1, $respuesta->json('meta.total'));
        $this->assertSame($propia->id, $respuesta->json('data.0.id'));
    }

    public function test_listado_incluye_resumen_de_la_convocatoria(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->convocatoriaAbierta($ficha, $instructor);
        $this->crearPostulacion($convocatoria, $aspirante);

        $this->actingAs($aspirante)
            ->getJson('/api/postulaciones')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [[
                    'id',
                    'estado' => ['id', 'codigo', 'nombre'],
                    'fecha_postulacion',
                    'fecha_retiro',
                    'convocatoria' => ['id', 'codigo', 'nombre', 'cargo', 'area', 'estado' => ['id', 'codigo', 'nombre'], 'fecha_apertura', 'fecha_cierre'],
                ]],
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            ]);
    }

    public function test_listado_ordena_por_fecha_postulacion_desc_y_luego_id_desc(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);

        $vieja = $this->crearPostulacion(
            $this->convocatoriaAbierta($ficha, $instructor),
            $aspirante,
            ['fecha_postulacion' => '2026-01-01 10:00:00'],
        );
        $media = $this->crearPostulacion(
            $this->convocatoriaAbierta($ficha, $instructor),
            $aspirante,
            ['fecha_postulacion' => '2026-03-01 10:00:00'],
        );
        // Misma fecha que $media pero se crea despues (id mayor): va primero.
        $reciente = $this->crearPostulacion(
            $this->convocatoriaAbierta($ficha, $instructor),
            $aspirante,
            ['fecha_postulacion' => '2026-03-01 10:00:00'],
        );

        $respuesta = $this->actingAs($aspirante)
            ->getJson('/api/postulaciones')
            ->assertOk();

        $ids = array_column($respuesta->json('data'), 'id');
        $this->assertSame([$reciente->id, $media->id, $vieja->id], $ids);
    }

    public function test_listado_pagina_y_per_page_max_100(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $this->crearPostulacion($this->convocatoriaAbierta($ficha, $instructor), $aspirante);
        $this->crearPostulacion($this->convocatoriaAbierta($ficha, $instructor), $aspirante);

        // per_page fuera de rango -> 422.
        $this->actingAs($aspirante)
            ->getJson('/api/postulaciones?per_page=101')
            ->assertStatus(422)
            ->assertJsonPath('errors.per_page.0', 'El campo elementos por página no debe superar 100.');

        // per_page=1: una fila por pagina y dos paginas.
        $respuesta = $this->actingAs($aspirante)
            ->getJson('/api/postulaciones?per_page=1')
            ->assertOk();

        $this->assertCount(1, $respuesta->json('data'));
        $this->assertSame(1, $respuesta->json('meta.per_page'));
        $this->assertSame(2, $respuesta->json('meta.last_page'));
        $this->assertSame(2, $respuesta->json('meta.total'));
    }

    public function test_listado_muestra_postulaciones_aunque_la_convocatoria_no_este_publicada(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $enProceso = $this->crearConvocatoria($ficha, $instructor, [
            'estado_id' => $this->parametroId('estado_convocatoria', 'en_proceso'),
        ]);
        $this->crearPostulacion($enProceso, $aspirante);

        $respuesta = $this->actingAs($aspirante)
            ->getJson('/api/postulaciones')
            ->assertOk();

        $this->assertSame(1, $respuesta->json('meta.total'));
        $this->assertSame('en_proceso', $respuesta->json('data.0.convocatoria.estado.codigo'));
    }

    public function test_instructor_no_ve_postulaciones_de_aspirantes(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $this->crearPostulacion($this->convocatoriaAbierta($ficha, $instructor), $aspirante);

        $respuesta = $this->actingAs($instructor)
            ->getJson('/api/postulaciones')
            ->assertOk();

        $this->assertSame(0, $respuesta->json('meta.total'));
        $this->assertSame([], $respuesta->json('data'));
    }

    public function test_super_admin_solo_ve_las_suyas(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $superAdmin = $this->usuario(Role::SuperAdmin);
        $this->crearPostulacion($this->convocatoriaAbierta($ficha, $instructor), $aspirante);

        $respuesta = $this->actingAs($superAdmin)
            ->getJson('/api/postulaciones')
            ->assertOk();

        $this->assertSame(0, $respuesta->json('meta.total'));
    }

    public function test_aprendiz_no_puede_listar_devuelve_403(): void
    {
        $ficha = $this->ficha();
        $aprendiz = $this->usuario(Role::Aprendiz, ['subrole' => 'general', 'ficha_id' => $ficha->id]);

        // Lo corta el permiso 'propio:postulaciones' antes del controlador.
        $this->actingAs($aprendiz)
            ->getJson('/api/postulaciones')
            ->assertForbidden();
    }

    public function test_get_sin_sesion_devuelve_401(): void
    {
        $this->getJson('/api/postulaciones')->assertUnauthorized();
    }

    // ── GET: sin N+1 ───────────────────────────────────────────────────

    public function test_listado_no_dispara_n_mas_1(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);

        $convocatorias = [];
        for ($i = 0; $i < 10; $i++) {
            $convocatorias[] = $this->convocatoriaAbierta($ficha, $instructor);
        }

        // Con 2 postulaciones...
        $this->crearPostulacion($convocatorias[0], $aspirante);
        $this->crearPostulacion($convocatorias[1], $aspirante);

        DB::enableQueryLog();
        $this->actingAs($aspirante)->getJson('/api/postulaciones')->assertOk();
        $conDos = count(DB::getQueryLog());
        DB::disableQueryLog();

        // ...y con 10, el numero de consultas debe ser IDENTICO.
        for ($i = 2; $i < 10; $i++) {
            $this->crearPostulacion($convocatorias[$i], $aspirante);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($aspirante)->getJson('/api/postulaciones')->assertOk();
        $conDiez = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame($conDos, $conDiez);
    }

    // ── PATCH: retiro ──────────────────────────────────────────────────

    public function test_aspirante_retira_su_postulacion(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $postulacion = $this->crearPostulacion($this->convocatoriaAbierta($ficha, $instructor), $aspirante);

        $respuesta = $this->actingAs($aspirante)
            ->patchJson("/api/postulaciones/{$postulacion->id}/estado", ['estado' => 'retirada'])
            ->assertOk();

        $this->assertSame($postulacion->id, $respuesta->json('data.id'));
        $this->assertSame('retirada', $respuesta->json('data.estado.codigo'));
        $this->assertNotNull($respuesta->json('data.fecha_retiro'));

        $this->assertDatabaseHas('postulaciones', [
            'id' => $postulacion->id,
            'estado_id' => $this->parametroId('estado_postulacion', 'retirada'),
        ]);
        $this->assertNotNull(Postulacion::find($postulacion->id)->fecha_retiro);
    }

    public function test_retiro_cuando_ya_esta_retirada_devuelve_422(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $postulacion = $this->postulacionRetirada($this->convocatoriaAbierta($ficha, $instructor), $aspirante);

        $this->actingAs($aspirante)
            ->patchJson("/api/postulaciones/{$postulacion->id}/estado", ['estado' => 'retirada'])
            ->assertStatus(422)
            ->assertJsonPath('errors.estado.0', 'La postulación ya está en estado retirada.');
    }

    public function test_retiro_con_convocatoria_no_publicada_devuelve_422(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $enProceso = $this->crearConvocatoria($ficha, $instructor, [
            'estado_id' => $this->parametroId('estado_convocatoria', 'en_proceso'),
        ]);
        $postulacion = $this->crearPostulacion($enProceso, $aspirante);

        $this->actingAs($aspirante)
            ->patchJson("/api/postulaciones/{$postulacion->id}/estado", ['estado' => 'retirada'])
            ->assertStatus(422)
            ->assertJsonPath('errors.estado.0', 'No puedes retirar tu postulación: la convocatoria ya no está publicada.');

        $this->assertDatabaseHas('postulaciones', [
            'id' => $postulacion->id,
            'estado_id' => $this->parametroId('estado_postulacion', 'postulada'),
        ]);
    }

    // ── PATCH: reapertura (retirada -> borrador) ───────────────────────

    public function test_reactivar_postulacion_retirada_en_ventana_abierta(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $postulacion = $this->postulacionRetirada($this->convocatoriaAbierta($ficha, $instructor), $aspirante);

        $respuesta = $this->actingAs($aspirante)
            ->patchJson("/api/postulaciones/{$postulacion->id}/estado", ['estado' => 'borrador'])
            ->assertOk();

        $this->assertSame($postulacion->id, $respuesta->json('data.id'));
        $this->assertSame('borrador', $respuesta->json('data.estado.codigo'));
        $this->assertNull($respuesta->json('data.fecha_retiro'));
        $this->assertSame(1, Postulacion::query()
            ->where('convocatoria_id', $postulacion->convocatoria_id)
            ->where('user_id', $aspirante->id)
            ->count());
    }

    /**
     * 'postulada' ya NO se alcanza con el PATCH: solo con el envío, que valida
     * los documentos. El PATCH guía al endpoint correcto.
     */
    public function test_patch_a_postulada_devuelve_422_y_guia_al_envio(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $postulacion = $this->postulacionBorrador($this->convocatoriaAbierta($ficha, $instructor), $aspirante);

        $this->actingAs($aspirante)
            ->patchJson("/api/postulaciones/{$postulacion->id}/estado", ['estado' => 'postulada'])
            ->assertStatus(422)
            ->assertJsonPath('errors.estado.0', 'Para enviar tu postulación usa el endpoint de envío.');

        // Sigue en borrador: el PATCH no lo cambió.
        $this->assertSame(
            $this->parametroId('estado_postulacion', 'borrador'),
            $postulacion->fresh()->estado_id,
        );
    }

    public function test_reactivar_antes_de_apertura_devuelve_422(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->crearConvocatoria($ficha, $instructor, [
            'estado_id' => $this->parametroId('estado_convocatoria', 'publicada'),
            'fecha_apertura' => now('America/Bogota')->addDay()->toDateString(),
            'fecha_cierre' => now('America/Bogota')->addDays(10)->toDateString(),
        ]);
        $postulacion = $this->postulacionRetirada($convocatoria, $aspirante);

        $this->actingAs($aspirante)
            ->patchJson("/api/postulaciones/{$postulacion->id}/estado", ['estado' => 'borrador'])
            ->assertStatus(422)
            ->assertJsonPath(
                'errors.estado.0',
                'La convocatoria aún no está abierta. Abre el '.$convocatoria->fecha_apertura->toDateString().'.',
            );
    }

    public function test_reactivar_despues_del_cierre_devuelve_422(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->crearConvocatoria($ficha, $instructor, [
            'estado_id' => $this->parametroId('estado_convocatoria', 'publicada'),
            'fecha_apertura' => now('America/Bogota')->subDays(10)->toDateString(),
            'fecha_cierre' => now('America/Bogota')->subDay()->toDateString(),
        ]);
        $postulacion = $this->postulacionRetirada($convocatoria, $aspirante);

        $this->actingAs($aspirante)
            ->patchJson("/api/postulaciones/{$postulacion->id}/estado", ['estado' => 'borrador'])
            ->assertStatus(422)
            ->assertJsonPath(
                'errors.estado.0',
                'La convocatoria ya cerró el '.$convocatoria->fecha_cierre->toDateString().'.',
            );
    }

    public function test_reactivar_con_convocatoria_no_publicada_devuelve_404(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $borrador = $this->crearConvocatoria($ficha, $instructor);
        $postulacion = $this->postulacionRetirada($borrador, $aspirante);

        $this->actingAs($aspirante)
            ->patchJson("/api/postulaciones/{$postulacion->id}/estado", ['estado' => 'borrador'])
            ->assertNotFound()
            ->assertJsonPath('message', 'Convocatoria no encontrada.');
    }

    // ── PATCH: validación del cuerpo ───────────────────────────────────

    public function test_estado_por_id_numerico_devuelve_422(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $postulacion = $this->crearPostulacion($this->convocatoriaAbierta($ficha, $instructor), $aspirante);

        $this->actingAs($aspirante)
            ->patchJson("/api/postulaciones/{$postulacion->id}/estado", ['estado' => '1'])
            ->assertStatus(422)
            ->assertJsonPath('errors.estado.0', 'El estado debe enviarse por su código, no por su id.');
    }

    public function test_estado_desconocido_devuelve_422(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $postulacion = $this->crearPostulacion($this->convocatoriaAbierta($ficha, $instructor), $aspirante);

        $this->actingAs($aspirante)
            ->patchJson("/api/postulaciones/{$postulacion->id}/estado", ['estado' => 'aprobada'])
            ->assertStatus(422)
            ->assertJsonPath('errors.estado.0', 'El estado no es válido.');
    }

    public function test_estado_igual_al_actual_devuelve_422(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $postulacion = $this->postulacionBorrador($this->convocatoriaAbierta($ficha, $instructor), $aspirante);

        $this->actingAs($aspirante)
            ->patchJson("/api/postulaciones/{$postulacion->id}/estado", ['estado' => 'borrador'])
            ->assertStatus(422)
            ->assertJsonPath('errors.estado.0', 'La postulación ya está en estado borrador.');
    }

    public function test_patch_sin_estado_devuelve_422(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $postulacion = $this->crearPostulacion($this->convocatoriaAbierta($ficha, $instructor), $aspirante);

        $this->actingAs($aspirante)
            ->patchJson("/api/postulaciones/{$postulacion->id}/estado", [])
            ->assertStatus(422)
            ->assertJsonPath('errors.estado.0', 'El campo estado es obligatorio.');
    }

    // ── PATCH: aislamiento ─────────────────────────────────────────────

    public function test_patch_de_postulacion_ajena_devuelve_404(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspiranteA = $this->aspirante($ficha);
        $aspiranteB = $this->aspirante($ficha);
        $postulacion = $this->crearPostulacion($this->convocatoriaAbierta($ficha, $instructor), $aspiranteA);

        $this->actingAs($aspiranteB)
            ->patchJson("/api/postulaciones/{$postulacion->id}/estado", ['estado' => 'retirada'])
            ->assertNotFound()
            ->assertJsonPath('message', 'Postulación no encontrada.');

        $this->assertDatabaseHas('postulaciones', [
            'id' => $postulacion->id,
            'estado_id' => $this->parametroId('estado_postulacion', 'postulada'),
        ]);
    }

    public function test_instructor_no_puede_cambiar_estado_de_aspirante_devuelve_404(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $postulacion = $this->crearPostulacion($this->convocatoriaAbierta($ficha, $instructor), $aspirante);

        $this->actingAs($instructor)
            ->patchJson("/api/postulaciones/{$postulacion->id}/estado", ['estado' => 'retirada'])
            ->assertNotFound();
    }

    public function test_patch_de_postulacion_inexistente_devuelve_404(): void
    {
        $aspirante = $this->aspirante($this->ficha());

        $this->actingAs($aspirante)
            ->patchJson('/api/postulaciones/999999/estado', ['estado' => 'retirada'])
            ->assertNotFound()
            ->assertJsonPath('message', 'Postulación no encontrada.');
    }

    public function test_patch_ajena_con_cuerpo_invalido_devuelve_404_no_422(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspiranteA = $this->aspirante($ficha);
        $aspiranteB = $this->aspirante($ficha);
        $postulacion = $this->crearPostulacion($this->convocatoriaAbierta($ficha, $instructor), $aspiranteA);

        // El cuerpo vacio seria 422, pero el aislamiento corre ANTES: 404.
        $this->actingAs($aspiranteB)
            ->patchJson("/api/postulaciones/{$postulacion->id}/estado", [])
            ->assertNotFound()
            ->assertJsonPath('message', 'Postulación no encontrada.');
    }

    public function test_patch_sin_sesion_devuelve_401(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $postulacion = $this->crearPostulacion($this->convocatoriaAbierta($ficha, $instructor), $aspirante);

        $this->patchJson("/api/postulaciones/{$postulacion->id}/estado", ['estado' => 'retirada'])
            ->assertUnauthorized();
    }

    public function test_aprendiz_no_puede_cambiar_estado_devuelve_403(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $aprendiz = $this->usuario(Role::Aprendiz, ['subrole' => 'general', 'ficha_id' => $ficha->id]);
        $postulacion = $this->crearPostulacion($this->convocatoriaAbierta($ficha, $instructor), $aspirante);

        $this->actingAs($aprendiz)
            ->patchJson("/api/postulaciones/{$postulacion->id}/estado", ['estado' => 'retirada'])
            ->assertForbidden();
    }

    // ── PATCH: carreras (UPDATE condicionado) ──────────────────────────

    public function test_carrera_en_retiro_devuelve_422(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $postulacion = $this->crearPostulacion($this->convocatoriaAbierta($ficha, $instructor), $aspirante);
        $retiradaId = $this->parametroId('estado_postulacion', 'retirada');

        // Otra peticion retira primero: al enlazar la ruta, la fila ya no esta
        // postulada, pero el modelo conserva el estado leido. El UPDATE
        // condicionado afecta 0 filas -> 422 (nunca 500).
        $evento = 'eloquent.retrieved: '.Postulacion::class;
        Postulacion::retrieved(function () use ($postulacion, $retiradaId) {
            DB::table('postulaciones')->where('id', $postulacion->id)->update([
                'estado_id' => $retiradaId,
                'fecha_retiro' => now(),
                'updated_at' => now(),
            ]);
        });

        try {
            $this->actingAs($aspirante)
                ->patchJson("/api/postulaciones/{$postulacion->id}/estado", ['estado' => 'retirada'])
                ->assertStatus(422)
                ->assertJsonPath('errors.estado.0', 'No se pudo actualizar la postulación: su estado cambió. Inténtalo de nuevo.');
        } finally {
            // Quita SOLO el listener de este evento (no flushEventListeners()).
            Event::forget($evento);
        }
    }

    public function test_carrera_en_reapertura_devuelve_422(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $postulacion = $this->postulacionRetirada($this->convocatoriaAbierta($ficha, $instructor), $aspirante);
        $postuladaId = $this->parametroId('estado_postulacion', 'postulada');

        $evento = 'eloquent.retrieved: '.Postulacion::class;
        Postulacion::retrieved(function () use ($postulacion, $postuladaId) {
            DB::table('postulaciones')->where('id', $postulacion->id)->update([
                'estado_id' => $postuladaId,
                'fecha_retiro' => null,
                'updated_at' => now(),
            ]);
        });

        try {
            $this->actingAs($aspirante)
                ->patchJson("/api/postulaciones/{$postulacion->id}/estado", ['estado' => 'borrador'])
                ->assertStatus(422)
                ->assertJsonPath('errors.estado.0', 'No se pudo actualizar la postulación: su estado cambió. Inténtalo de nuevo.');
        } finally {
            Event::forget($evento);
        }
    }

}
