<?php

namespace Tests\Feature;

use App\Models\Evaluacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\RondasCorreccionAyudas;
use Tests\TestCase;

/** Rondas de corrección: legado. Escenario y utilidades en RondasCorreccionAyudas. */
class RondasLegadoTest extends TestCase
{
    use RefreshDatabase;
    use RondasCorreccionAyudas;

    public function test_una_solicitud_antigua_sin_plazo_se_lee_como_sin_plazo_y_bloquea_ordinarias(): void
    {
        $this->legada();

        $resumen = $this->resumen();
        $this->assertSame('sin_plazo', $resumen['estado']);
        $this->assertSame('legado', $resumen['ronda_actual']['origen']);
        $this->assertSame(1, $resumen['ordinarias_usadas']);
        $this->assertFalse($resumen['puede_solicitar']);
        $this->assertSame('sin_plazo', $resumen['motivo']);

        $this->pedir($this->otroEvaluador)->assertStatus(422);
        $this->actingAs($this->aspirante)->postJson("/api/postulaciones/{$this->postulacion->id}/correccion/enviar")->assertStatus(422)->assertJsonPath('errors.correccion.0', fn ($m) => str_contains($m, 'plazo registrado'));
    }

    public function test_una_solicitud_antigua_vigente_se_puede_responder_y_cuenta_como_la_primera_ronda(): void
    {
        $this->legada(null, now()->addDay());
        $this->assertSame('vigente', $this->resumen()['estado']);

        $this->responder()->assertOk();

        $ronda = $this->rondas()->sole();
        $this->assertSame('legado', $ronda->origen);
        $this->assertNotNull($ronda->respondida_at);

        // La segunda ordinaria queda disponible y no se cuenta dos veces la antigua.
        $resumen = $this->resumen();
        $this->assertSame(1, $resumen['ordinarias_usadas']);
        $this->assertTrue($resumen['puede_solicitar']);
    }

    public function test_el_comando_diagnostica_sin_escribir_convierte_y_es_idempotente(): void
    {
        $this->legada(null, now()->subDay());

        $this->artisan('correcciones:migrar-legado')->expectsOutputToContain('DIAGNÓSTICO DE SOLO LECTURA')->assertSuccessful();
        $this->assertCount(0, $this->rondas());

        $this->artisan('correcciones:migrar-legado', ['--aplicar' => true])->assertSuccessful();
        $this->assertCount(1, $this->rondas());
        $original = Evaluacion::where('postulacion_id', $this->postulacion->id)->sole();
        $this->assertTrue($this->rondas()->sole()->fecha_limite->equalTo($original->fecha_limite_correccion), 'conserva el plazo original');

        // Segunda ejecución: nada nuevo y sin contar dos veces.
        $this->artisan('correcciones:migrar-legado', ['--aplicar' => true])->assertSuccessful();
        $this->assertCount(1, $this->rondas());
        $this->assertSame(1, $this->resumen()['ordinarias_usadas']);
    }

    public function test_los_solapes_de_distintos_evaluadores_se_reportan_y_no_se_agrupan(): void
    {
        $this->legada($this->evaluador, now()->subDay());
        $this->legada($this->otroEvaluador, now()->subHours(5));

        $this->artisan('correcciones:migrar-legado', ['--aplicar' => true])
            ->expectsOutputToContain('no se agrupan')->assertSuccessful();
        $this->assertCount(0, $this->rondas(), 'no se convierten sin revisar');
        // Mientras tanto, la API los cuenta por separado y con las mismas restricciones.
        $this->assertSame(2, $this->resumen()['ordinarias_usadas']);
        $this->assertSame('solape', $this->resumen()['motivo']);
        $this->assertFalse($this->resumen($this->instructor)['puede_autorizar'], 'no se ofrece autorizar mientras haya solape sin revisar');

        // Ninguna operación de la API los convierte ni los agrupa por su cuenta: se rechazan hasta la revisión.
        $this->pedir($this->evaluador, 'Nueva.')->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'simultáneas'));
        $this->actingAs($this->instructor)->postJson("/api/evaluaciones/{$this->postulacion->id}/correcciones/autorizacion", [
            'justificacion' => 'Intento antes de revisar los solapes.',
        ])->assertStatus(422);
        $this->actingAs($this->aspirante)->postJson("/api/postulaciones/{$this->postulacion->id}/correccion/enviar")->assertStatus(422);
        // Cambiar el estado de una evaluación que es parte del solape tampoco la saca del conteo.
        $this->actingAs($this->evaluador)->putJson("/api/evaluaciones/{$this->postulacion->id}", [
            'estado' => 'en_proceso',
            'criterios' => [['criterio' => 'Evaluación', 'puntaje' => 0, 'ponderacion' => 1]],
            'perfil' => ['doc-'.$this->hoja => false, 'doc-'.$this->identidad => true],
        ])->assertStatus(422);
        $this->assertCount(0, $this->rondas());
        $this->assertSame(2, $this->resumen()['ordinarias_usadas']);
        $this->assertSame('correccion_solicitada', Evaluacion::where('evaluador_id', $this->evaluador->id)->value('estado'));

        $this->artisan('correcciones:migrar-legado', ['--aplicar' => true, '--incluir-solapes' => true])->assertSuccessful();
        $this->assertCount(2, $this->rondas());
        $this->assertSame([$this->evaluador->id, $this->otroEvaluador->id], $this->rondas()->pluck('solicitada_por')->sort()->values()->all());
        $this->assertSame(2, $this->resumen()['ordinarias_usadas']);
    }
}
