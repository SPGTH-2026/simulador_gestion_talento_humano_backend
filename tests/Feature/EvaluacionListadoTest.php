<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Evaluacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EvaluacionAyudas;
use Tests\TestCase;

class EvaluacionListadoTest extends TestCase
{
    use RefreshDatabase;
    use EvaluacionAyudas;

    public function test_filtra_postulaciones_por_ficha_y_devuelve_las_fichas(): void
    {
        $fichaA = $this->ficha('2896411');
        $fichaB = $this->ficha('2631620');

        $evaluador = $this->usuario(Role::Aprendiz, 'evaluador', ['ficha_id' => $fichaA->id]);
        $evaluador->fichas()->syncWithoutDetaching([$fichaB->id]);

        $deA = $this->postulacion($this->convocatoria($fichaA));
        $deB = $this->postulacion($this->convocatoria($fichaB));

        $respuesta = $this->actingAs($evaluador)
            ->getJson("/api/evaluaciones?ficha_id={$fichaB->id}")
            ->assertOk();

        $ids = collect($respuesta->json('data'))->pluck('id')->all();

        $this->assertContains($deB->id, $ids);
        $this->assertNotContains($deA->id, $ids);

        // El meta trae las fichas del alcance para poblar el filtro.
        $this->assertCount(2, $respuesta->json('meta.fichas'));
    }

    public function test_ficha_ajena_al_alcance_no_devuelve_nada(): void
    {
        $fichaA = $this->ficha('2896411');
        $fichaB = $this->ficha('2631620');

        $evaluador = $this->usuario(Role::Aprendiz, 'evaluador', ['ficha_id' => $fichaA->id]);
        $this->postulacion($this->convocatoria($fichaA));
        $this->postulacion($this->convocatoria($fichaB));

        $respuesta = $this->actingAs($evaluador)
            ->getJson("/api/evaluaciones?ficha_id={$fichaB->id}")
            ->assertOk();

        $this->assertSame([], $respuesta->json('data'));
        // El filtro solo ofrece las fichas del alcance (no la B).
        $this->assertCount(1, $respuesta->json('meta.fichas'));
    }

    // ── El aspirante ve lo suyo ────────────────────────────────────────

    public function test_aspirante_ve_sus_evaluaciones(): void
    {
        $ficha = $this->ficha('2896411');
        $aspirante = $this->usuario(Role::Aspirante, null, ['ficha_id' => $ficha->id]);
        $evaluador = $this->usuario(Role::Aprendiz, 'evaluador', ['ficha_id' => $ficha->id]);
        $postulacion = $this->postulacion($this->convocatoria($ficha), $aspirante);

        Evaluacion::create([
            'postulacion_id' => $postulacion->id,
            'evaluador_id' => $evaluador->id,
            'puntaje_total' => 88.5,
            'estado' => 'finalizada',
            'observaciones' => 'Recomendado',
        ]);

        $this->actingAs($aspirante)->getJson('/api/mis-evaluaciones')
            ->assertOk()
            ->assertJsonPath('data.0.puntaje_total', '88.50')
            ->assertJsonPath('data.0.observaciones', 'Recomendado');
    }

    // ── Porcentajes configurables por convocatoria ─────────────────────

}
