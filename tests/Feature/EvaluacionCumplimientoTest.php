<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Parametro;
use App\Models\ParametroTema;
use App\Models\PerfilAspirante;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EvaluacionAyudas;
use Tests\TestCase;

class EvaluacionCumplimientoTest extends TestCase
{
    use RefreshDatabase;
    use EvaluacionAyudas;

    public function test_cumplimiento_usa_los_porcentajes_de_la_convocatoria(): void
    {
        $ficha = $this->ficha('2896411');
        $evaluador = $this->usuario(Role::Aprendiz, 'evaluador', ['ficha_id' => $ficha->id]);
        $convocatoria = $this->convocatoria($ficha);
        $postulacion = $this->postulacion($convocatoria);

        // Hoja de vida vale 80 % y el documento de identidad 20 %.
        $ids = $this->documentosConPeso($convocatoria, [
            'hoja_de_vida' => 80,
            'documento_identidad' => 20,
        ]);
        $this->conDocumentosCargados($postulacion);

        // Solo cumple el documento que vale 80 % → 80 % de cumplimiento.
        $this->actingAs($evaluador)->putJson("/api/evaluaciones/{$postulacion->id}", [
            'estado' => 'finalizada',
            'observaciones' => 'Falta el documento de identidad.',
            'criterios' => [['criterio' => 'Evaluación', 'puntaje' => 80, 'ponderacion' => 1]],
            'perfil' => [
                'doc-'.$ids['hoja_de_vida'] => true,
                'doc-'.$ids['documento_identidad'] => false,
            ],
            // Se envía un valor distinto a propósito: el servidor manda.
            'cumplimiento' => 100,
        ])->assertOk()
            ->assertJsonPath('data.evaluacion.cumplimiento', 80)
            ->assertJsonPath('data.evaluacion.pasa_seleccion', true);
    }

    public function test_el_conteo_de_cargados_solo_incluye_documentos_requeridos(): void
    {
        $ficha = $this->ficha('2896411');
        $evaluador = $this->usuario(Role::Aprendiz, 'evaluador', ['ficha_id' => $ficha->id]);
        $convocatoria = $this->convocatoria($ficha);
        $postulacion = $this->postulacion($convocatoria);

        $ids = $this->documentosConPeso($convocatoria, [
            'hoja_de_vida' => 50,
            'documento_identidad' => 50,
        ]);

        // Un requerido cargado y un documento adicional (sin parámetro).
        $archivo = [
            'nombre_original' => 'archivo.pdf',
            'ruta' => 'postulaciones/test/archivo.pdf',
            'disco' => 'local',
            'mime' => 'application/pdf',
            'tamano_bytes' => 1024,
        ];
        $postulacion->documentos()->create($archivo + ['parametro_id' => $ids['hoja_de_vida']]);
        $postulacion->documentos()->create($archivo + ['parametro_id' => null, 'titulo' => 'Extra']);

        $this->actingAs($evaluador)->getJson('/api/evaluaciones')
            ->assertOk()
            ->assertJsonPath('data.0.documentos.total', 2)
            ->assertJsonPath('data.0.documentos.cargados', 1);
    }

    public function test_sin_porcentajes_configurados_reparte_en_partes_iguales(): void
    {
        $ficha = $this->ficha('2896411');
        $evaluador = $this->usuario(Role::Aprendiz, 'evaluador', ['ficha_id' => $ficha->id]);
        $convocatoria = $this->convocatoria($ficha);
        $postulacion = $this->postulacion($convocatoria);

        $ids = $this->documentosConPeso($convocatoria, [
            'hoja_de_vida' => null,
            'documento_identidad' => null,
        ]);
        $this->conDocumentosCargados($postulacion);

        // Dos documentos sin peso y solo uno cumplido → 50 %.
        $this->actingAs($evaluador)->putJson("/api/evaluaciones/{$postulacion->id}", [
            'estado' => 'finalizada',
            'observaciones' => 'Falta el documento de identidad.',
            'criterios' => [['criterio' => 'Evaluación', 'puntaje' => 50, 'ponderacion' => 1]],
            'perfil' => [
                'doc-'.$ids['hoja_de_vida'] => true,
                'doc-'.$ids['documento_identidad'] => false,
            ],
        ])->assertOk()
            ->assertJsonPath('data.evaluacion.cumplimiento', 50)
            ->assertJsonPath('data.evaluacion.pasa_seleccion', false);
    }

