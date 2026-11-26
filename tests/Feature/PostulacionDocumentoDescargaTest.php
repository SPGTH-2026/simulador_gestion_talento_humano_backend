<?php

namespace Tests\Feature;

use App\Models\PostulacionDocumento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\PostulacionDocumentoAyudas;
use Tests\TestCase;

class PostulacionDocumentoDescargaTest extends TestCase
{
    use RefreshDatabase;
    use PostulacionDocumentoAyudas;

    public function test_descarga_autorizada_del_dueno(): void
    {
        [$aspirante, $postulacion] = $this->baseConDocumentos(['hoja_de_vida' => true]);

        $this->actingAs($aspirante)->withHeaders(['Accept' => 'application/json'])
            ->post("/api/postulaciones/{$postulacion->id}/documentos", [
                'codigo' => 'hoja_de_vida', 'archivo' => $this->pdf('hoja.pdf'),
            ])->assertCreated();

        $documento = PostulacionDocumento::sole();

        $this->actingAs($aspirante)
            ->get("/api/postulaciones/{$postulacion->id}/documentos/{$documento->id}/archivo")
            ->assertOk();
    }

    // ── Visor PDF (?inline=1) ─────────────────────────────────────────

    public function test_el_dueno_lo_pide_en_linea_para_el_visor_y_sigue_descargandose_sin_inline(): void
    {
        [$aspirante, $postulacion] = $this->baseConDocumentos(['hoja_de_vida' => true]);

        $this->actingAs($aspirante)->withHeaders(['Accept' => 'application/json'])
            ->post("/api/postulaciones/{$postulacion->id}/documentos", [
                'codigo' => 'hoja_de_vida', 'archivo' => $this->pdf('hoja.pdf'),
            ])->assertCreated();

        $documento = PostulacionDocumento::sole();

        // El visor (pestaña del navegador) pide inline=1...
        $enLinea = $this->actingAs($aspirante)
            ->get("/api/postulaciones/{$postulacion->id}/documentos/{$documento->id}/archivo?inline=1")
            ->assertOk();
        $this->assertStringStartsWith('inline;', (string) $enLinea->headers->get('content-disposition'));

        // ...y sin el parámetro la descarga clásica no cambia.
        $descarga = $this->actingAs($aspirante)
            ->get("/api/postulaciones/{$postulacion->id}/documentos/{$documento->id}/archivo")
            ->assertOk();
        $this->assertStringStartsWith('attachment;', (string) $descarga->headers->get('content-disposition'));
    }

    public function test_el_revisor_tambien_puede_abrir_el_archivo_en_linea(): void
    {
        [$aspirante, $postulacion] = $this->baseConDocumentos(['hoja_de_vida' => true]);

        $this->actingAs($aspirante)->withHeaders(['Accept' => 'application/json'])
            ->post("/api/postulaciones/{$postulacion->id}/documentos", [
                'codigo' => 'hoja_de_vida', 'archivo' => $this->pdf(),
            ])->assertCreated();

        $documento = PostulacionDocumento::sole();
        $this->enviada($postulacion);
        $instructor = $this->instructor($aspirante->ficha);

        $enLinea = $this->actingAs($instructor)
            ->get("/api/revision/postulaciones/{$postulacion->id}/documentos/{$documento->id}/archivo?inline=1")
            ->assertOk();

        $this->assertStringStartsWith('inline;', (string) $enLinea->headers->get('content-disposition'));
    }

    public function test_no_puede_descargar_de_una_postulacion_ajena(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspiranteA = $this->aspirante($ficha);
        $aspiranteB = $this->aspirante($ficha);
        $convocatoria = $this->conDocumentos($this->convocatoriaAbierta($ficha, $instructor), ['hoja_de_vida' => true]);
        $postulacionB = $this->crearPostulacion($convocatoria, $aspiranteB);

        $this->actingAs($aspiranteB)->withHeaders(['Accept' => 'application/json'])
            ->post("/api/postulaciones/{$postulacionB->id}/documentos", [
                'codigo' => 'hoja_de_vida', 'archivo' => $this->pdf(),
            ])->assertCreated();

        $documento = PostulacionDocumento::sole();

        $this->actingAs($aspiranteA)
            ->get("/api/postulaciones/{$postulacionB->id}/documentos/{$documento->id}/archivo")
            ->assertNotFound();

        $this->actingAs($aspiranteA)
            ->deleteJson("/api/postulaciones/{$postulacionB->id}/documentos/{$documento->id}")
            ->assertNotFound();
    }

    // ── Regla de la libreta militar por sexo ───────────────────────────

}
