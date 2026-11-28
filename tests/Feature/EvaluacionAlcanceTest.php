<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Evaluacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EvaluacionAyudas;
use Tests\TestCase;

class EvaluacionAlcanceTest extends TestCase
{
    use RefreshDatabase;
    use EvaluacionAyudas;

    public function test_invitado_recibe_401(): void
    {
        $this->getJson('/api/evaluaciones')->assertUnauthorized();
    }

    public function test_aspirante_sin_permiso_recibe_403(): void
    {
        $aspirante = $this->usuario(Role::Aspirante);
        $this->actingAs($aspirante)->getJson('/api/evaluaciones')->assertForbidden();
    }

    // ── Listado ────────────────────────────────────────────────────────

    public function test_evaluador_ve_solo_postulaciones_de_su_ficha(): void
    {
        $fichaA = $this->ficha('2896411');
        $fichaB = $this->ficha('2631620');

        $evaluador = $this->usuario(Role::Aprendiz, 'evaluador', ['ficha_id' => $fichaA->id]);
        $mias = $this->postulacion($this->convocatoria($fichaA));
        $ajenas = $this->postulacion($this->convocatoria($fichaB));

        $ids = collect($this->actingAs($evaluador)->getJson('/api/evaluaciones')->json('data'))
            ->pluck('id')->all();

        $this->assertContains($mias->id, $ids);
        $this->assertNotContains($ajenas->id, $ids);
    }

    public function test_evaluador_no_ve_la_evaluacion_de_otro_evaluador(): void
    {
        $ficha = $this->ficha('2896411');
        $yo = $this->usuario(Role::Aprendiz, 'evaluador', ['ficha_id' => $ficha->id]);
        $otro = $this->usuario(Role::Aprendiz, 'evaluador', ['ficha_id' => $ficha->id]);
        $postulacion = $this->postulacion($this->convocatoria($ficha));

        Evaluacion::create([
            'postulacion_id' => $postulacion->id,
            'evaluador_id' => $otro->id,
            'puntaje_total' => 90,
            'estado' => 'finalizada',
        ]);

        $this->actingAs($yo)->getJson('/api/evaluaciones')
            ->assertOk()
            ->assertJsonPath('data.0.evaluacion', null);
    }

    // ── Registrar evaluación ───────────────────────────────────────────

    public function test_el_instructor_ve_la_evaluacion_del_aprendiz_evaluador(): void
    {
        $ficha = $this->ficha('2896411');
        $instructor = $this->usuario(Role::Instructor, null, ['ficha_id' => $ficha->id]);
        $evaluador = $this->usuario(Role::Aprendiz, 'evaluador', ['ficha_id' => $ficha->id]);
        $postulacion = $this->postulacion($this->convocatoria($ficha, $instructor));

        $evaluacion = Evaluacion::create([
            'postulacion_id' => $postulacion->id,
            'evaluador_id' => $evaluador->id,
            'puntaje_total' => 90,
            'estado' => 'finalizada',
        ]);

        // El Instructor actúa como admin: en el listado de trabajo y en el
        // detalle ve la evaluación que ya realizó el aprendiz con rol evaluador.
        $this->actingAs($instructor)->getJson('/api/evaluaciones')
            ->assertOk()
            ->assertJsonPath('data.0.evaluacion.id', $evaluacion->id)
            ->assertJsonPath('data.0.evaluacion.evaluador_id', $evaluador->id)
            ->assertJsonPath('data.0.evaluacion.es_propia', false);

        $this->actingAs($instructor)->getJson("/api/evaluaciones/{$postulacion->id}")
            ->assertOk()
            ->assertJsonPath('data.evaluacion.id', $evaluacion->id)
            ->assertJsonPath('data.evaluacion.evaluador_id', $evaluador->id)
            ->assertJsonPath('data.evaluacion.es_propia', false);

        // El evaluador reconoce la misma evaluación como propia.
        $this->actingAs($evaluador)->getJson("/api/evaluaciones/{$postulacion->id}")
            ->assertOk()
            ->assertJsonPath('data.evaluacion.id', $evaluacion->id)
            ->assertJsonPath('data.evaluacion.es_propia', true);
    }

    public function test_el_super_admin_ve_la_evaluacion_del_aprendiz_evaluador(): void
    {
        $ficha = $this->ficha('2896411');
        $superAdmin = $this->usuario(Role::SuperAdmin);
        $evaluador = $this->usuario(Role::Aprendiz, 'evaluador', ['ficha_id' => $ficha->id]);
        $postulacion = $this->postulacion($this->convocatoria($ficha));

        $evaluacion = Evaluacion::create([
            'postulacion_id' => $postulacion->id,
            'evaluador_id' => $evaluador->id,
            'puntaje_total' => 80,
            'estado' => 'finalizada',
        ]);

        // El super admin ve el trabajo de los evaluadores en el listado y en
        // "Mis evaluaciones", sin pertenecer a la ficha del aspirante.
        $this->actingAs($superAdmin)->getJson('/api/evaluaciones')
            ->assertOk()
            ->assertJsonPath('data.0.evaluacion.id', $evaluacion->id)
            ->assertJsonPath('data.0.evaluacion.evaluador_id', $evaluador->id);

        $this->actingAs($superAdmin)->getJson('/api/mis-evaluaciones')
            ->assertOk()
            ->assertJsonPath('data.0.id', $evaluacion->id)
            ->assertJsonPath('data.0.evaluador.id', $evaluador->id);
    }