    public function test_documentos_no_obligatorios_no_cuentan_para_el_60(): void
    {
        $ficha = $this->ficha('2896411');
        $evaluador = $this->usuario(Role::Aprendiz, 'evaluador', ['ficha_id' => $ficha->id]);
        $convocatoria = $this->convocatoria($ficha);
        $postulacion = $this->postulacion($convocatoria);

        $obligatorio = $this->parametroId('tipo_documento', 'hoja_de_vida');
        $opcional = $this->parametroId('tipo_documento', 'tarjeta_profesional');

        $convocatoria->documentos()->attach($obligatorio, ['obligatorio' => true, 'ponderacion' => null]);
        $convocatoria->documentos()->attach($opcional, ['obligatorio' => false, 'ponderacion' => null]);
        $this->conDocumentosCargados($postulacion);

        // Solo cumple el obligatorio. Si el opcional contara, el resultado sería 50.
        $this->actingAs($evaluador)->putJson("/api/evaluaciones/{$postulacion->id}", [
            'estado' => 'finalizada',
            'criterios' => [['criterio' => 'Evaluación', 'puntaje' => 100, 'ponderacion' => 1]],
            'perfil' => ['doc-'.$obligatorio => true],
        ])->assertOk()
            ->assertJsonPath('data.evaluacion.cumplimiento', 100)
            ->assertJsonPath('data.evaluacion.pasa_seleccion', true);
    }

    public function test_un_documento_nuevo_obligatorio_cuenta_para_el_60(): void
    {
        $ficha = $this->ficha('2896411');
        $evaluador = $this->usuario(Role::Aprendiz, 'evaluador', ['ficha_id' => $ficha->id]);
        $convocatoria = $this->convocatoria($ficha);
        $postulacion = $this->postulacion($convocatoria);

        // Documento nuevo del catálogo (sin código), obligatorio y con todo el
        // peso: debe contar igual que los documentos fijos.
        $tema = ParametroTema::where('codigo', 'tipo_documento')->firstOrFail();
        $nuevo = Parametro::create([
            'tema_id' => $tema->id,
            'codigo' => null,
            'nombre' => 'Especialización',
            'orden' => 99,
            'activo' => true,
        ]);

        $convocatoria->documentos()->attach($nuevo->id, [
            'obligatorio' => true,
            'ponderacion' => 100,
        ]);
        $this->conDocumentosCargados($postulacion);

        $this->actingAs($evaluador)->putJson("/api/evaluaciones/{$postulacion->id}", [
            'estado' => 'finalizada',
            'criterios' => [['criterio' => 'Evaluación', 'puntaje' => 100, 'ponderacion' => 1]],
            'perfil' => ['doc-'.$nuevo->id => true],
        ])->assertOk()
            ->assertJsonPath('data.evaluacion.cumplimiento', 100)
            ->assertJsonPath('data.evaluacion.pasa_seleccion', true);
    }

    public function test_la_libreta_militar_de_una_mujer_no_cuenta_para_el_60(): void
    {
        $ficha = $this->ficha('2896411');
        $evaluador = $this->usuario(Role::Aprendiz, 'evaluador', ['ficha_id' => $ficha->id]);
        $convocatoria = $this->convocatoria($ficha);
        $aspirante = $this->usuario(Role::Aspirante, null, ['ficha_id' => $ficha->id]);

        PerfilAspirante::create([
            'user_id' => $aspirante->id,
            'sexo_id' => $this->parametroId('sexo', 'mujer'),
        ]);

        $postulacion = $this->postulacion($convocatoria, $aspirante);

        $hoja = $this->parametroId('tipo_documento', 'hoja_de_vida');
        $libreta = $this->parametroId('tipo_documento', 'libreta_militar');

        $convocatoria->documentos()->attach($hoja, ['obligatorio' => true, 'ponderacion' => null]);
        $convocatoria->documentos()->attach($libreta, ['obligatorio' => true, 'ponderacion' => null]);
        // Solo sube la hoja de vida: la libreta no aplica a una mujer y no se le exige.
        $postulacion->documentos()->create([
            'nombre_original' => 'hoja.pdf', 'ruta' => "postulaciones/test/{$postulacion->id}-hoja.pdf", 'disco' => 'local',
            'mime' => 'application/pdf', 'tamano_bytes' => 1024, 'parametro_id' => $hoja,
        ]);

        // La libreta no aplica: solo pesa la hoja de vida.
        $this->actingAs($evaluador)->putJson("/api/evaluaciones/{$postulacion->id}", [
            'estado' => 'finalizada',
            'criterios' => [['criterio' => 'Evaluación', 'puntaje' => 100, 'ponderacion' => 1]],
            'perfil' => ['doc-'.$hoja => true],
        ])->assertOk()
            ->assertJsonPath('data.evaluacion.cumplimiento', 100)
            ->assertJsonPath('data.evaluacion.pasa_seleccion', true);
    }

    public function test_no_se_puede_evaluar_sin_documentos_obligatorios_aplicables(): void
    {
        $ficha = $this->ficha('2896411');
        $evaluador = $this->usuario(Role::Aprendiz, 'evaluador', ['ficha_id' => $ficha->id]);
        $postulacion = $this->postulacion($this->convocatoria($ficha));

        // Aunque el navegador mande cumplimiento 100, sin documentos no hay base
        // para decidir el 60 %: se rechaza.
        $this->actingAs($evaluador)->putJson("/api/evaluaciones/{$postulacion->id}", [
            'estado' => 'finalizada',
            'criterios' => [['criterio' => 'Evaluación', 'puntaje' => 100, 'ponderacion' => 1]],
            'cumplimiento' => 100,
        ])->assertStatus(422);
    }

    // ── Documentos faltantes (RF-DOC-006 + RF-EVA-003) ─────────────────

}
