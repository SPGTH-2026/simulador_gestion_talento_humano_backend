<?php

namespace Tests\Feature;

use App\Models\Evaluacion;
use App\Models\PostulacionDocumento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\PostulacionDocumentoAyudas;
use Tests\TestCase;

class PostulacionDocumentoCorreccionTest extends TestCase
{
    use RefreshDatabase;
    use PostulacionDocumentoAyudas;

    public function test_durante_correccion_vigente_se_puede_reemplazar_aunque_evaluacion_ya_empezo(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->conDocumentos($this->crearConvocatoria($ficha, $instructor, [
            'estado_id' => $this->parametroId('estado_convocatoria', 'en_proceso'),
            'fecha_apertura' => now('America/Bogota')->subDays(10)->toDateString(),
            'fecha_cierre' => now('America/Bogota')->subDay()->toDateString(),
            'fecha_evaluacion' => now('America/Bogota')->subDay()->toDateString(),
        ]), ['hoja_de_vida' => true]);
        $postulacion = $this->crearPostulacion($convocatoria, $aspirante);

        Evaluacion::create([
            'postulacion_id' => $postulacion->id,
            'evaluador_id' => $instructor->id,
            'estado' => 'correccion_solicitada',
            'observaciones' => 'Subir de nuevo la hoja de vida.',
            'perfil' => ['doc-'.$this->parametroId('tipo_documento', 'hoja_de_vida') => false],
            'fecha_limite_correccion' => now()->addDays(Evaluacion::DIAS_LIMITE_CORRECCION),
        ]);

        $url = "/api/postulaciones/{$postulacion->id}/documentos";

        $this->actingAs($aspirante)
            ->getJson($url)
            ->assertOk()
            ->assertJsonPath('data.editable', true)
            ->assertJsonPath('data.motivo_edicion', null);

        $this->actingAs($aspirante)->withHeaders(['Accept' => 'application/json'])
            ->post($url, ['codigo' => 'hoja_de_vida', 'archivo' => $this->pdf()])
            ->assertCreated();
    }

    public function test_correccion_enviada_o_vencida_no_permite_reemplazar_documentos(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->conDocumentos($this->crearConvocatoria($ficha, $instructor, [
            'estado_id' => $this->parametroId('estado_convocatoria', 'en_proceso'),
            'fecha_apertura' => now('America/Bogota')->subDays(10)->toDateString(),
            'fecha_cierre' => now('America/Bogota')->subDay()->toDateString(),
            'fecha_evaluacion' => now('America/Bogota')->subDay()->toDateString(),
        ]), ['hoja_de_vida' => true]);
        $postulacion = $this->crearPostulacion($convocatoria, $aspirante);

        $url = "/api/postulaciones/{$postulacion->id}/documentos";

        // Corrección ya enviada por el aspirante: ya no se reemplaza nada.
        Evaluacion::create([
            'postulacion_id' => $postulacion->id,
            'evaluador_id' => $instructor->id,
            'estado' => 'correccion_solicitada',
            'observaciones' => 'Subir de nuevo la hoja de vida.',
            'fecha_limite_correccion' => now()->addDays(Evaluacion::DIAS_LIMITE_CORRECCION),
            'correccion_enviada_at' => now(),
        ]);

        // Con la convocatoria en proceso, sin corrección vigente, la edición queda
        // cerrada (404 histórico de exigirEditable).
        $this->actingAs($aspirante)->withHeaders(['Accept' => 'application/json'])
            ->post($url, ['codigo' => 'hoja_de_vida', 'archivo' => $this->pdf()])
            ->assertNotFound();

        // Plazo vencido: tampoco.
        Evaluacion::query()->where('postulacion_id', $postulacion->id)->update([
            'correccion_enviada_at' => null,
            'fecha_limite_correccion' => now()->subDay(),
        ]);

        $this->actingAs($aspirante)->withHeaders(['Accept' => 'application/json'])
            ->post($url, ['codigo' => 'hoja_de_vida', 'archivo' => $this->pdf()])
            ->assertNotFound();
    }

