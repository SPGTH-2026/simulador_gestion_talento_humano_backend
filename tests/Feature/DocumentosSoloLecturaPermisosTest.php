<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\PostulacionDocumento;
use App\Models\PostulacionSkill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\DocumentosSoloLecturaAyudas;
use Tests\TestCase;

class DocumentosSoloLecturaPermisosTest extends TestCase
{
    use RefreshDatabase;
    use DocumentosSoloLecturaAyudas;

    public function test_el_permiso_documentos_gestionar_ya_no_existe(): void
    {
        $this->assertNotContains('documentos:gestionar', $this->instructor($this->ficha())->permissions());
        $this->assertNotContains('documentos:gestionar', $this->usuario(Role::SuperAdmin)->permissions());
    }

    // ── Instructor y super admin: escritura rechazada ──────────────────

    public function test_el_instructor_no_puede_subir_ni_reemplazar_un_documento_del_aspirante(): void
    {
        [$aspirante, $postulacion, $instructor] = $this->base();
        $this->subirComoAspirante($aspirante, $postulacion);

        $this->assertRechazada($this->actingAs($instructor)
            ->withHeaders(['Accept' => 'application/json'])
            ->post("/api/postulaciones/{$postulacion->id}/documentos", [
                'codigo' => 'hoja_de_vida',
                'archivo' => $this->pdf('intruso.pdf'),
            ]));

        $this->assertRechazada($this->actingAs($instructor)
            ->withHeaders(['Accept' => 'application/json'])
            ->post("/api/revision/postulaciones/{$postulacion->id}/documentos", [
                'codigo' => 'hoja_de_vida',
                'archivo' => $this->pdf('intruso.pdf'),
            ]));

        $this->assertSame('hoja.pdf', PostulacionDocumento::sole()->nombre_original);
    }

    public function test_el_super_admin_no_puede_subir_ni_reemplazar_un_documento_del_aspirante(): void
    {
        [$aspirante, $postulacion] = $this->base();
        $this->subirComoAspirante($aspirante, $postulacion);
        $superAdmin = $this->usuario(Role::SuperAdmin);

        $this->assertRechazada($this->actingAs($superAdmin)
            ->withHeaders(['Accept' => 'application/json'])
            ->post("/api/postulaciones/{$postulacion->id}/documentos", [
                'codigo' => 'hoja_de_vida',
                'archivo' => $this->pdf('admin.pdf'),
            ]));

        $this->assertRechazada($this->actingAs($superAdmin)
            ->withHeaders(['Accept' => 'application/json'])
            ->post("/api/revision/postulaciones/{$postulacion->id}/documentos", [
                'codigo' => 'hoja_de_vida',
                'archivo' => $this->pdf('admin.pdf'),
            ]));

        $this->assertSame('hoja.pdf', PostulacionDocumento::sole()->nombre_original);
    }

    public function test_la_ruta_de_documentos_adicionales_ya_no_existe(): void
    {
        [$aspirante, $postulacion] = $this->base();

        $this->actingAs($aspirante)
            ->withHeaders(['Accept' => 'application/json'])
            ->post("/api/postulaciones/{$postulacion->id}/documentos/adicionales", [
                'titulo' => 'Extra',
                'archivo' => $this->pdf(),
            ])
            ->assertNotFound();

        $this->assertSame(0, PostulacionDocumento::count());
    }

    public function test_el_instructor_no_puede_eliminar_un_documento_del_aspirante(): void
    {
        [$aspirante, $postulacion, $instructor] = $this->base();
        $documento = $this->subirComoAspirante($aspirante, $postulacion);
        $ruta = $documento->ruta;

        $this->assertRechazada($this->actingAs($instructor)
            ->deleteJson("/api/revision/postulaciones/{$postulacion->id}/documentos/{$documento->id}"));

        $this->assertRechazada($this->actingAs($instructor)
            ->deleteJson("/api/postulaciones/{$postulacion->id}/documentos/{$documento->id}"));

        $this->assertSame(1, PostulacionDocumento::count());
        Storage::disk('local')->assertExists($ruta);
    }

    public function test_el_super_admin_no_puede_eliminar_un_documento_del_aspirante(): void
    {
        [$aspirante, $postulacion] = $this->base();
        $documento = $this->subirComoAspirante($aspirante, $postulacion);
        $superAdmin = $this->usuario(Role::SuperAdmin);

        $this->assertRechazada($this->actingAs($superAdmin)
            ->deleteJson("/api/revision/postulaciones/{$postulacion->id}/documentos/{$documento->id}"));

        $this->assertSame(1, PostulacionDocumento::count());
    }

    public function test_el_instructor_no_puede_tocar_los_certificados_del_aspirante(): void
    {
        [, $postulacion, $instructor] = $this->base();
        $skill = $this->skill($postulacion);

        $this->assertRechazada($this->actingAs($instructor)
            ->withHeaders(['Accept' => 'application/json'])
            ->post("/api/revision/postulaciones/{$postulacion->id}/skills/{$skill->id}/certificado", [
                'archivo' => $this->pdf('certificado.pdf'),
            ]));

        $this->assertRechazada($this->actingAs($instructor)
            ->withHeaders(['Accept' => 'application/json'])
            ->post("/api/postulaciones/{$postulacion->id}/skills/{$skill->id}/certificado", [
                'archivo' => $this->pdf('certificado.pdf'),
            ]));

        $this->assertRechazada($this->actingAs($instructor)
            ->deleteJson("/api/revision/postulaciones/{$postulacion->id}/skills/{$skill->id}/certificado"));

        $this->assertNull($skill->fresh()->certificado_ruta);
    }

    public function test_el_super_admin_no_puede_tocar_los_certificados_del_aspirante(): void
    {
        [, $postulacion] = $this->base();
        $skill = $this->skill($postulacion);
        $superAdmin = $this->usuario(Role::SuperAdmin);

        $this->assertRechazada($this->actingAs($superAdmin)
            ->withHeaders(['Accept' => 'application/json'])
            ->post("/api/revision/postulaciones/{$postulacion->id}/skills/{$skill->id}/certificado", [
                'archivo' => $this->pdf('certificado.pdf'),
            ]));

        $this->assertNull($skill->fresh()->certificado_ruta);
    }

    public function test_el_instructor_no_puede_editar_skills_ni_estado_de_la_postulacion_del_aspirante(): void
    {
        [, $postulacion, $instructor] = $this->base();
        // Una postulación enviada: es la que el instructor intenta cambiar sin éxito.
        $postulacion->update(['estado_id' => $this->parametroId('estado_postulacion', 'postulada')]);

        $this->assertRechazada($this->actingAs($instructor)
            ->withHeaders(['Accept' => 'application/json'])
            ->postJson("/api/postulaciones/{$postulacion->id}/skills", ['nombre' => 'PHP']));

        $this->assertRechazada($this->actingAs($instructor)
            ->withHeaders(['Accept' => 'application/json'])
            ->patchJson("/api/postulaciones/{$postulacion->id}/estado", ['estado' => 'retirada']));

        $this->assertRechazada($this->actingAs($instructor)
            ->withHeaders(['Accept' => 'application/json'])
            ->postJson("/api/postulaciones/{$postulacion->id}/enviar"));

        $this->assertSame('postulada', $postulacion->fresh()->estado->codigo);
        $this->assertSame(0, PostulacionSkill::count());
    }

    // ── Instructor y super admin: lectura y descarga sí permitidas ────

}
