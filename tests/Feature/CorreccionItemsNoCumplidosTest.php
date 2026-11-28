<?php

namespace Tests\Feature;

use App\Enums\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\RondasCorreccionAyudas;
use Tests\TestCase;

/** Al pedir una corrección, el aspirante ve qué puntos del checklist de cada documento no cumplió. */
class CorreccionItemsNoCumplidosTest extends TestCase
{
    use RefreshDatabase;
    use RondasCorreccionAyudas;

    private function pedirConItems(array $marcas)
    {
        $this->postulacion->convocatoria->documentos()->updateExistingPivot($this->hoja, ['items' => "Datos completos\nFirmada\nCon fecha\nSin tachones"]);

        return $this->actingAs($this->evaluador)->putJson("/api/evaluaciones/{$this->postulacion->id}", [
            'estado' => 'correccion_solicitada',
            'observaciones' => 'Hay campos borrosos.',
            'criterios' => [['criterio' => 'Evaluación', 'puntaje' => 0, 'ponderacion' => 1]],
            'perfil' => ['doc-'.$this->hoja => false, 'doc-'.$this->identidad => true] + $marcas,
        ]);
    }

    public function test_el_aspirante_ve_los_puntos_que_no_cumplio_por_documento(): void
    {
        $this->pedirConItems([
            'docitem-'.$this->hoja.'-0' => true,
            'docitem-'.$this->hoja.'-1' => false,
            'docitem-'.$this->hoja.'-2' => true,
            'docitem-'.$this->hoja.'-3' => false,
        ])->assertOk();

        $respuesta = $this->actingAs($this->aspirante)->getJson("/api/postulaciones/{$this->postulacion->id}/correccion")->assertOk();

        $this->assertSame(['Firmada', 'Sin tachones'], $respuesta->json('data.items_no_cumplidos.'.$this->hoja));
        // Un documento sin ítems fallidos no aparece.
        $this->assertArrayNotHasKey((string) $this->identidad, $respuesta->json('data.items_no_cumplidos'));
    }

    public function test_sin_items_marcados_la_lista_va_vacia(): void
    {
        $this->pedirConItems([])->assertOk();

        $this->actingAs($this->aspirante)->getJson("/api/postulaciones/{$this->postulacion->id}/correccion")
            ->assertOk()
            ->assertJsonPath('data.items_no_cumplidos', []);
    }

    public function test_otro_aspirante_no_ve_los_puntos(): void
    {
        $this->pedirConItems(['docitem-'.$this->hoja.'-1' => false])->assertOk();

        $this->actingAs($this->usuario(Role::Aspirante))->getJson("/api/postulaciones/{$this->postulacion->id}/correccion")->assertNotFound();
    }
}
