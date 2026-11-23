<?php

namespace Tests\Feature;

use App\Models\PostulacionDocumento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\PostulacionDocumentoAyudas;
use Tests\TestCase;

class PostulacionDocumentoDiscoCaidoTest extends TestCase
{
    use RefreshDatabase;
    use PostulacionDocumentoAyudas;

    /**
     * Si el disco de documentos no está disponible, la subida responde 422 con un mensaje en español y no deja fila.
     * Antes, la excepción de validación no estaba importada en el trait y el fallo salía como error 500.
     */
    public function test_si_el_disco_de_documentos_falla_responde_422_y_no_crea_la_fila(): void
    {
        [$aspirante, $postulacion] = $this->baseConDocumentos(['hoja_de_vida' => true]);
        config(['filesystems.documentos_disk' => 'disco_que_no_existe']);

        $this->actingAs($aspirante)
            ->withHeaders(['Accept' => 'application/json'])
            ->post("/api/postulaciones/{$postulacion->id}/documentos", [
                'codigo' => 'hoja_de_vida',
                'archivo' => $this->pdf('mi-hoja.pdf'),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('archivo');

        $this->assertSame(0, PostulacionDocumento::count());
    }
}
