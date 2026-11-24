<?php

namespace Tests\Feature;

use App\Models\Parametro;
use App\Models\PostulacionDocumento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\PostulacionDocumentoAyudas;
use Tests\TestCase;

class PostulacionDocumentoSubidaTest extends TestCase
{
    use RefreshDatabase;
    use PostulacionDocumentoAyudas;

    public function test_dueno_ve_el_estado_con_documentos_pendientes(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->conDocumentos($this->convocatoriaAbierta($ficha, $instructor), [
            'hoja_de_vida' => true,
            'certificados_academicos' => false,
        ]);
        $postulacion = $this->crearPostulacion($convocatoria, $aspirante);

        $respuesta = $this->actingAs($aspirante)
            ->getJson("/api/postulaciones/{$postulacion->id}/documentos")
            ->assertOk();

        $respuesta->assertJsonPath('data.editable', true);
        $respuesta->assertJsonPath('data.completo', false);
        $respuesta->assertJsonPath('data.requiere_sexo', false);
        $respuesta->assertJsonPath('data.adicionales', []);
        $respuesta->assertJsonPath('data.skills', []);

        $documentos = collect($respuesta->json('data.documentos'))
            ->keyBy(fn (array $d) => $d['parametro']['codigo']);

        $this->assertSame('pendiente', $documentos['hoja_de_vida']['estado']);
        $this->assertTrue($documentos['hoja_de_vida']['obligatorio']);
        $this->assertSame('pendiente', $documentos['certificados_academicos']['estado']);
        $this->assertFalse($documentos['certificados_academicos']['obligatorio']);
    }

    public function test_postulacion_ajena_devuelve_404(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspiranteA = $this->aspirante($ficha);
        $aspiranteB = $this->aspirante($ficha);
        $convocatoria = $this->convocatoriaAbierta($ficha, $instructor);
        $postulacionB = $this->crearPostulacion($convocatoria, $aspiranteB);

        $this->actingAs($aspiranteA)
            ->getJson("/api/postulaciones/{$postulacionB->id}/documentos")
            ->assertNotFound()
            ->assertJsonPath('message', 'Postulación no encontrada.');
    }

    public function test_postulacion_inexistente_devuelve_404(): void
    {
        $aspirante = $this->aspirante($this->ficha());

        $this->actingAs($aspirante)
            ->getJson('/api/postulaciones/999999/documentos')
            ->assertNotFound()
            ->assertJsonPath('message', 'Postulación no encontrada.');
    }

    // ── Subir / reemplazar ─────────────────────────────────────────────

    public function test_sube_un_documento_pdf_devuelve_201(): void
    {
        [$aspirante, $postulacion] = $this->baseConDocumentos(['hoja_de_vida' => true]);

        $this->actingAs($aspirante)
            ->withHeaders(['Accept' => 'application/json'])
            ->post("/api/postulaciones/{$postulacion->id}/documentos", [
                'codigo' => 'hoja_de_vida',
                'archivo' => $this->pdf('mi-hoja.pdf'),
            ])
            ->assertCreated();

        $documento = PostulacionDocumento::sole();
        $this->assertSame('mi-hoja.pdf', $documento->nombre_original);
        $this->assertSame('local', $documento->disco);
        $this->assertSame('application/pdf', $documento->mime);
        Storage::disk('local')->assertExists($documento->ruta);
    }

    public function test_el_archivo_se_guarda_bajo_ficha_usuario_y_postulacion(): void
    {
        [$aspirante, $postulacion] = $this->baseConDocumentos(['hoja_de_vida' => true]);

        $this->actingAs($aspirante)
            ->withHeaders(['Accept' => 'application/json'])
            ->post("/api/postulaciones/{$postulacion->id}/documentos", [
                'codigo' => 'hoja_de_vida',
                'archivo' => $this->pdf('mi-hoja.pdf'),
            ])
            ->assertCreated();

        $fichaId = $postulacion->convocatoria->ficha->codigo;
        $esperado = "fichas/{$fichaId}/usuarios/{$aspirante->id}/postulaciones/{$postulacion->id}/documentos/";

        $this->assertStringStartsWith($esperado, PostulacionDocumento::sole()->ruta);
    }

