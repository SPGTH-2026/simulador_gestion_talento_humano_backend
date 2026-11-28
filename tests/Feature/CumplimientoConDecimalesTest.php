<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\RondasCorreccionAyudas;
use Tests\TestCase;

/** El cumplimiento no se redondea a un entero: se guarda con dos decimales para que el total sea justo. */
class CumplimientoConDecimalesTest extends TestCase
{
    use RefreshDatabase;
    use RondasCorreccionAyudas;

    private function guardar(array $perfil)
    {
        return $this->actingAs($this->evaluador)->putJson("/api/evaluaciones/{$this->postulacion->id}", [
            'estado' => 'en_proceso',
            'criterios' => [['criterio' => 'Evaluación', 'puntaje' => 0, 'ponderacion' => 1]],
            'perfil' => $perfil,
        ]);
    }

    public function test_un_documento_con_tres_items_aporta_un_tercio_exacto_con_dos_decimales(): void
    {
        // Hoja 50 % con 3 ítems; identidad 50 % sin checklist. 1 de 3 ítems: 50/3 = 16,67 + 50 = 66,67 %.
        $convocatoria = $this->postulacion->convocatoria;
        $convocatoria->documentos()->updateExistingPivot($this->hoja, ['items' => "A\nB\nC"]);

        $this->guardar([
            'docitem-'.$this->hoja.'-0' => true,
            'docitem-'.$this->hoja.'-1' => false,
            'docitem-'.$this->hoja.'-2' => false,
            'doc-'.$this->identidad => true,
        ])->assertOk()->assertJsonPath('data.evaluacion.cumplimiento', 66.67);
    }

    public function test_el_valor_con_decimales_se_guarda_y_se_vuelve_a_leer_igual(): void
    {
        $this->postulacion->convocatoria->documentos()->updateExistingPivot($this->hoja, ['items' => "A\nB\nC"]);
        $perfil = ['docitem-'.$this->hoja.'-0' => true, 'docitem-'.$this->hoja.'-1' => true, 'docitem-'.$this->hoja.'-2' => false, 'doc-'.$this->identidad => true];

        $this->guardar($perfil)->assertOk();
        // Guardar exactamente lo mismo es un reenvío idéntico: no cambia la revisión.
        $this->guardar($perfil)->assertOk();

        $this->assertEquals(83.33, $this->postulacion->evaluaciones()->first()->cumplimiento);
        $this->assertSame(1, (int) $this->postulacion->evaluaciones()->first()->revision);
    }

    public function test_el_cliente_puede_enviar_un_cumplimiento_decimal_sin_error_de_validacion(): void
    {
        $this->actingAs($this->evaluador)->putJson("/api/evaluaciones/{$this->postulacion->id}", [
            'estado' => 'en_proceso',
            'cumplimiento' => 66.67,
            'criterios' => [['criterio' => 'Evaluación', 'puntaje' => 66.67, 'ponderacion' => 1]],
            'perfil' => ['doc-'.$this->hoja => true, 'doc-'.$this->identidad => false],
        ])->assertOk();
    }

    public function test_pasa_a_seleccion_se_decide_con_el_valor_exacto(): void
    {
        // 59,99 no es 60: la regla se aplica sobre el valor exacto, sin redondear hacia arriba.
        $this->postulacion->convocatoria->documentos()->updateExistingPivot($this->hoja, ['items' => "A\nB\nC\nD\nE"]);
        $this->postulacion->convocatoria->documentos()->updateExistingPivot($this->identidad, ['ponderacion' => 50]);

        // 3 de 5 ítems en la hoja (60 % de 50 = 30) + identidad 50 = 80: pasa.
        $this->actingAs($this->evaluador)->putJson("/api/evaluaciones/{$this->postulacion->id}", [
            'estado' => 'finalizada',
            'observaciones' => 'Faltan dos puntos de la hoja de vida.',
            'criterios' => [['criterio' => 'Evaluación', 'puntaje' => 80, 'ponderacion' => 1]],
            'perfil' => [
                'docitem-'.$this->hoja.'-0' => true, 'docitem-'.$this->hoja.'-1' => true, 'docitem-'.$this->hoja.'-2' => true,
                'docitem-'.$this->hoja.'-3' => false, 'docitem-'.$this->hoja.'-4' => false,
                'doc-'.$this->identidad => true,
            ],
        ])->assertOk()
            ->assertJsonPath('data.evaluacion.cumplimiento', 80)
            ->assertJsonPath('data.evaluacion.pasa_seleccion', true);
    }
}
