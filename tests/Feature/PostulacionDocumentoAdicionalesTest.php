<?php

namespace Tests\Feature;

use App\Models\PostulacionDocumento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\PostulacionDocumentoAyudas;
use Tests\TestCase;

class PostulacionDocumentoAdicionalesTest extends TestCase
{
    use RefreshDatabase;
    use PostulacionDocumentoAyudas;

    public function test_sube_un_documento_adicional_devuelve_201(): void
    {
        [$aspirante, $postulacion] = $this->baseConDocumentos([]);

        $this->actingAs($aspirante)->withHeaders(['Accept' => 'application/json'])
            ->post("/api/postulaciones/{$postulacion->id}/documentos/adicionales", [
                'titulo' => 'Certificado de inglés',
                'nota' => 'Nivel B2',
                'archivo' => $this->pdf('ingles.pdf'),
            ])
            ->assertCreated()
            ->assertJsonPath('data.titulo', 'Certificado de inglés')
            ->assertJsonPath('data.nota', 'Nivel B2');

        $documento = PostulacionDocumento::sole();
        $this->assertNull($documento->parametro_id);
    }

    public function test_adicional_sin_titulo_devuelve_422(): void
    {
        [$aspirante, $postulacion] = $this->baseConDocumentos([]);

        $this->actingAs($aspirante)->withHeaders(['Accept' => 'application/json'])
            ->post("/api/postulaciones/{$postulacion->id}/documentos/adicionales", [
                'archivo' => $this->pdf(),
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.titulo.0', 'El título del documento adicional es obligatorio.');
    }

    public function test_tope_de_5_adicionales_devuelve_422(): void
    {
        [$aspirante, $postulacion] = $this->baseConDocumentos([]);
        $url = "/api/postulaciones/{$postulacion->id}/documentos/adicionales";

        for ($i = 1; $i <= 5; $i++) {
            $this->actingAs($aspirante)->withHeaders(['Accept' => 'application/json'])
                ->post($url, ['titulo' => "Adicional {$i}", 'archivo' => $this->pdf("a{$i}.pdf")])
                ->assertCreated();
        }

        $this->actingAs($aspirante)->withHeaders(['Accept' => 'application/json'])
            ->post($url, ['titulo' => 'Adicional 6', 'archivo' => $this->pdf('a6.pdf')])
            ->assertStatus(422)
            ->assertJsonPath('errors.titulo.0', 'No puedes agregar más de 5 documentos adicionales.');

        $this->assertDatabaseCount('postulacion_documentos', 5);
    }

    // ── Borrar y descargar ─────────────────────────────────────────────

    public function test_borra_un_documento_opcional_y_su_archivo(): void
    {
        [$aspirante, $postulacion] = $this->baseConDocumentos(['tarjeta_profesional' => false]);

        $this->actingAs($aspirante)->withHeaders(['Accept' => 'application/json'])
            ->post("/api/postulaciones/{$postulacion->id}/documentos", [
                'codigo' => 'tarjeta_profesional', 'archivo' => $this->pdf(),
            ])->assertCreated();

        $documento = PostulacionDocumento::sole();

        $this->actingAs($aspirante)
            ->deleteJson("/api/postulaciones/{$postulacion->id}/documentos/{$documento->id}")
            ->assertNoContent();

        Storage::disk('local')->assertMissing($documento->ruta);
        $this->assertDatabaseCount('postulacion_documentos', 0);
    }

    public function test_borra_un_documento_adicional_y_su_archivo(): void
    {
        [$aspirante, $postulacion] = $this->baseConDocumentos(['hoja_de_vida' => true]);

        $this->actingAs($aspirante)->withHeaders(['Accept' => 'application/json'])
            ->post("/api/postulaciones/{$postulacion->id}/documentos/adicionales", [
                'titulo' => 'Diploma', 'archivo' => $this->pdf('diploma.pdf'),
            ])->assertCreated();

        $documento = PostulacionDocumento::sole();

        $this->actingAs($aspirante)
            ->deleteJson("/api/postulaciones/{$postulacion->id}/documentos/{$documento->id}")
            ->assertNoContent();

        Storage::disk('local')->assertMissing($documento->ruta);
        $this->assertDatabaseCount('postulacion_documentos', 0);
    }

    /** Regla acordada el 4-nov (RF-DOC-006): un obligatorio cargado no se elimina, solo se reemplaza; en cualquier estado editable. */
    #[\PHPUnit\Framework\Attributes\DataProvider('estadosEditables')]
    public function test_un_documento_obligatorio_cargado_no_se_elimina_solo_se_reemplaza(string $estado): void
    {
        $ficha = $this->ficha();
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->conDocumentos($this->convocatoriaAbierta($ficha, $this->instructor($ficha)), ['hoja_de_vida' => true]);
        $postulacion = $this->crearPostulacion($convocatoria, $aspirante, ['estado_id' => $this->parametroId('estado_postulacion', $estado)]);

        $this->actingAs($aspirante)->withHeaders(['Accept' => 'application/json'])
            ->post("/api/postulaciones/{$postulacion->id}/documentos", ['codigo' => 'hoja_de_vida', 'archivo' => $this->pdf('v1.pdf')])
            ->assertCreated();
        $documento = PostulacionDocumento::sole();

        $this->actingAs($aspirante)
            ->deleteJson("/api/postulaciones/{$postulacion->id}/documentos/{$documento->id}")
            ->assertStatus(422)
            ->assertJsonPath('errors.documento.0', 'Este documento es obligatorio y no se puede eliminar: reemplázalo por otro archivo.');

        // El documento y su archivo siguen ahí.
        $this->assertDatabaseCount('postulacion_documentos', 1);
        Storage::disk('local')->assertExists($documento->ruta);

        // Y sí se puede reemplazar (200) mientras la postulación sea editable; queda un solo registro.
        $this->actingAs($aspirante)->withHeaders(['Accept' => 'application/json'])
            ->post("/api/postulaciones/{$postulacion->id}/documentos", ['codigo' => 'hoja_de_vida', 'archivo' => $this->pdf('v2.pdf')])
            ->assertOk();
        $this->assertDatabaseCount('postulacion_documentos', 1);
        $this->assertSame('v2.pdf', PostulacionDocumento::sole()->nombre_original);
    }

    public function test_la_regla_de_no_eliminar_obligatorios_no_cambia_la_edicion_ni_la_pertenencia(): void
    {
        $ficha = $this->ficha();
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->conDocumentos($this->convocatoriaAbierta($ficha, $this->instructor($ficha)), ['hoja_de_vida' => true]);
        $postulacion = $this->crearPostulacion($convocatoria, $aspirante);
        $this->actingAs($aspirante)->withHeaders(['Accept' => 'application/json'])
            ->post("/api/postulaciones/{$postulacion->id}/documentos", ['codigo' => 'hoja_de_vida', 'archivo' => $this->pdf()])->assertCreated();
        $documento = PostulacionDocumento::sole();

        // Una postulación ajena sigue respondiendo 404 (antes que la regla del obligatorio).
        $this->actingAs($this->aspirante($ficha))
            ->deleteJson("/api/postulaciones/{$postulacion->id}/documentos/{$documento->id}")
            ->assertNotFound();

        // Con la convocatoria que ya no está publicada, la edición manda: 404 como hasta ahora.
        $convocatoria->forceFill(['estado_id' => $this->parametroId('estado_convocatoria', 'en_proceso')])->save();
        $this->actingAs($aspirante)
            ->deleteJson("/api/postulaciones/{$postulacion->id}/documentos/{$documento->id}")
            ->assertNotFound();
        $this->assertDatabaseCount('postulacion_documentos', 1);
    }

}