    public function test_sube_un_documento_nuevo_sin_codigo_por_parametro_id(): void
    {
        [$aspirante, $postulacion] = $this->baseConDocumentos(['hoja_de_vida' => true]);

        // Documento NUEVO del catálogo (sin código), como una especialización.
        $temaId = (int) Parametro::porTemaYCodigo('tipo_documento', 'hoja_de_vida')->tema_id;
        $nuevo = Parametro::create([
            'tema_id' => $temaId,
            'codigo' => null,
            'nombre' => 'Especialización',
            'orden' => 99,
            'activo' => true,
        ]);

        $postulacion->convocatoria->documentos()->attach($nuevo->id, [
            'obligatorio' => true,
            'ponderacion' => 100,
        ]);

        $this->actingAs($aspirante)
            ->withHeaders(['Accept' => 'application/json'])
            ->post("/api/postulaciones/{$postulacion->id}/documentos", [
                'parametro_id' => $nuevo->id,
                'archivo' => $this->pdf('especializacion.pdf'),
            ])
            ->assertCreated();

        $documento = PostulacionDocumento::sole();
        $this->assertSame($nuevo->id, (int) $documento->parametro_id);
        $this->assertSame('especializacion.pdf', $documento->nombre_original);
        Storage::disk('local')->assertExists($documento->ruta);
    }

    public function test_parametro_id_que_no_es_de_la_convocatoria_devuelve_422(): void
    {
        [$aspirante, $postulacion] = $this->baseConDocumentos(['hoja_de_vida' => true]);

        $ajeno = Parametro::porTemaYCodigo('tipo_documento', 'tarjeta_profesional');

        $this->actingAs($aspirante)->withHeaders(['Accept' => 'application/json'])
            ->post("/api/postulaciones/{$postulacion->id}/documentos", [
                'parametro_id' => $ajeno->id,
                'archivo' => $this->pdf(),
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.parametro_id.0', 'Ese documento no hace parte de esta convocatoria.');

        $this->assertDatabaseCount('postulacion_documentos', 0);
    }

    public function test_reemplazar_el_mismo_tipo_actualiza_la_fila_y_borra_el_archivo_viejo(): void
    {
        [$aspirante, $postulacion] = $this->baseConDocumentos(['hoja_de_vida' => true]);
        $url = "/api/postulaciones/{$postulacion->id}/documentos";

        $this->actingAs($aspirante)->withHeaders(['Accept' => 'application/json'])
            ->post($url, ['codigo' => 'hoja_de_vida', 'archivo' => $this->pdf('v1.pdf')])
            ->assertCreated();

        $rutaVieja = PostulacionDocumento::sole()->ruta;

        $this->actingAs($aspirante)->withHeaders(['Accept' => 'application/json'])
            ->post($url, ['codigo' => 'hoja_de_vida', 'archivo' => $this->pdf('v2.pdf')])
            ->assertOk();

        $this->assertSame(1, PostulacionDocumento::count());
        $this->assertSame('v2.pdf', PostulacionDocumento::sole()->nombre_original);
        Storage::disk('local')->assertMissing($rutaVieja);
    }

    public function test_documento_que_no_es_de_la_convocatoria_devuelve_422(): void
    {
        [$aspirante, $postulacion] = $this->baseConDocumentos(['hoja_de_vida' => true]);

        $this->actingAs($aspirante)->withHeaders(['Accept' => 'application/json'])
            ->post("/api/postulaciones/{$postulacion->id}/documentos", [
                'codigo' => 'tarjeta_profesional',
                'archivo' => $this->pdf(),
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.codigo.0', 'Ese documento no hace parte de esta convocatoria.');

        $this->assertDatabaseCount('postulacion_documentos', 0);
    }

    public function test_archivo_no_pdf_devuelve_422(): void
    {
        [$aspirante, $postulacion] = $this->baseConDocumentos(['hoja_de_vida' => true]);

        $this->actingAs($aspirante)->withHeaders(['Accept' => 'application/json'])
            ->post("/api/postulaciones/{$postulacion->id}/documentos", [
                'codigo' => 'hoja_de_vida',
                'archivo' => UploadedFile::fake()->create('notas.txt', 10, 'text/plain'),
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.archivo.0', 'El archivo debe ser un PDF.');
    }

    public function test_archivo_mayor_a_5_mb_devuelve_422(): void
    {
        [$aspirante, $postulacion] = $this->baseConDocumentos(['hoja_de_vida' => true]);

        $this->actingAs($aspirante)->withHeaders(['Accept' => 'application/json'])
            ->post("/api/postulaciones/{$postulacion->id}/documentos", [
                'codigo' => 'hoja_de_vida',
                'archivo' => UploadedFile::fake()->create('grande.pdf', 6000, 'application/pdf'),
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.archivo.0', 'El archivo no debe superar los 3 MB.');
    }

    public function test_sin_archivo_devuelve_422(): void
    {
        [$aspirante, $postulacion] = $this->baseConDocumentos(['hoja_de_vida' => true]);

        $this->actingAs($aspirante)->withHeaders(['Accept' => 'application/json'])
            ->post("/api/postulaciones/{$postulacion->id}/documentos", ['codigo' => 'hoja_de_vida'])
            ->assertStatus(422)
            ->assertJsonPath('errors.archivo.0', 'El archivo es obligatorio.');
    }

}
