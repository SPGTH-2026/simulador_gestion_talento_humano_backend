<?php

namespace Tests\Feature;

use App\Models\PostulacionDocumento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Concerns\PostulacionDocumentoAyudas;
use Tests\TestCase;

/**
 * Si la escritura en base de datos falla DESPUÉS de guardar el archivo, el archivo nuevo se borra: no debe quedar
 * huérfano en el disco.
 */
class PostulacionDocumentoLimpiezaTest extends TestCase
{
    use RefreshDatabase;
    use PostulacionDocumentoAyudas;

    protected function tearDown(): void
    {
        PostulacionDocumento::flushEventListeners();

        parent::tearDown();
    }

    public function test_si_falla_al_crear_un_requerido_no_queda_el_archivo_en_el_disco(): void
    {
        Storage::fake('local');
        [$aspirante, $postulacion] = $this->baseConDocumentos(['hoja_de_vida' => true]);
        PostulacionDocumento::creating(fn () => throw new RuntimeException('falla simulada'));

        $this->withoutExceptionHandling();
        $this->expectException(RuntimeException::class);

        try {
            $this->actingAs($aspirante)->withHeaders(['Accept' => 'application/json'])
                ->post("/api/postulaciones/{$postulacion->id}/documentos", [
                    'codigo' => 'hoja_de_vida',
                    'archivo' => $this->pdf('mi-hoja.pdf'),
                ]);
        } finally {
            $this->assertSame([], Storage::disk('local')->allFiles());
        }
    }
}
