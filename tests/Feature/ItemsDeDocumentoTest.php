<?php

namespace Tests\Feature;

use App\Models\Convocatoria;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ConvocatoriaGestionAyudas;
use Tests\TestCase;

/** Cada documento de la convocatoria puede traer su propio checklist de ítems (uno por línea); si no lo escribe, el predeterminado. */
class ItemsDeDocumentoTest extends TestCase
{
    use RefreshDatabase;
    use ConvocatoriaGestionAyudas;

    public function test_los_items_de_cada_documento_se_guardan_y_se_devuelven_como_lista(): void
    {
        $instructor = $this->instructor($this->ficha());

        $respuesta = $this->actingAs($instructor)->postJson('/api/convocatorias', [
            'nombre' => 'Con items por documento',
            'documentos' => [
                [
                    'codigo' => 'hoja_de_vida',
                    'ponderacion' => 50,
                    'items' => "Datos personales completos\n\n  Firmada  \n",
                ],
                ['codigo' => 'documento_identidad', 'ponderacion' => 20],
                ['codigo' => 'certificados_academicos', 'ponderacion' => 20],
                ['codigo' => 'certificados_laborales', 'ponderacion' => 10],
            ],
        ])->assertCreated();

        $id = $respuesta->json('data.id');

        $documentos = collect($this->actingAs($instructor)->getJson("/api/convocatorias/{$id}")->json('data.documentos'))
            ->keyBy('codigo');

        // El texto se guarda recortado en los extremos; cada línea se limpia al mostrarla.
        $this->assertSame("Datos personales completos\n\n  Firmada", $documentos['hoja_de_vida']['items']);
        // Sin checklist escrito, el documento guarda el predeterminado (no queda sin ítems).
        $this->assertSame(config('convocatorias.checklist_por_defecto.documento_identidad'), $documentos['documento_identidad']['items']);
    }

    private function itemsPorCodigo(int $convocatoriaId): array
    {
        return Convocatoria::findOrFail($convocatoriaId)->documentos->mapWithKeys(fn ($d) => [$d->codigo => $d->pivot->items])->all();
    }

    public function test_sin_escribir_checklist_cada_documento_guarda_el_predeterminado(): void
    {
        $instructor = $this->instructor($this->ficha());

        $id = $this->actingAs($instructor)->postJson('/api/convocatorias', ['nombre' => 'Sin checklist escrito', 'documentos' => [['codigo' => 'hoja_de_vida'], ['codigo' => 'documento_identidad'], ['codigo' => 'certificados_academicos'], ['codigo' => 'certificados_laborales']]])->assertCreated()->json('data.id');

        $items = $this->itemsPorCodigo($id);
        foreach (['hoja_de_vida', 'documento_identidad', 'certificados_academicos', 'certificados_laborales'] as $codigo) {
            $this->assertSame(config("convocatorias.checklist_por_defecto.{$codigo}"), $items[$codigo], "«{$codigo}» debe llevar su checklist predeterminado.");
        }
        // «Certificados laborales» ya no depende de la convocatoria: tiene sus tres ítems.
        $this->assertCount(3, explode("\n", $items['certificados_laborales']));
    }

    public function test_el_checklist_que_escribe_el_gestor_se_respeta(): void
    {
        $instructor = $this->instructor($this->ficha());

        $id = $this->actingAs($instructor)->postJson('/api/convocatorias', [
            'nombre' => 'Con checklist propio',
            'documentos' => [['codigo' => 'certificados_laborales', 'items' => "Solo este punto\nY este otro"]],
        ])->assertCreated()->json('data.id');

        $this->assertSame("Solo este punto\nY este otro", $this->itemsPorCodigo($id)['certificados_laborales']);
    }

    public function test_el_detalle_de_la_convocatoria_devuelve_el_checklist_de_cada_documento(): void
    {
        $instructor = $this->instructor($this->ficha());
        $id = $this->actingAs($instructor)->postJson('/api/convocatorias', ['nombre' => 'Detalle', 'documentos' => [['codigo' => 'certificados_laborales']]])->assertCreated()->json('data.id');

        $documentos = collect($this->actingAs($instructor)->getJson("/api/convocatorias/{$id}")->assertOk()->json('data.documentos'));

        $laborales = $documentos->firstWhere('codigo', 'certificados_laborales');
        $this->assertNotEmpty($laborales['items']);
    }
}
