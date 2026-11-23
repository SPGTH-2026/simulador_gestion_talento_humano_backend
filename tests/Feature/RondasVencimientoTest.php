<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\RondasCorreccionAyudas;
use Tests\TestCase;

/** Rondas de corrección: vencimiento. Escenario y utilidades en RondasCorreccionAyudas. */
class RondasVencimientoTest extends TestCase
{
    use RefreshDatabase;
    use RondasCorreccionAyudas;

    public function test_vencida_sin_respuesta_bloquea_las_ordinarias_aunque_quede_una_sin_usar(): void
    {
        $this->pedir($this->evaluador)->assertOk();
        $this->vencer($this->rondas()->sole());

        $resumen = $this->resumen();
        $this->assertSame('vencida', $resumen['estado']);
        $this->assertFalse($resumen['puede_solicitar']);
        $this->assertSame('vencida', $resumen['motivo']);
        $this->assertSame(1, $resumen['ordinarias_restantes']);

        $this->pedir($this->evaluador, 'Otra.')->assertStatus(422);
        $this->assertCount(1, $this->rondas());

        // El aspirante tampoco puede responder una ronda vencida (hora de Colombia en el mensaje).
        $this->actingAs($this->aspirante)->postJson("/api/postulaciones/{$this->postulacion->id}/correccion/enviar")->assertStatus(422)->assertJsonPath('errors.correccion.0', fn ($m) => str_contains($m, 'hora de Colombia'));
    }

    public function test_la_marca_abierta_se_libera_sin_tareas_programadas_al_operar(): void
    {
        $this->pedir($this->evaluador)->assertOk();
        $ronda = $this->rondas()->sole();
        $this->vencer($ronda);
        $this->assertSame(1, $ronda->fresh()->abierta, 'el vencimiento por sí solo no escribe');

        // Una operación bajo bloqueo (autorizar) la libera.
        $this->actingAs($this->instructor)->postJson("/api/evaluaciones/{$this->postulacion->id}/correcciones/autorizacion", [
            'justificacion' => 'El aspirante tuvo un problema técnico.',
        ])->assertCreated();

        $this->assertNull($ronda->fresh()->abierta);
        $this->assertNotNull($ronda->fresh()->cerrada_at);
    }
}
