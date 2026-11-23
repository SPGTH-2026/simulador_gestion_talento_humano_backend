<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Convocatoria;
use App\Models\CorreccionPostulacion;
use App\Models\Evaluacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\EvaluacionAyudas;
use Tests\TestCase;

class EvaluacionCorreccionPlazoTest extends TestCase
{
    use RefreshDatabase;
    use EvaluacionAyudas;

    public function test_pedir_correccion_fija_plazo_de_dos_dias_que_se_conserva_y_se_limpia_al_finalizar(): void
    {
        Storage::fake('local');

        $ficha = $this->ficha('2896413');
        $evaluador = $this->usuario(Role::Aprendiz, 'evaluador', ['ficha_id' => $ficha->id]);
        $aspirante = $this->usuario(Role::Aspirante, null, ['ficha_id' => $ficha->id]);
        $convocatoria = $this->convocatoria($ficha);
        $hoja = $this->parametroId('tipo_documento', 'hoja_de_vida');
        $convocatoria->documentos()->attach($hoja, ['obligatorio' => true, 'ponderacion' => 100]);
        $postulacion = $this->postulacion($convocatoria, $aspirante);

        $solicitud = [
            'estado' => 'correccion_solicitada',
            'observaciones' => 'La hoja de vida está borrosa.',
            'criterios' => [['criterio' => 'Evaluación', 'puntaje' => 0, 'ponderacion' => 1]],
            'perfil' => ['doc-'.$hoja => false],
        ];

        // 1) Al pedir la corrección se fija un plazo de dos días.
        $this->actingAs($evaluador)->putJson("/api/evaluaciones/{$postulacion->id}", $solicitud)->assertOk();
        $primero = Evaluacion::where('postulacion_id', $postulacion->id)->firstOrFail()->fecha_limite_correccion;
        $this->assertNotNull($primero);
        $this->assertEqualsWithDelta(now()->addDays(2)->timestamp, $primero->timestamp, 60);

        // 2) Volver a guardar la misma solicitud conserva el plazo original.
        $this->actingAs($evaluador)->putJson("/api/evaluaciones/{$postulacion->id}", $solicitud)->assertOk();
        $this->assertEquals(
            $primero->timestamp,
            Evaluacion::where('postulacion_id', $postulacion->id)->firstOrFail()->fecha_limite_correccion->timestamp,
        );

        // 3) El aspirante ve el plazo en su listado.
        $this->actingAs($aspirante)->getJson('/api/mis-evaluaciones')
            ->assertOk()
            ->assertJsonPath('data.0.fecha_limite_correccion', fn ($v) => $v !== null);

        // 4) Al finalizar, el plazo deja de aplicar. Antes el aspirante carga la hoja de vida (finalizar exige los obligatorios).
        $this->actingAs($aspirante)->withHeaders(['Accept' => 'application/json'])
            ->post("/api/postulaciones/{$postulacion->id}/documentos", [
                'codigo' => 'hoja_de_vida',
                'archivo' => UploadedFile::fake()->create('hoja.pdf', 100, 'application/pdf'),
            ])->assertCreated();
        $this->actingAs($evaluador)->putJson("/api/evaluaciones/{$postulacion->id}", [
            'estado' => 'finalizada',
            'criterios' => [['criterio' => 'Evaluación', 'puntaje' => 80, 'ponderacion' => 1]],
            'perfil' => ['doc-'.$hoja => true],
        ])->assertOk();
        $this->assertNull(Evaluacion::where('postulacion_id', $postulacion->id)->firstOrFail()->fecha_limite_correccion);
    }

