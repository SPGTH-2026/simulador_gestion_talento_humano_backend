<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\PerfilAspirante;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EvaluacionAyudas;
use Tests\TestCase;

class EvaluacionDocumentosObligatoriosTest extends TestCase
{
    use RefreshDatabase;
    use EvaluacionAyudas;

    public function test_no_se_puede_marcar_cumple_un_documento_obligatorio_sin_archivo(): void
    {
        [$evaluador, $postulacion, $ids] = $this->postulacionSinHojaDeVida();

        foreach (['en_proceso', 'finalizada'] as $estado) {
            $this->actingAs($evaluador)->putJson("/api/evaluaciones/{$postulacion->id}", [
                'estado' => $estado,
                'criterios' => [['criterio' => 'Evaluación', 'puntaje' => 100, 'ponderacion' => 1]],
                'perfil' => ['doc-'.$ids['hoja_de_vida'] => true, 'doc-'.$ids['documento_identidad'] => true],
            ])->assertStatus(422)
                ->assertJsonPath('message', 'No puedes marcar «Cumple» un documento que el aspirante no ha cargado: Hoja de vida.');
        }

        $this->assertDatabaseCount('evaluaciones', 0);
    }

    public function test_no_se_puede_finalizar_con_un_obligatorio_aplicable_faltante_aunque_se_marque_no(): void
    {
        [$evaluador, $postulacion, $ids] = $this->postulacionSinHojaDeVida();

        // El resto cumple y alcanzaría el 60 %, pero falta un obligatorio: no se finaliza, se pide la corrección.
        $this->actingAs($evaluador)->putJson("/api/evaluaciones/{$postulacion->id}", [
            'estado' => 'finalizada',
            'criterios' => [['criterio' => 'Evaluación', 'puntaje' => 60, 'ponderacion' => 1]],
            'perfil' => ['doc-'.$ids['hoja_de_vida'] => false, 'doc-'.$ids['documento_identidad'] => true],
        ])->assertStatus(422)
            ->assertJsonPath('message', 'No se puede finalizar la evaluación: faltan documentos obligatorios (Hoja de vida). Solicita la corrección al aspirante.');

        $this->assertDatabaseCount('evaluaciones', 0);
    }

    public function test_con_un_obligatorio_faltante_se_puede_guardar_en_proceso_y_pedir_la_correccion(): void
    {
        [$evaluador, $postulacion, $ids] = $this->postulacionSinHojaDeVida();
        $perfil = ['doc-'.$ids['hoja_de_vida'] => false, 'doc-'.$ids['documento_identidad'] => true];

        $this->actingAs($evaluador)->putJson("/api/evaluaciones/{$postulacion->id}", [
            'estado' => 'en_proceso',
            'criterios' => [['criterio' => 'Evaluación', 'puntaje' => 60, 'ponderacion' => 1]],
            'perfil' => $perfil,
        ])->assertOk()->assertJsonPath('data.evaluacion.estado', 'en_proceso')->assertJsonPath('data.evaluacion.pasa_seleccion', null);

        $this->actingAs($evaluador)->putJson("/api/evaluaciones/{$postulacion->id}", [
            'estado' => 'correccion_solicitada',
            'observaciones' => 'Falta subir la hoja de vida.',
            'criterios' => [['criterio' => 'Evaluación', 'puntaje' => 60, 'ponderacion' => 1]],
            'perfil' => $perfil,
        ])->assertOk()->assertJsonPath('data.evaluacion.estado', 'correccion_solicitada')->assertJsonPath('data.evaluacion.pasa_seleccion', null);
    }

    public function test_la_libreta_de_una_mujer_y_un_opcional_ausente_no_bloquean_la_finalizacion(): void
    {
        $ficha = $this->ficha('2896411');
        $evaluador = $this->usuario(Role::Aprendiz, 'evaluador', ['ficha_id' => $ficha->id]);
        $convocatoria = $this->convocatoria($ficha);
        $aspirante = $this->usuario(Role::Aspirante, null, ['ficha_id' => $ficha->id]);
        PerfilAspirante::create(['user_id' => $aspirante->id, 'sexo_id' => $this->parametroId('sexo', 'mujer')]);
        $ids = $this->documentosConPeso($convocatoria, ['hoja_de_vida' => 100, 'libreta_militar' => null]);
        $convocatoria->documentos()->attach($this->parametroId('tipo_documento', 'tarjeta_profesional'), ['obligatorio' => false, 'ponderacion' => null]);
        $postulacion = $this->postulacion($convocatoria, $aspirante);
        $postulacion->documentos()->create([
            'nombre_original' => 'hv.pdf', 'ruta' => "postulaciones/test/{$postulacion->id}-hv.pdf", 'disco' => 'local',
            'mime' => 'application/pdf', 'tamano_bytes' => 1024, 'parametro_id' => $ids['hoja_de_vida'],
        ]);

        $this->actingAs($evaluador)->putJson("/api/evaluaciones/{$postulacion->id}", [
            'estado' => 'finalizada',
            'criterios' => [['criterio' => 'Evaluación', 'puntaje' => 100, 'ponderacion' => 1]],
            'perfil' => ['doc-'.$ids['hoja_de_vida'] => true],
        ])->assertOk()->assertJsonPath('data.evaluacion.pasa_seleccion', true);
    }

    public function test_el_conteo_de_documentos_excluye_opcionales_y_no_aplica(): void
    {
        $ficha = $this->ficha('2896411');
        $evaluador = $this->usuario(Role::Aprendiz, 'evaluador', ['ficha_id' => $ficha->id]);
        $convocatoria = $this->convocatoria($ficha);
        $aspirante = $this->usuario(Role::Aspirante, null, ['ficha_id' => $ficha->id]);

        PerfilAspirante::create([
            'user_id' => $aspirante->id,
            'sexo_id' => $this->parametroId('sexo', 'mujer'),
        ]);

        $this->postulacion($convocatoria, $aspirante);

        $hoja = $this->parametroId('tipo_documento', 'hoja_de_vida');
        $libreta = $this->parametroId('tipo_documento', 'libreta_militar');
        $opcional = $this->parametroId('tipo_documento', 'tarjeta_profesional');

        $convocatoria->documentos()->attach($hoja, ['obligatorio' => true, 'ponderacion' => null]);
        $convocatoria->documentos()->attach($libreta, ['obligatorio' => true, 'ponderacion' => null]);
        $convocatoria->documentos()->attach($opcional, ['obligatorio' => false, 'ponderacion' => null]);

        // Solo queda la hoja de vida: la libreta no aplica y la tarjeta es opcional.
        $this->actingAs($evaluador)->getJson('/api/evaluaciones')
            ->assertOk()
            ->assertJsonPath('data.0.documentos.total', 1)
            ->assertJsonPath('data.0.documentos.cargados', 0);
    }

}