    public function test_durante_correccion_solo_se_reemplazan_los_documentos_marcados(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->conDocumentos($this->crearConvocatoria($ficha, $instructor, [
            'estado_id' => $this->parametroId('estado_convocatoria', 'en_proceso'),
            'fecha_apertura' => now('America/Bogota')->subDays(10)->toDateString(),
            'fecha_cierre' => now('America/Bogota')->subDay()->toDateString(),
            'fecha_evaluacion' => now('America/Bogota')->subDay()->toDateString(),
        ]), ['hoja_de_vida' => true, 'documento_identidad' => true]);
        $postulacion = $this->crearPostulacion($convocatoria, $aspirante);

        $hoja = $this->parametroId('tipo_documento', 'hoja_de_vida');
        Evaluacion::create([
            'postulacion_id' => $postulacion->id,
            'evaluador_id' => $instructor->id,
            'estado' => 'correccion_solicitada',
            'observaciones' => 'Subir de nuevo la hoja de vida.',
            'perfil' => ['doc-'.$hoja => false],
            'fecha_limite_correccion' => now()->addDays(Evaluacion::DIAS_LIMITE_CORRECCION),
        ]);

        $url = "/api/postulaciones/{$postulacion->id}/documentos";

        // Un documento que el evaluador NO marcó: se rechaza.
        $this->actingAs($aspirante)->withHeaders(['Accept' => 'application/json'])
            ->post($url, ['codigo' => 'documento_identidad', 'archivo' => $this->pdf()])
            ->assertStatus(422)
            ->assertJsonPath('errors.codigo.0', 'Durante la corrección solo puedes reemplazar los documentos que el evaluador marcó.');

        // El documento marcado sí se puede subir.
        $this->actingAs($aspirante)->withHeaders(['Accept' => 'application/json'])
            ->post($url, ['codigo' => 'hoja_de_vida', 'archivo' => $this->pdf()])
            ->assertCreated();
    }

    public function test_durante_correccion_no_se_suben_adicionales_ni_se_borran_documentos(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->conDocumentos($this->crearConvocatoria($ficha, $instructor, [
            'estado_id' => $this->parametroId('estado_convocatoria', 'en_proceso'),
            'fecha_apertura' => now('America/Bogota')->subDays(10)->toDateString(),
            'fecha_cierre' => now('America/Bogota')->subDay()->toDateString(),
            'fecha_evaluacion' => now('America/Bogota')->subDay()->toDateString(),
        ]), ['hoja_de_vida' => true, 'documento_identidad' => false]);
        $postulacion = $this->crearPostulacion($convocatoria, $aspirante);

        $hoja = $this->parametroId('tipo_documento', 'hoja_de_vida');
        Evaluacion::create([
            'postulacion_id' => $postulacion->id,
            'evaluador_id' => $instructor->id,
            'estado' => 'correccion_solicitada',
            'observaciones' => 'Subir de nuevo la hoja de vida.',
            'perfil' => ['doc-'.$hoja => false],
            'fecha_limite_correccion' => now()->addDays(Evaluacion::DIAS_LIMITE_CORRECCION),
        ]);

        // Un documento opcional ya subido, que no fue marcado.
        $opcional = PostulacionDocumento::create([
            'postulacion_id' => $postulacion->id,
            'parametro_id' => $this->parametroId('tipo_documento', 'documento_identidad'),
            'nombre_original' => 'identidad.pdf',
            'ruta' => "postulaciones/{$postulacion->id}/identidad.pdf",
            'disco' => 'local',
            'mime' => 'application/pdf',
            'tamano_bytes' => 1024,
        ]);

        $this->actingAs($aspirante)->withHeaders(['Accept' => 'application/json'])
            ->post("/api/postulaciones/{$postulacion->id}/documentos/adicionales", [
                'titulo' => 'Certificado extra',
                'archivo' => $this->pdf(),
            ])
            ->assertStatus(422);

        $this->actingAs($aspirante)->withHeaders(['Accept' => 'application/json'])
            ->deleteJson("/api/postulaciones/{$postulacion->id}/documentos/{$opcional->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('postulacion_documentos', ['id' => $opcional->id]);
    }

}
