<?php

namespace Tests\Feature;

use App\Models\Postulacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\PostulacionDocumentoAyudas;
use Tests\TestCase;

class PostulacionDocumentoVentanasTest extends TestCase
{
    use RefreshDatabase;
    use PostulacionDocumentoAyudas;

    public function test_postulada_editable_despues_del_cierre_si_no_empezo_evaluacion(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->conDocumentos($this->crearConvocatoria($ficha, $instructor, [
            'estado_id' => $this->parametroId('estado_convocatoria', 'publicada'),
            'fecha_apertura' => now('America/Bogota')->subDays(10)->toDateString(),
            'fecha_cierre' => now('America/Bogota')->subDay()->toDateString(),
            // fecha_evaluacion null = no ha empezado evaluación
        ]), ['hoja_de_vida' => true]);
        $postulacion = $this->crearPostulacion($convocatoria, $aspirante);

        $url = "/api/postulaciones/{$postulacion->id}/documentos";

        $this->actingAs($aspirante)
            ->getJson($url)
            ->assertOk()
            ->assertJsonPath('data.editable', true)
            ->assertJsonPath('data.ventana', 'cerrada')
            ->assertJsonPath('data.motivo_edicion', null);

        $this->actingAs($aspirante)->withHeaders(['Accept' => 'application/json'])
            ->post($url, ['codigo' => 'hoja_de_vida', 'archivo' => $this->pdf()])
            ->assertCreated();
    }

    public function test_no_se_puede_subir_si_evaluacion_ya_empezo(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->conDocumentos($this->crearConvocatoria($ficha, $instructor, [
            'estado_id' => $this->parametroId('estado_convocatoria', 'publicada'),
            'fecha_apertura' => now('America/Bogota')->subDays(10)->toDateString(),
            'fecha_cierre' => now('America/Bogota')->subDay()->toDateString(),
            'fecha_evaluacion' => now('America/Bogota')->subDay()->toDateString(),
        ]), ['hoja_de_vida' => true]);
        $postulacion = $this->crearPostulacion($convocatoria, $aspirante);

        $url = "/api/postulaciones/{$postulacion->id}/documentos";

        $mensajeEsperado = 'La evaluación ya comenzó el '.$convocatoria->fecha_evaluacion->format('d/m/Y').'. Los documentos quedaron solo para consulta.';

        $this->actingAs($aspirante)
            ->getJson($url)
            ->assertOk()
            ->assertJsonPath('data.editable', false)
            ->assertJsonPath('data.motivo_edicion', $mensajeEsperado);

        $this->actingAs($aspirante)->withHeaders(['Accept' => 'application/json'])
            ->post($url, ['codigo' => 'hoja_de_vida', 'archivo' => $this->pdf()])
            ->assertStatus(422)
            ->assertJsonPath('errors.documentos.0', $mensajeEsperado);
    }

    public function test_no_se_puede_subir_con_convocatoria_en_proceso(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->conDocumentos($this->crearConvocatoria($ficha, $instructor, [
            'estado_id' => $this->parametroId('estado_convocatoria', 'en_proceso'),
            'fecha_apertura' => now('America/Bogota')->subDays(10)->toDateString(),
            'fecha_cierre' => now('America/Bogota')->subDay()->toDateString(),
        ]), ['hoja_de_vida' => true]);
        $postulacion = $this->crearPostulacion($convocatoria, $aspirante);

        $url = "/api/postulaciones/{$postulacion->id}/documentos";

        $this->actingAs($aspirante)->withHeaders(['Accept' => 'application/json'])
            ->post($url, ['codigo' => 'hoja_de_vida', 'archivo' => $this->pdf()])
            ->assertNotFound();
    }

    public function test_borrador_no_editable_despues_del_cierre(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->conDocumentos($this->crearConvocatoria($ficha, $instructor, [
            'estado_id' => $this->parametroId('estado_convocatoria', 'publicada'),
            'fecha_apertura' => now('America/Bogota')->subDays(10)->toDateString(),
            'fecha_cierre' => now('America/Bogota')->subDay()->toDateString(),
        ]), ['hoja_de_vida' => true]);
        // crearPostulacion por defecto crea postulada; creamos borrador manual
        $postulacion = Postulacion::create([
            'convocatoria_id' => $convocatoria->id,
            'user_id' => $aspirante->id,
            'estado_id' => $this->parametroId('estado_postulacion', 'borrador'),
            'fecha_postulacion' => now(),
        ]);

        $url = "/api/postulaciones/{$postulacion->id}/documentos";

        $this->actingAs($aspirante)
            ->getJson($url)
            ->assertOk()
            ->assertJsonPath('data.editable', false)
            ->assertJsonPath('data.motivo_edicion', 'La convocatoria ya cerró el '.$convocatoria->fecha_cierre->format('d/m/Y').'. Los borradores no se editan fuera de la ventana.');

        $this->actingAs($aspirante)->withHeaders(['Accept' => 'application/json'])
            ->post($url, ['codigo' => 'hoja_de_vida', 'archivo' => $this->pdf()])
            ->assertStatus(422);
    }

    public function test_no_se_puede_subir_antes_de_apertura(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->conDocumentos($this->crearConvocatoria($ficha, $instructor, [
            'estado_id' => $this->parametroId('estado_convocatoria', 'publicada'),
            'fecha_apertura' => now('America/Bogota')->addDays(5)->toDateString(),
            'fecha_cierre' => now('America/Bogota')->addDays(10)->toDateString(),
        ]), ['hoja_de_vida' => true]);
        $postulacion = $this->crearPostulacion($convocatoria, $aspirante);

        $url = "/api/postulaciones/{$postulacion->id}/documentos";

        $this->actingAs($aspirante)
            ->getJson($url)
            ->assertOk()
            ->assertJsonPath('data.editable', false)
            ->assertJsonPath('data.motivo_edicion', 'La convocatoria aún no está abierta. Abre el '.$convocatoria->fecha_apertura->format('d/m/Y').'.');

        $this->actingAs($aspirante)->withHeaders(['Accept' => 'application/json'])
            ->post($url, ['codigo' => 'hoja_de_vida', 'archivo' => $this->pdf()])
            ->assertStatus(422);
    }

    public function test_aspirante_sin_sesion_devuelve_401(): void
    {
        $this->postJson('/api/postulaciones/1/documentos')->assertUnauthorized();
    }

    // ── Adicionales ────────────────────────────────────────────────────

}
