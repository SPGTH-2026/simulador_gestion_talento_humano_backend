<?php

namespace Tests\Feature;

use App\Enums\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MisPostulacionesAyudas;
use Tests\TestCase;

class MisPostulacionesListadoTest extends TestCase
{
    use RefreshDatabase;
    use MisPostulacionesAyudas;

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

}