    public function test_una_nueva_solicitud_despues_de_enviar_abre_otro_plazo(): void
    {
        Storage::fake('local');

        $ficha = $this->ficha('2896414');
        $evaluador = $this->usuario(Role::Aprendiz, 'evaluador', ['ficha_id' => $ficha->id]);
        $aspirante = $this->usuario(Role::Aspirante, null, ['ficha_id' => $ficha->id]);
        $convocatoria = $this->convocatoria($ficha);
        $hoja = $this->parametroId('tipo_documento', 'hoja_de_vida');
        $convocatoria->documentos()->attach($hoja, ['obligatorio' => true, 'ponderacion' => 100]);
        $postulacion = $this->postulacion($convocatoria, $aspirante);

        $url = "/api/evaluaciones/{$postulacion->id}";
        $solicitud = fn (string $observaciones) => [
            'estado' => 'correccion_solicitada',
            'observaciones' => $observaciones,
            'criterios' => [['criterio' => 'Evaluación', 'puntaje' => 0, 'ponderacion' => 1]],
            'perfil' => ['doc-'.$hoja => false],
        ];

        // Primera ronda: el aspirante reemplaza la hoja y envía la corrección.
        $this->actingAs($evaluador)->putJson($url, $solicitud('La hoja de vida está borrosa.'))->assertOk();
        $this->actingAs($aspirante)->withHeaders(['Accept' => 'application/json'])
            ->post("/api/postulaciones/{$postulacion->id}/documentos", [
                'codigo' => 'hoja_de_vida',
                'archivo' => UploadedFile::fake()->create('hoja.pdf', 100, 'application/pdf'),
            ])->assertCreated();
        $this->actingAs($aspirante)->postJson("/api/postulaciones/{$postulacion->id}/correccion/enviar")->assertOk();

        // El evaluador tarda y la primera ronda ya venció.
        Evaluacion::where('postulacion_id', $postulacion->id)->update(['fecha_limite_correccion' => now()->subDay()]);

        // Segunda ronda: el plazo se renueva y el aspirante puede volver a corregir.
        $this->actingAs($evaluador)->putJson($url, $solicitud('Falta la firma en la hoja de vida.'))->assertOk();
        $segundo = Evaluacion::where('postulacion_id', $postulacion->id)->firstOrFail()->fecha_limite_correccion;
        $this->assertEqualsWithDelta(now()->addDays(2)->timestamp, $segundo->timestamp, 60);

        $this->actingAs($aspirante)->withHeaders(['Accept' => 'application/json'])
            ->post("/api/postulaciones/{$postulacion->id}/documentos", [
                'codigo' => 'hoja_de_vida',
                'archivo' => UploadedFile::fake()->create('hoja.pdf', 100, 'application/pdf'),
            ])->assertOk();
        $this->actingAs($aspirante)->postJson("/api/postulaciones/{$postulacion->id}/correccion/enviar")->assertOk();
    }

    public function test_con_el_plazo_vencido_el_aspirante_no_puede_enviar_la_correccion(): void
    {
        Storage::fake('local');

        $ficha = $this->ficha('2896414');
        $evaluador = $this->usuario(Role::Aprendiz, 'evaluador', ['ficha_id' => $ficha->id]);
        $aspirante = $this->usuario(Role::Aspirante, null, ['ficha_id' => $ficha->id]);
        $convocatoria = $this->convocatoria($ficha);
        $hoja = $this->parametroId('tipo_documento', 'hoja_de_vida');
        $convocatoria->documentos()->attach($hoja, ['obligatorio' => true, 'ponderacion' => 100]);
        $postulacion = $this->postulacion($convocatoria, $aspirante);

        $this->actingAs($evaluador)->putJson("/api/evaluaciones/{$postulacion->id}", [
            'estado' => 'correccion_solicitada',
            'observaciones' => 'Hoja de vida borrosa.',
            'criterios' => [['criterio' => 'Evaluación', 'puntaje' => 0, 'ponderacion' => 1]],
            'perfil' => ['doc-'.$hoja => false],
        ])->assertOk();

        // El plazo ya pasó (el plazo vive en la ronda de corrección de la postulación).
        CorreccionPostulacion::where('postulacion_id', $postulacion->id)->update([
            'fecha_limite' => now()->subMinute(),
        ]);

        // Aunque reemplace el documento, el envío se rechaza.
        $this->actingAs($aspirante)->withHeaders(['Accept' => 'application/json'])
            ->post("/api/postulaciones/{$postulacion->id}/documentos", [
                'codigo' => 'hoja_de_vida',
                'archivo' => UploadedFile::fake()->create('hoja.pdf', 100, 'application/pdf'),
            ])->assertCreated();

        $this->actingAs($aspirante)->postJson("/api/postulaciones/{$postulacion->id}/correccion/enviar")
            ->assertStatus(422)
            ->assertJsonPath('errors.correccion.0', fn ($m) => str_contains($m, 'venció'));

        $this->assertNull(Evaluacion::where('postulacion_id', $postulacion->id)->firstOrFail()->correccion_enviada_at);
    }

