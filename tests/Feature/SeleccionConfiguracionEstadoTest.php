<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\SeleccionConfiguracion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeleccionConfiguracionAyudas;
use Tests\TestCase;

class SeleccionConfiguracionEstadoTest extends TestCase
{
    use RefreshDatabase;
    use SeleccionConfiguracionAyudas;

    public function test_reenviar_no_duplica_y_actualiza_la_misma_fila(): void
    {
        $ficha = $this->ficha('2896411');
        $convocatoria = $this->convocatoria($ficha);
        $primero = $this->seleccionador($ficha);
        $segundo = $this->usuario(Role::Instructor, null, ['ficha_id' => $ficha->id]);
        $cuerpo = ['entrevista_activa' => true, 'prueba_tecnica_activa' => true, 'peso_entrevista' => 30, 'peso_prueba_tecnica' => 70];

        $this->guardar($primero, $convocatoria, $cuerpo)->assertOk();
        $id = SeleccionConfiguracion::first()->id;
        $this->guardar($primero, $convocatoria, $cuerpo)->assertOk();
        $this->assertSame(1, SeleccionConfiguracion::count());

        $this->guardar($segundo, $convocatoria, ['entrevista_activa' => false, 'prueba_tecnica_activa' => true])->assertOk();
        $fila = SeleccionConfiguracion::first();
        $this->assertSame(1, SeleccionConfiguracion::count());
        $this->assertSame($id, $fila->id);
        $this->assertSame($segundo->id, $fila->actualizado_por);
        $this->assertNull($fila->peso_entrevista);
        $this->assertSame(100, $fila->peso_prueba_tecnica);
    }

    public function test_cada_convocatoria_tiene_su_propia_configuracion(): void
    {
        $ficha = $this->ficha('2896411');
        $uno = $this->convocatoria($ficha);
        $dos = $this->convocatoria($ficha);
        $actor = $this->seleccionador($ficha);

        $this->guardar($actor, $uno, ['entrevista_activa' => true, 'prueba_tecnica_activa' => false])->assertOk();

        $this->actingAs($actor)->getJson($this->url($dos))->assertOk()->assertJsonPath('data.configurada', false);
        $this->assertSame(1, SeleccionConfiguracion::count());
    }

    // ── Estado de la convocatoria ──────────────────────────────────────

    public function test_se_puede_guardar_en_publicada_y_en_proceso(): void
    {
        $ficha = $this->ficha('2896411');
        $actor = $this->seleccionador($ficha);

        foreach (['publicada', 'en_proceso'] as $estado) {
            $convocatoria = $this->convocatoria($ficha, $estado);
            $this->guardar($actor, $convocatoria, ['entrevista_activa' => true, 'prueba_tecnica_activa' => true])
                ->assertOk()->assertJsonPath('data.estado_convocatoria', $estado)->assertJsonPath('data.editable', true);
        }
    }

    public function test_no_se_puede_guardar_en_borrador_ni_cerrada(): void
    {
        $ficha = $this->ficha('2896411');
        $actor = $this->seleccionador($ficha);

        foreach (['borrador', 'cerrada'] as $estado) {
            $convocatoria = $this->convocatoria($ficha, $estado);
            $this->guardar($actor, $convocatoria, ['entrevista_activa' => true, 'prueba_tecnica_activa' => false])
                ->assertUnprocessable()->assertJsonValidationErrors('convocatoria');
        }
        $this->assertSame(0, SeleccionConfiguracion::count());
    }

    public function test_una_convocatoria_cerrada_conserva_y_muestra_su_configuracion(): void
    {
        $ficha = $this->ficha('2896411');
        $convocatoria = $this->convocatoria($ficha, 'en_proceso');
        $actor = $this->seleccionador($ficha);

        $this->guardar($actor, $convocatoria, ['entrevista_activa' => true, 'prueba_tecnica_activa' => true, 'peso_entrevista' => 40, 'peso_prueba_tecnica' => 60])->assertOk();
        $this->cambiarEstado($convocatoria, 'cerrada');

        $this->actingAs($actor)->getJson($this->url($convocatoria))->assertOk()
            ->assertJsonPath('data.estado_convocatoria', 'cerrada')
            ->assertJsonPath('data.editable', false)
            ->assertJsonPath('data.opcion', 'ambas')
            ->assertJsonPath('data.entrevista.peso', 40)
            ->assertJsonPath('data.prueba_tecnica.peso', 60);

        // Y ya no se puede modificar.
        $this->guardar($actor, $convocatoria, ['entrevista_activa' => false, 'prueba_tecnica_activa' => false])->assertUnprocessable();
        $this->assertSame(40, SeleccionConfiguracion::first()->peso_entrevista);
    }

}
