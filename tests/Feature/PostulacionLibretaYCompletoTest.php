<?php

namespace Tests\Feature;

use App\Models\PostulacionDocumento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\PostulacionDocumentoAyudas;
use Tests\TestCase;

class PostulacionLibretaYCompletoTest extends TestCase
{
    use RefreshDatabase;
    use PostulacionDocumentoAyudas;

    public function test_libreta_no_aplica_a_mujeres_y_bloquea_su_carga(): void
    {
        [$aspirante, $postulacion] = $this->baseConDocumentos(['libreta_militar' => true]);
        $this->actingAs($aspirante)->putJson('/api/perfil/sexo', ['sexo' => 'mujer'])->assertOk();

        $respuesta = $this->actingAs($aspirante)
            ->getJson("/api/postulaciones/{$postulacion->id}/documentos")
            ->assertOk();

        // El sexo ya está registrado: no hay que volver a pedirlo, y la libreta
        // (único obligatorio) queda en no_aplica, así que el paso está completo.
        $respuesta->assertJsonPath('data.requiere_sexo', false);
        $respuesta->assertJsonPath('data.sexo.codigo', 'mujer');
        $respuesta->assertJsonPath('data.completo', true);

        $documentos = collect($respuesta->json('data.documentos'))
            ->keyBy(fn (array $d) => $d['parametro']['codigo']);
        $this->assertSame('no_aplica', $documentos['libreta_militar']['estado']);

        $this->actingAs($aspirante)->withHeaders(['Accept' => 'application/json'])
            ->post("/api/postulaciones/{$postulacion->id}/documentos", [
                'codigo' => 'libreta_militar', 'archivo' => $this->pdf(),
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.codigo.0', 'La libreta militar no aplica: tu perfil está registrado como femenino.');
    }

    public function test_libreta_exige_sexo_registrado(): void
    {
        [$aspirante, $postulacion] = $this->baseConDocumentos(['libreta_militar' => true]);

        $this->actingAs($aspirante)->withHeaders(['Accept' => 'application/json'])
            ->post("/api/postulaciones/{$postulacion->id}/documentos", [
                'codigo' => 'libreta_militar', 'archivo' => $this->pdf(),
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.codigo.0', 'Antes de subir la libreta militar debes llenar tus datos personales.');
    }

    public function test_hombre_con_sexo_registrado_puede_subir_la_libreta(): void
    {
        [$aspirante, $postulacion] = $this->baseConDocumentos(['libreta_militar' => true]);
        $this->actingAs($aspirante)->putJson('/api/perfil/sexo', ['sexo' => 'hombre'])->assertOk();

        $this->actingAs($aspirante)->withHeaders(['Accept' => 'application/json'])
            ->post("/api/postulaciones/{$postulacion->id}/documentos", [
                'codigo' => 'libreta_militar', 'archivo' => $this->pdf('libreta.pdf'),
            ])
            ->assertCreated();
    }

    public function test_estado_sin_sexo_pide_sexo_y_deja_la_libreta_pendiente(): void
    {
        [$aspirante, $postulacion] = $this->baseConDocumentos(['libreta_militar' => true]);

        $respuesta = $this->actingAs($aspirante)
            ->getJson("/api/postulaciones/{$postulacion->id}/documentos")
            ->assertOk();

        $respuesta->assertJsonPath('data.requiere_sexo', true);
        $respuesta->assertJsonPath('data.sexo', null);
        $respuesta->assertJsonPath('data.completo', false);

        $documentos = collect($respuesta->json('data.documentos'))
            ->keyBy(fn (array $d) => $d['parametro']['codigo']);
        $this->assertSame('pendiente', $documentos['libreta_militar']['estado']);
    }

    public function test_cambiar_el_sexo_no_borra_la_libreta_y_la_deja_no_aplica(): void
    {
        [$aspirante, $postulacion] = $this->baseConDocumentos(['libreta_militar' => true]);
        $this->actingAs($aspirante)->putJson('/api/perfil/sexo', ['sexo' => 'hombre'])->assertOk();

        $this->actingAs($aspirante)->withHeaders(['Accept' => 'application/json'])
            ->post("/api/postulaciones/{$postulacion->id}/documentos", [
                'codigo' => 'libreta_militar', 'archivo' => $this->pdf('libreta.pdf'),
            ])->assertCreated();

        $documento = PostulacionDocumento::sole();
        Storage::disk('local')->assertExists($documento->ruta);

        // El aspirante corrige su sexo a mujer: la fila y su archivo se conservan,
        // pero la libreta pasa a no_aplica (ya no se exige).
        $this->actingAs($aspirante)->putJson('/api/perfil/sexo', ['sexo' => 'mujer'])->assertOk();

        $respuesta = $this->actingAs($aspirante)
            ->getJson("/api/postulaciones/{$postulacion->id}/documentos")
            ->assertOk();

        $respuesta->assertJsonPath('data.sexo.codigo', 'mujer');
        $respuesta->assertJsonPath('data.completo', true);

        $documentos = collect($respuesta->json('data.documentos'))
            ->keyBy(fn (array $d) => $d['parametro']['codigo']);
        $this->assertSame('no_aplica', $documentos['libreta_militar']['estado']);
        $this->assertNotNull($documentos['libreta_militar']['archivo']);

        $this->assertDatabaseCount('postulacion_documentos', 1);
        Storage::disk('local')->assertExists($documento->ruta);
    }

    // ── Completitud ────────────────────────────────────────────────────

    public function test_completo_cuando_todos_los_obligatorios_estan_subidos(): void
    {
        [$aspirante, $postulacion] = $this->baseConDocumentos([
            'hoja_de_vida' => true,
            'documento_identidad' => true,
            'tarjeta_profesional' => false,
        ]);
        $url = "/api/postulaciones/{$postulacion->id}/documentos";

        $this->actingAs($aspirante)->getJson($url)->assertJsonPath('data.completo', false);

        foreach (['hoja_de_vida', 'documento_identidad'] as $codigo) {
            $this->actingAs($aspirante)->withHeaders(['Accept' => 'application/json'])
                ->post($url, ['codigo' => $codigo, 'archivo' => $this->pdf("{$codigo}.pdf")])
                ->assertCreated();
        }

        // El opcional no obligatorio ('tarjeta_profesional') sigue pendiente y no
        // impide que quede completo.
        $this->actingAs($aspirante)->getJson($url)->assertJsonPath('data.completo', true);
    }

    public function test_descargar_un_archivo_ausente_devuelve_404(): void
    {
        [$aspirante, $postulacion] = $this->baseConDocumentos(['hoja_de_vida' => true]);

        $this->actingAs($aspirante)->withHeaders(['Accept' => 'application/json'])
            ->post("/api/postulaciones/{$postulacion->id}/documentos", [
                'codigo' => 'hoja_de_vida', 'archivo' => $this->pdf('hoja.pdf'),
            ])->assertCreated();

        $documento = PostulacionDocumento::sole();
        Storage::disk('local')->delete($documento->ruta);

        $this->actingAs($aspirante)
            ->getJson("/api/postulaciones/{$postulacion->id}/documentos/{$documento->id}/archivo")
            ->assertNotFound()
            ->assertJsonPath('message', 'Documento no encontrado.');
    }

    // ── Contrato para el frontend ──────────────────────────────────────

}