    public function test_reemplazar_documento_reabre_evaluacion_finalizada_a_en_proceso(): void
    {
        Storage::fake('local');

        $ficha = $this->ficha('2896411');
        $evaluador = $this->usuario(Role::Aprendiz, 'evaluador', ['ficha_id' => $ficha->id]);
        $aspirante = $this->usuario(Role::Aspirante, null, ['ficha_id' => $ficha->id]);
        $convocatoria = $this->convocatoria($ficha);
        $convocatoria->forceFill([
            'fecha_apertura' => now('America/Bogota')->subDay()->toDateString(),
            'fecha_cierre' => now('America/Bogota')->addDay()->toDateString(),
        ])->save();

        $hoja = $this->parametroId('tipo_documento', 'hoja_de_vida');
        $convocatoria->documentos()->attach($hoja, ['obligatorio' => true, 'ponderacion' => 100]);

        $postulacion = $this->conDocumentosCargados($this->postulacion($convocatoria, $aspirante));

        $this->actingAs($evaluador)->putJson("/api/evaluaciones/{$postulacion->id}", [
            'estado' => 'finalizada',
            'criterios' => [['criterio' => 'Evaluación', 'puntaje' => 100, 'ponderacion' => 1]],
            'perfil' => ['doc-'.$hoja => true],
        ])->assertOk()
            ->assertJsonPath('data.evaluacion.estado', 'finalizada')
            ->assertJsonPath('data.evaluacion.pasa_seleccion', true);

        // Si el aspirante reemplaza el documento (ya existía: 200), la evaluación finalizada pasa a en_proceso.
        $this->actingAs($aspirante)
            ->withHeaders(['Accept' => 'application/json'])
            ->post("/api/postulaciones/{$postulacion->id}/documentos", [
                'codigo' => 'hoja_de_vida',
                'archivo' => UploadedFile::fake()->create('hoja_nueva.pdf', 100, 'application/pdf'),
            ])->assertOk();

        $this->actingAs($evaluador)->getJson("/api/evaluaciones/{$postulacion->id}")
            ->assertOk()
            ->assertJsonPath('data.evaluacion.estado', 'en_proceso')
            ->assertJsonPath('data.evaluacion.pasa_seleccion', null);
    }

    public function test_no_se_pide_otra_correccion_mientras_hay_una_vigente(): void
    {
        [$evaluador, $aspirante, $postulacion, $cuerpo] = $this->escenarioCorreccion();

        $this->actingAs($evaluador)->putJson("/api/evaluaciones/{$postulacion->id}", $cuerpo('La hoja está borrosa.'))
            ->assertOk()
            ->assertJsonPath('data.evaluacion.correccion_estado', 'vigente');

        // Reenvío idéntico: se conserva el comportamiento de siempre.
        $this->actingAs($evaluador)->putJson("/api/evaluaciones/{$postulacion->id}", $cuerpo('La hoja está borrosa.'))
            ->assertOk();

        // Una solicitud distinta encima de la vigente se rechaza y no cambia nada.
        $this->actingAs($evaluador)->putJson("/api/evaluaciones/{$postulacion->id}", $cuerpo('Otra observación distinta.'))
            ->assertStatus(422)
            ->assertJsonPath('message', 'Ya hay una corrección pendiente: espera la respuesta del aspirante antes de pedir otra.');

        $evaluacion = Evaluacion::where('postulacion_id', $postulacion->id)->sole();
        $this->assertSame('La hoja está borrosa.', $evaluacion->observaciones);
        $this->assertSame(1, Evaluacion::where('postulacion_id', $postulacion->id)->count());
    }

    public function test_el_estado_de_la_correccion_sale_del_servidor_y_no_depende_de_la_convocatoria(): void
    {
        // Convocatoria cerrada (la ventana de postulación ya terminó) con corrección vigente.
        [$evaluador, $aspirante, $postulacion, $cuerpo] = $this->escenarioCorreccion('cerrada');

        $this->actingAs($evaluador)->putJson("/api/evaluaciones/{$postulacion->id}", $cuerpo('Reemplaza la hoja.'))->assertOk();

        $this->actingAs($aspirante)->getJson('/api/mis-evaluaciones')
            ->assertOk()
            ->assertJsonPath('data.0.correccion_estado', 'vigente');

        $ronda = CorreccionPostulacion::where('postulacion_id', $postulacion->id)->sole();

        // Plazo vencido sin respuesta.
        $ronda->forceFill(['fecha_limite' => now()->subMinute()])->save();
        $this->actingAs($aspirante)->getJson('/api/mis-evaluaciones')->assertJsonPath('data.0.correccion_estado', 'vencida');

        // Respondida.
        $ronda->forceFill(['fecha_limite' => now()->addDay(), 'respondida_at' => now()])->save();
        $this->actingAs($aspirante)->getJson('/api/mis-evaluaciones')->assertJsonPath('data.0.correccion_estado', 'enviada');
    }

}