    public function test_la_evaluacion_del_evaluador_prevalece_sobre_la_del_admin(): void
    {
        $ficha = $this->ficha('2896411');
        $instructor = $this->usuario(Role::Instructor, null, ['ficha_id' => $ficha->id]);
        $superAdmin = $this->usuario(Role::SuperAdmin);
        $evaluador = $this->usuario(Role::Aprendiz, 'evaluador', ['ficha_id' => $ficha->id]);
        $postulacion = $this->postulacion($this->convocatoria($ficha, $instructor));

        // El admin ya tenía una evaluación finalizada...
        Evaluacion::create([
            'postulacion_id' => $postulacion->id,
            'evaluador_id' => $instructor->id,
            'puntaje_total' => 100,
            'estado' => 'finalizada',
        ]);

        // ...pero el evaluador tiene un borrador más reciente. El admin y el
        // super admin deben ver el trabajo del evaluador, no la evaluación
        // propia del admin.
        $delEvaluador = Evaluacion::create([
            'postulacion_id' => $postulacion->id,
            'evaluador_id' => $evaluador->id,
            'puntaje_total' => 50,
            'estado' => 'en_proceso',
        ]);

        $this->actingAs($instructor)->getJson('/api/evaluaciones')
            ->assertOk()
            ->assertJsonPath('data.0.evaluacion.id', $delEvaluador->id)
            ->assertJsonPath('data.0.evaluacion.evaluador_id', $evaluador->id);

        $this->actingAs($instructor)->getJson("/api/evaluaciones/{$postulacion->id}")
            ->assertOk()
            ->assertJsonPath('data.evaluacion.id', $delEvaluador->id);

        $this->actingAs($superAdmin)->getJson('/api/evaluaciones')
            ->assertOk()
            ->assertJsonPath('data.0.evaluacion.id', $delEvaluador->id)
            ->assertJsonPath('data.0.evaluacion.evaluador_id', $evaluador->id);
    }

    public function test_el_instructor_edita_la_evaluacion_del_aprendiz_sin_duplicarla(): void
    {
        $ficha = $this->ficha('2896411');
        $instructor = $this->usuario(Role::Instructor, null, ['ficha_id' => $ficha->id]);
        $evaluador = $this->usuario(Role::Aprendiz, 'evaluador', ['ficha_id' => $ficha->id]);
        $convocatoria = $this->convocatoria($ficha, $instructor);
        $ids = $this->documentosConPeso($convocatoria, [
            'hoja_de_vida' => 50,
            'documento_identidad' => 50,
        ]);
        $postulacion = $this->conDocumentosCargados($this->postulacion($convocatoria));

        // El aprendiz evaluador finaliza con el 100 %.
        $this->actingAs($evaluador)->putJson("/api/evaluaciones/{$postulacion->id}", [
            'estado' => 'finalizada',
            'criterios' => [['criterio' => 'Evaluación', 'puntaje' => 100, 'ponderacion' => 1]],
            'perfil' => [
                'doc-'.$ids['hoja_de_vida'] => true,
                'doc-'.$ids['documento_identidad'] => true,
            ],
        ])->assertOk()->assertJsonPath('data.evaluacion.cumplimiento', 100);

        // El Instructor corrige esa misma evaluación como administrador.
        $this->actingAs($instructor)->putJson("/api/evaluaciones/{$postulacion->id}", [
            'estado' => 'finalizada',
            'observaciones' => 'Falta el documento de identidad.',
            'criterios' => [['criterio' => 'Evaluación', 'puntaje' => 50, 'ponderacion' => 1]],
            'perfil' => [
                'doc-'.$ids['hoja_de_vida'] => true,
                'doc-'.$ids['documento_identidad'] => false,
            ],
        ])->assertOk()
            ->assertJsonPath('data.evaluacion.cumplimiento', 50)
            ->assertJsonPath('data.evaluacion.pasa_seleccion', false)
            ->assertJsonPath('data.evaluacion.evaluador_id', $evaluador->id);

        // Sigue existiendo una sola fila y conserva al evaluador original: el
        // Instructor administra la evaluación, no crea una paralela.
        $this->assertDatabaseCount('evaluaciones', 1);
        $this->assertDatabaseHas('evaluaciones', [
            'postulacion_id' => $postulacion->id,
            'evaluador_id' => $evaluador->id,
            'cumplimiento' => 50,
            'pasa_seleccion' => false,
        ]);
    }

}
