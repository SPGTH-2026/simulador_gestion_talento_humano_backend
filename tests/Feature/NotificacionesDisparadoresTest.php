<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Notificacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EvaluacionAyudas;
use Tests\TestCase;

class NotificacionesDisparadoresTest extends TestCase
{
    use RefreshDatabase;
    use EvaluacionAyudas;

    public function test_pedir_correccion_avisa_al_aspirante(): void
    {
        $ficha = $this->ficha('2896411');
        $evaluador = $this->usuario(Role::Aprendiz, 'evaluador', ['ficha_id' => $ficha->id]);
        $aspirante = $this->usuario(Role::Aspirante, null, ['ficha_id' => $ficha->id]);
        $convocatoria = $this->convocatoria($ficha);
        $hoja = $this->parametroId('tipo_documento', 'hoja_de_vida');
        $convocatoria->documentos()->attach($hoja, ['obligatorio' => true, 'ponderacion' => 100]);
        $postulacion = $this->postulacion($convocatoria, $aspirante);

        $this->actingAs($evaluador)->putJson("/api/evaluaciones/{$postulacion->id}", [
            'estado' => 'correccion_solicitada',
            'observaciones' => 'La hoja de vida está borrosa.',
            'criterios' => [['criterio' => 'Evaluación', 'puntaje' => 0, 'ponderacion' => 1]],
            'perfil' => ['doc-'.$hoja => false],
        ])->assertOk();

        $aviso = Notificacion::query()->where('user_id', $aspirante->id)->sole();
        $this->assertSame('correccion_solicitada', $aviso->tipo->value);
        $this->assertSame('/mis-postulaciones', $aviso->url);
        $this->assertNull($aviso->leida_at);
    }

    public function test_finalizar_avisa_una_sola_vez_aunque_se_vuelva_a_guardar(): void
    {
        $ficha = $this->ficha('2896411');
        $evaluador = $this->usuario(Role::Aprendiz, 'evaluador', ['ficha_id' => $ficha->id]);
        $aspirante = $this->usuario(Role::Aspirante, null, ['ficha_id' => $ficha->id]);
        $convocatoria = $this->convocatoria($ficha);
        $this->documentosConPeso($convocatoria, [
            'hoja_de_vida' => null,
            'documento_identidad' => null,
        ]);
        $postulacion = $this->conDocumentosCargados($this->postulacion($convocatoria, $aspirante));

        $this->actingAs($evaluador)->putJson("/api/evaluaciones/{$postulacion->id}", [
            'observaciones' => 'Buen perfil',
            'estado' => 'finalizada',
            'criterios' => $this->criterios(),
            'perfil' => $this->perfilDeTodos($postulacion),
        ])->assertOk();

        // Guardar otra vez la evaluación ya finalizada (con otra observación) no es un nuevo paso a «finalizada».
        $this->actingAs($evaluador)->putJson("/api/evaluaciones/{$postulacion->id}", [
            'observaciones' => 'Buen perfil, sin cambios de fondo',
            'estado' => 'finalizada',
            'criterios' => $this->criterios(),
            'perfil' => $this->perfilDeTodos($postulacion),
        ])->assertOk();

        $avisos = Notificacion::query()->where('user_id', $aspirante->id)->get();
        $this->assertCount(1, $avisos);
        $this->assertSame('evaluacion_finalizada', $avisos->first()->tipo->value);
        $this->assertSame('/mis-evaluaciones', $avisos->first()->url);
    }

    public function test_una_evaluacion_en_proceso_no_avisa(): void
    {
        $ficha = $this->ficha('2896411');
        $evaluador = $this->usuario(Role::Aprendiz, 'evaluador', ['ficha_id' => $ficha->id]);
        $aspirante = $this->usuario(Role::Aspirante, null, ['ficha_id' => $ficha->id]);
        $convocatoria = $this->convocatoria($ficha);
        $convocatoria->documentos()->attach($this->parametroId('tipo_documento', 'hoja_de_vida'), ['obligatorio' => true, 'ponderacion' => 100]);
        $postulacion = $this->postulacion($convocatoria, $aspirante);

        $this->actingAs($evaluador)->putJson("/api/evaluaciones/{$postulacion->id}", [
            'estado' => 'en_proceso',
            'criterios' => $this->criterios(),
        ])->assertOk();

        $this->assertDatabaseCount('notificaciones', 0);
    }
}
