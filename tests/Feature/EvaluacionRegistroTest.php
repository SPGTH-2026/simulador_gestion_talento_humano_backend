<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Evaluacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EvaluacionAyudas;
use Tests\TestCase;

class EvaluacionRegistroTest extends TestCase
{
    use RefreshDatabase;
    use EvaluacionAyudas;

    public function test_evaluador_registra_evaluacion_y_calcula_promedio_ponderado(): void
    {
        $ficha = $this->ficha('2896411');
        $evaluador = $this->usuario(Role::Aprendiz, 'evaluador', ['ficha_id' => $ficha->id]);
        $convocatoria = $this->convocatoria($ficha);
        // El 60 % exige documentos obligatorios configurados.
        $this->documentosConPeso($convocatoria, [
            'hoja_de_vida' => null,
            'documento_identidad' => null,
        ]);
        $postulacion = $this->conDocumentosCargados($this->postulacion($convocatoria));

        $this->actingAs($evaluador)->putJson("/api/evaluaciones/{$postulacion->id}", [
            'observaciones' => 'Buen perfil',
            'estado' => 'finalizada',
            'criterios' => $this->criterios(),
            'perfil' => $this->perfilDeTodos($postulacion),
        ])->assertOk()
            ->assertJsonPath('data.evaluacion.estado', 'finalizada')
            ->assertJsonPath('data.evaluacion.puntaje_total', '73.33')
            ->assertJsonCount(2, 'data.evaluacion.criterios');

        $this->assertDatabaseHas('evaluaciones', [
            'postulacion_id' => $postulacion->id,
            'evaluador_id' => $evaluador->id,
            'puntaje_total' => 73.33,
        ]);
        $this->assertDatabaseCount('evaluacion_detalles', 2);
    }

    public function test_un_borrador_no_define_si_pasa_a_seleccion(): void
    {
        $ficha = $this->ficha('2896411');
        $evaluador = $this->usuario(Role::Aprendiz, 'evaluador', ['ficha_id' => $ficha->id]);
        $convocatoria = $this->convocatoria($ficha);
        $this->documentosConPeso($convocatoria, [
            'hoja_de_vida' => null,
            'documento_identidad' => null,
        ]);
        $postulacion = $this->postulacion($convocatoria);

        $this->actingAs($evaluador)->putJson("/api/evaluaciones/{$postulacion->id}", [
            'estado' => 'en_proceso',
            'criterios' => [['criterio' => 'Evaluación', 'puntaje' => 90, 'ponderacion' => 1]],
            'cumplimiento' => 90,
        ])->assertOk()
            ->assertJsonPath('data.evaluacion.estado', 'en_proceso')
            ->assertJsonPath('data.evaluacion.pasa_seleccion', null);
    }

    public function test_re_evaluar_actualiza_y_no_duplica(): void
    {
        $ficha = $this->ficha('2896411');
        $evaluador = $this->usuario(Role::Aprendiz, 'evaluador', ['ficha_id' => $ficha->id]);
        $convocatoria = $this->convocatoria($ficha);
        $this->documentosConPeso($convocatoria, [
            'hoja_de_vida' => null,
            'documento_identidad' => null,
        ]);
        $postulacion = $this->postulacion($convocatoria);

        $this->actingAs($evaluador)->putJson("/api/evaluaciones/{$postulacion->id}", [
            'criterios' => $this->criterios(),
        ])->assertOk();

        $this->actingAs($evaluador)->putJson("/api/evaluaciones/{$postulacion->id}", [
            'criterios' => [['criterio' => 'Único', 'puntaje' => 100, 'ponderacion' => 1]],
        ])->assertOk()->assertJsonPath('data.evaluacion.puntaje_total', '100.00');

        $this->assertSame(1, Evaluacion::where('postulacion_id', $postulacion->id)->count());
        $this->assertDatabaseCount('evaluacion_detalles', 1);
    }

