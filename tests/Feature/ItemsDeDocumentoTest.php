<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ConvocatoriaGestionAyudas;
use Tests\TestCase;

/** Cada documento de la convocatoria puede traer su propio checklist de ítems (uno por línea). */
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
        $this->assertNull($documentos['documento_identidad']['items']);
    }
}
