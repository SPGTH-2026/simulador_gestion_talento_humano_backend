<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ConvocatoriaPostulacionesAyudas;
use Tests\TestCase;

class ConvocatoriaPostulacionesAspiranteTest extends TestCase
{
    use RefreshDatabase;
    use ConvocatoriaPostulacionesAyudas;

    public function test_listado_aspirante_sin_postulacion_recibe_mi_postulacion_null(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $this->convocatoriaAbierta($ficha, $instructor);
        $aspirante = $this->aspirante($ficha);

        $respuesta = $this->actingAs($aspirante)->getJson('/api/convocatorias')->assertOk();

        $this->assertSame(1, $respuesta->json('meta.total'));
        $this->assertNull($respuesta->json('data.0.mi_postulacion'));
    }

    public function test_listado_aspirante_postulado_recibe_id_y_estado_postulada(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->convocatoriaAbierta($ficha, $instructor);
        $aspirante = $this->aspirante($ficha);
        $propia = $this->postular($convocatoria, $aspirante);

        $respuesta = $this->actingAs($aspirante)->getJson('/api/convocatorias')->assertOk();

        $this->assertSame($propia->id, $respuesta->json('data.0.mi_postulacion.id'));
        $this->assertSame('postulada', $respuesta->json('data.0.mi_postulacion.estado.codigo'));
        $this->assertSame('Postulada', $respuesta->json('data.0.mi_postulacion.estado.nombre'));
        // El estado de mi_postulacion va por codigo/nombre (sin id).
        $this->assertSame(['codigo', 'nombre'], array_keys($respuesta->json('data.0.mi_postulacion.estado')));
    }

    public function test_listado_aspirante_con_postulacion_retirada_recibe_estado_retirada(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->convocatoriaAbierta($ficha, $instructor);
        $aspirante = $this->aspirante($ficha);
        $retirada = $this->retirar($convocatoria, $aspirante);

        $respuesta = $this->actingAs($aspirante)->getJson('/api/convocatorias')->assertOk();

        $this->assertSame($retirada->id, $respuesta->json('data.0.mi_postulacion.id'));
        $this->assertSame('retirada', $respuesta->json('data.0.mi_postulacion.estado.codigo'));
        $this->assertSame('Retirada', $respuesta->json('data.0.mi_postulacion.estado.nombre'));
    }

    public function test_listado_aspirante_no_recibe_la_clave_postulantes(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->convocatoriaAbierta($ficha, $instructor);
        $aspirante = $this->aspirante($ficha);
        $this->postular($convocatoria, $aspirante);

        $respuesta = $this->actingAs($aspirante)->getJson('/api/convocatorias')->assertOk();

        $this->assertArrayHasKey('mi_postulacion', $respuesta->json('data.0'));
        $this->assertArrayNotHasKey('postulantes', $respuesta->json('data.0'));
    }

    public function test_mi_postulacion_muestra_solo_la_del_autenticado(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspiranteA = $this->aspirante($ficha);
        $aspiranteB = $this->aspirante($ficha);

        $compartida = $this->convocatoriaAbierta($ficha, $instructor);
        $soloDeB = $this->convocatoriaAbierta($ficha, $instructor);

        $propiaDeA = $this->postular($compartida, $aspiranteA);
        $this->postular($compartida, $aspiranteB);
        $this->postular($soloDeB, $aspiranteB);

        $data = $this->actingAs($aspiranteA)->getJson('/api/convocatorias')->assertOk()->json('data');

        $porConvocatoria = collect($data)->keyBy('id');

        // En la compartida ve la SUYA, no la de B.
        $this->assertSame($propiaDeA->id, $porConvocatoria[$compartida->id]['mi_postulacion']['id']);
        // En la que solo postulo B, A no ve nada.
        $this->assertNull($porConvocatoria[$soloDeB->id]['mi_postulacion']);
    }

    public function test_listado_aspirante_no_dispara_n_mas_1(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);

        $convocatorias = [];
        for ($i = 0; $i < 10; $i++) {
            $convocatorias[] = $this->convocatoriaAbierta($ficha, $instructor);
        }

        $this->postular($convocatorias[0], $aspirante);
        $this->postular($convocatorias[1], $aspirante);

        // Cada petición real arranca con la memoria de parámetros vacía (es un
        // binding "scoped"). En un test las dos peticiones comparten contenedor y
        // la segunda aprovecharía lo que cargó la primera: se reinicia antes de
        // cada medición para que ambas partan del mismo estado de caché. Solo
        // afecta a lo registrado como scoped (el memo de parámetros); el usuario
        // autenticado con actingAs vive en el guard y no se toca.
        $this->app->forgetScopedInstances();
        DB::enableQueryLog();
        $this->actingAs($aspirante->fresh())->getJson('/api/convocatorias?per_page=100')->assertOk();
        $conDos = count(DB::getQueryLog());
        DB::disableQueryLog();

        for ($i = 2; $i < 10; $i++) {
            $this->postular($convocatorias[$i], $aspirante);
        }

        $this->app->forgetScopedInstances();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($aspirante->fresh())->getJson('/api/convocatorias?per_page=100')->assertOk();
        $conDiez = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Mismo número de consultas con 2 y con 10 postulaciones: si crece con
        // las postulaciones, hay un N+1.
        $this->assertSame($conDos, $conDiez);
    }

    // ── Listado: gestores ──────────────────────────────────────────────

}
