<?php

namespace Tests\Feature;

use App\Enums\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\EvaluacionAyudas;
use Tests\TestCase;

class EvaluacionCorreccionEnvioTest extends TestCase
{
    use RefreshDatabase;
    use EvaluacionAyudas;

    public function test_evaluador_puede_mandar_a_corregir_y_reemplazar_documento_lo_marca_actualizado(): void
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

        $postulacion = $this->postulacion($convocatoria, $aspirante);

        // 1) Mandar a corregir sin observaciones se rechaza con 422.
        $this->actingAs($evaluador)->putJson("/api/evaluaciones/{$postulacion->id}", [
            'estado' => 'correccion_solicitada',
            'criterios' => [['criterio' => 'Evaluación', 'puntaje' => 0, 'ponderacion' => 1]],
            'perfil' => ['doc-'.$hoja => false],
        ])->assertStatus(422);

        // 2) Mandar a corregir con documento en false y observaciones funciona.
        $this->actingAs($evaluador)->putJson("/api/evaluaciones/{$postulacion->id}", [
            'estado' => 'correccion_solicitada',
            'observaciones' => 'La hoja de vida está borrosa.',
            'criterios' => [['criterio' => 'Evaluación', 'puntaje' => 0, 'ponderacion' => 1]],
            'perfil' => ['doc-'.$hoja => false],
        ])->assertOk()
            ->assertJsonPath('data.evaluacion.estado', 'correccion_solicitada')
            ->assertJsonPath('data.evaluacion.pasa_seleccion', null);

        // 3) El aspirante reemplaza el documento requerido sin error 500.
        $this->actingAs($aspirante)
            ->withHeaders(['Accept' => 'application/json'])
            ->post("/api/postulaciones/{$postulacion->id}/documentos", [
                'codigo' => 'hoja_de_vida',
                'archivo' => UploadedFile::fake()->create('hoja_corregida.pdf', 100, 'application/pdf'),
            ])->assertCreated();

        // 4) El evaluador ve el documento en documentos_actualizados.
        $this->actingAs($evaluador)->getJson("/api/evaluaciones/{$postulacion->id}")
            ->assertOk()
            ->assertJsonPath('data.evaluacion.documentos_actualizados', ['doc-'.$hoja]);
    }

    public function test_aspirante_envia_la_correccion_solo_cuando_reemplaza_todos_los_marcados(): void
    {
        Storage::fake('local');

        $ficha = $this->ficha('2896412');
        $evaluador = $this->usuario(Role::Aprendiz, 'evaluador', ['ficha_id' => $ficha->id]);
        $aspirante = $this->usuario(Role::Aspirante, null, ['ficha_id' => $ficha->id]);
        $convocatoria = $this->convocatoria($ficha);
        $convocatoria->forceFill([
            'fecha_apertura' => now('America/Bogota')->subDay()->toDateString(),
            'fecha_cierre' => now('America/Bogota')->addDay()->toDateString(),
        ])->save();

        $hoja = $this->parametroId('tipo_documento', 'hoja_de_vida');
        $identidad = $this->parametroId('tipo_documento', 'documento_identidad');
        $convocatoria->documentos()->attach($hoja, ['obligatorio' => true, 'ponderacion' => 50]);
        $convocatoria->documentos()->attach($identidad, ['obligatorio' => true, 'ponderacion' => 50]);

        $postulacion = $this->postulacion($convocatoria, $aspirante);

        // El evaluador marca dos documentos con "Corregir".
        $this->actingAs($evaluador)->putJson("/api/evaluaciones/{$postulacion->id}", [
            'estado' => 'correccion_solicitada',
            'observaciones' => 'Subir de nuevo hoja de vida e identidad.',
            'criterios' => [['criterio' => 'Evaluación', 'puntaje' => 0, 'ponderacion' => 1]],
            'perfil' => ['doc-'.$hoja => false, 'doc-'.$identidad => false],
        ])->assertOk();

        // Sin reemplazar nada, el envío se rechaza y no guarda nada.
        $this->actingAs($aspirante)->postJson("/api/postulaciones/{$postulacion->id}/correccion/enviar")
            ->assertStatus(422)
            ->assertJsonPath('faltantes', collect([$hoja, $identidad])->sort()->values()->all());

        // Reemplaza solo uno: sigue faltando el otro.
        $this->actingAs($aspirante)->withHeaders(['Accept' => 'application/json'])
            ->post("/api/postulaciones/{$postulacion->id}/documentos", [
                'codigo' => 'hoja_de_vida',
                'archivo' => UploadedFile::fake()->create('hoja.pdf', 100, 'application/pdf'),
            ])->assertCreated();
        $this->actingAs($aspirante)->postJson("/api/postulaciones/{$postulacion->id}/correccion/enviar")
            ->assertStatus(422)
            ->assertJsonPath('faltantes', [$identidad]);

        // Reemplaza el segundo: el envío queda registrado.
        $this->actingAs($aspirante)->withHeaders(['Accept' => 'application/json'])
            ->post("/api/postulaciones/{$postulacion->id}/documentos", [
                'codigo' => 'documento_identidad',
                'archivo' => UploadedFile::fake()->create('identidad.pdf', 100, 'application/pdf'),
            ])->assertCreated();
        $this->actingAs($aspirante)->postJson("/api/postulaciones/{$postulacion->id}/correccion/enviar")
            ->assertOk()
            ->assertJsonStructure(['data' => ['correccion_enviada_at']]);

        // El evaluador y el aspirante ven la fecha del envío, y los reemplazos siguen marcados.
        $this->actingAs($evaluador)->getJson("/api/evaluaciones/{$postulacion->id}")
            ->assertOk()
            ->assertJsonCount(2, 'data.evaluacion.documentos_actualizados')->assertJsonFragment(['doc-'.$hoja])->assertJsonFragment(['doc-'.$identidad]);
        $this->actingAs($aspirante)->getJson('/api/mis-evaluaciones')
            ->assertOk()
            ->assertJsonPath('data.0.correccion_enviada_at', fn ($v) => $v !== null);

        // Otro usuario no puede enviar la corrección de esta postulación.
        $otro = $this->usuario(Role::Aspirante, null, ['ficha_id' => $ficha->id]);
        $this->actingAs($otro)->postJson("/api/postulaciones/{$postulacion->id}/correccion/enviar")
            ->assertNotFound();
    }

}
