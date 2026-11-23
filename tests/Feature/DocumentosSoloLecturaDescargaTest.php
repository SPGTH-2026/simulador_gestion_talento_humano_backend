<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\PostulacionDocumento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\DocumentosSoloLecturaAyudas;
use Tests\TestCase;

class DocumentosSoloLecturaDescargaTest extends TestCase
{
    use RefreshDatabase;
    use DocumentosSoloLecturaAyudas;

    public function test_el_instructor_de_la_ficha_ve_y_descarga_los_documentos(): void
    {
        [$aspirante, $postulacion, $instructor] = $this->base();
        $documento = $this->subirComoAspirante($aspirante, $postulacion);

        $this->actingAs($instructor)
            ->getJson("/api/revision/postulaciones/{$postulacion->id}/documentos")
            ->assertOk()
            ->assertJsonPath('data.documentos.0.archivo.nombre_original', 'hoja.pdf');

        $this->actingAs($instructor)
            ->get("/api/revision/postulaciones/{$postulacion->id}/documentos/{$documento->id}/archivo")
            ->assertOk();
    }

    public function test_el_super_admin_ve_y_descarga_los_documentos(): void
    {
        [$aspirante, $postulacion] = $this->base();
        $documento = $this->subirComoAspirante($aspirante, $postulacion);
        $superAdmin = $this->usuario(Role::SuperAdmin);

        $this->actingAs($superAdmin)
            ->getJson("/api/revision/postulaciones/{$postulacion->id}/documentos")
            ->assertOk();

        $this->actingAs($superAdmin)
            ->get("/api/revision/postulaciones/{$postulacion->id}/documentos/{$documento->id}/archivo")
            ->assertOk();
    }

    public function test_el_instructor_de_otra_ficha_no_ve_ni_descarga(): void
    {
        [$aspirante, $postulacion] = $this->base();
        $documento = $this->subirComoAspirante($aspirante, $postulacion);
        $otroInstructor = $this->instructor($this->ficha('9999999'));

        $this->actingAs($otroInstructor)
            ->getJson("/api/revision/postulaciones/{$postulacion->id}/documentos")
            ->assertNotFound();

        $this->actingAs($otroInstructor)
            ->get("/api/revision/postulaciones/{$postulacion->id}/documentos/{$documento->id}/archivo")
            ->assertNotFound();
    }

    // ── Aspirante dueño: escritura permitida ───────────────────────────

    public function test_el_aspirante_dueño_si_puede_subir_su_documento(): void
    {
        [$aspirante, $postulacion] = $this->base();

        $this->actingAs($aspirante)
            ->withHeaders(['Accept' => 'application/json'])
            ->post("/api/postulaciones/{$postulacion->id}/documentos", [
                'codigo' => 'hoja_de_vida',
                'archivo' => $this->pdf('mia.pdf'),
            ])
            ->assertCreated();

        $this->assertSame(1, PostulacionDocumento::count());
    }

}