    public function test_un_borrador_no_aparece_en_el_listado_de_evaluacion(): void
    {
        $ficha = $this->ficha('2896411');
        $evaluador = $this->usuario(Role::Aprendiz, 'evaluador', ['ficha_id' => $ficha->id]);
        $enviada = $this->postulacion($this->convocatoria($ficha));
        $borrador = $this->postulacion($this->convocatoria($ficha));
        $borrador->update(['estado_id' => $this->parametroId('estado_postulacion', 'borrador')]);

        $ids = collect($this->actingAs($evaluador)->getJson('/api/evaluaciones')->json('data'))
            ->pluck('id')->all();

        $this->assertContains($enviada->id, $ids);
        $this->assertNotContains($borrador->id, $ids);
    }

    public function test_no_se_puede_evaluar_una_postulacion_en_borrador(): void
    {
        $ficha = $this->ficha('2896411');
        $evaluador = $this->usuario(Role::Aprendiz, 'evaluador', ['ficha_id' => $ficha->id]);
        $postulacion = $this->postulacion($this->convocatoria($ficha));
        $postulacion->update(['estado_id' => $this->parametroId('estado_postulacion', 'borrador')]);

        $this->actingAs($evaluador)->putJson("/api/evaluaciones/{$postulacion->id}", [
            'criterios' => $this->criterios(),
        ])->assertStatus(422);

        $this->assertDatabaseMissing('evaluaciones', [
            'postulacion_id' => $postulacion->id,
            'evaluador_id' => $evaluador->id,
        ]);
    }

    public function test_no_se_puede_evaluar_una_postulacion_retirada(): void
    {
        $ficha = $this->ficha('2896411');
        $evaluador = $this->usuario(Role::Aprendiz, 'evaluador', ['ficha_id' => $ficha->id]);
        $postulacion = $this->postulacion($this->convocatoria($ficha));
        $postulacion->update(['estado_id' => $this->parametroId('estado_postulacion', 'retirada')]);

        $this->actingAs($evaluador)->putJson("/api/evaluaciones/{$postulacion->id}", [
            'criterios' => $this->criterios(),
        ])->assertStatus(422);
    }

    public function test_no_puede_evaluar_postulacion_de_otra_ficha(): void
    {
        $fichaA = $this->ficha('2896411');
        $fichaB = $this->ficha('2631620');
        $evaluador = $this->usuario(Role::Aprendiz, 'evaluador', ['ficha_id' => $fichaA->id]);
        $ajena = $this->postulacion($this->convocatoria($fichaB));

        // Fuera de alcance se responde 404: no se revela que la postulación existe en otra ficha.
        $this->actingAs($evaluador)->putJson("/api/evaluaciones/{$ajena->id}", [
            'criterios' => $this->criterios(),
        ])->assertNotFound();
    }

    public function test_aspirante_no_puede_evaluar(): void
    {
        $ficha = $this->ficha('2896411');
        $aspirante = $this->usuario(Role::Aspirante, null, ['ficha_id' => $ficha->id]);
        $postulacion = $this->postulacion($this->convocatoria($ficha), $aspirante);

        $this->actingAs($aspirante)->putJson("/api/evaluaciones/{$postulacion->id}", [
            'criterios' => $this->criterios(),
        ])->assertForbidden();
    }

    // ── Validaciones ───────────────────────────────────────────────────

    public function test_criterios_son_obligatorios(): void
    {
        $ficha = $this->ficha('2896411');
        $evaluador = $this->usuario(Role::Aprendiz, 'evaluador', ['ficha_id' => $ficha->id]);
        $postulacion = $this->postulacion($this->convocatoria($ficha));

        $this->actingAs($evaluador)->putJson("/api/evaluaciones/{$postulacion->id}", [
            'criterios' => [],
        ])->assertStatus(422)->assertJsonValidationErrors('criterios');
    }

    public function test_puntaje_fuera_de_rango_rechaza(): void
    {
        $ficha = $this->ficha('2896411');
        $evaluador = $this->usuario(Role::Aprendiz, 'evaluador', ['ficha_id' => $ficha->id]);
        $postulacion = $this->postulacion($this->convocatoria($ficha));

        $this->actingAs($evaluador)->putJson("/api/evaluaciones/{$postulacion->id}", [
            'criterios' => [['criterio' => 'Experiencia', 'puntaje' => 150]],
        ])->assertStatus(422)->assertJsonValidationErrors('criterios.0.puntaje');
    }

    // ── Filtro por ficha ───────────────────────────────────────────────

}
