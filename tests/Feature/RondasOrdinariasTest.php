<?php

namespace Tests\Feature;

use App\Models\CorreccionPostulacion;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\RondasCorreccionAyudas;
use Tests\TestCase;

/** Rondas de corrección: ordinarias. Escenario y utilidades en RondasCorreccionAyudas. */
class RondasOrdinariasTest extends TestCase
{
    use RefreshDatabase;
    use RondasCorreccionAyudas;

    public function test_la_ronda_guarda_historial_y_dura_48_horas_en_utc(): void
    {
        $this->pedir($this->evaluador, 'Hoja borrosa.')->assertOk();

        $ronda = $this->rondas()->sole();
        $this->assertSame('ordinaria', $ronda->tipo);
        $this->assertSame(1, $ronda->numero);
        $this->assertSame($this->evaluador->id, $ronda->solicitada_por);
        $this->assertSame([$this->hoja], $ronda->documentos_marcados);
        $this->assertSame('Hoja borrosa.', $ronda->observaciones);
        $this->assertTrue($ronda->fecha_limite->equalTo($ronda->solicitada_at->copy()->addHours(48)));
        $this->assertSame('UTC', $ronda->fecha_limite->getTimezone()->getName());
        $this->assertSame(1, $ronda->abierta);
    }

    public function test_el_reenvio_identico_no_crea_otra_ronda(): void
    {
        $this->pedir($this->evaluador)->assertOk();
        $this->pedir($this->evaluador)->assertOk();

        $this->assertCount(1, $this->rondas());
    }

    public function test_una_solicitud_distinta_con_una_ronda_vigente_se_rechaza(): void
    {
        $this->pedir($this->evaluador, 'Primera.')->assertOk();

        $this->pedir($this->evaluador, 'Otra distinta.')->assertStatus(422)
            ->assertJsonPath('message', 'Ya hay una corrección pendiente: espera la respuesta del aspirante antes de pedir otra.');
        // Otro evaluador tampoco puede abrir una segunda pendiente.
        $this->pedir($this->otroEvaluador, 'De otro evaluador.')->assertStatus(422);

        $this->assertCount(1, $this->rondas());
    }

    public function test_la_base_impide_dos_rondas_abiertas_de_la_misma_postulacion(): void
    {
        $this->pedir($this->evaluador)->assertOk();

        $this->expectException(QueryException::class);
        CorreccionPostulacion::create([
            'postulacion_id' => $this->postulacion->id, 'tipo' => 'excepcional', 'numero' => 1,
            'documentos_marcados' => [$this->hoja], 'abierta' => 1, 'solicitada_at' => now(), 'fecha_limite' => now()->addDay(),
        ]);
    }

    public function test_la_segunda_ronda_ordinaria_exige_la_respuesta_de_la_primera_y_tiene_plazo_nuevo(): void
    {
        $this->pedir($this->evaluador)->assertOk();
        $primera = $this->rondas()->sole();

        $this->travel(10)->minutes();
        $this->responder()->assertOk();
        $this->assertNotNull($primera->fresh()->respondida_at);
        $this->assertNull($primera->fresh()->abierta);

        $resumen = $this->resumen();
        $this->assertTrue($resumen['puede_solicitar']);
        $this->assertSame('quedan', $resumen['motivo']);
        $this->assertSame(1, $resumen['ordinarias_usadas']);

        $this->travel(10)->minutes();
        $this->pedir($this->evaluador, 'Ahora la identidad.', [$this->identidad])->assertOk();

        $segunda = $this->rondas()->last();
        $this->assertSame(2, $segunda->numero);
        $this->assertTrue($segunda->fecha_limite->gt($primera->fecha_limite));
        $this->assertTrue($segunda->fecha_limite->equalTo($segunda->solicitada_at->copy()->addHours(48)));
    }

    public function test_las_dos_rondas_ordinarias_se_comparten_entre_evaluadores_y_luego_se_agotan(): void
    {
        $this->pedir($this->evaluador)->assertOk();
        $this->responder()->assertOk();

        // Otro evaluador pide la segunda: cuenta para la misma postulación.
        $this->pedir($this->otroEvaluador, 'La identidad.', [$this->identidad])->assertOk();
        $this->responder('documento_identidad')->assertOk();

        $this->assertSame([1, 2], $this->rondas()->pluck('numero')->all());
        $this->assertSame([$this->evaluador->id, $this->otroEvaluador->id], $this->rondas()->pluck('solicitada_por')->all());

        // Tercera ordinaria: agotadas, pida quien la pida.
        $this->pedir($this->evaluador, 'Tercera.')->assertStatus(422);
        $this->pedir($this->otroEvaluador, 'Tercera.')->assertStatus(422);
        $resumen = $this->resumen();
        $this->assertFalse($resumen['puede_solicitar']);
        $this->assertSame('agotadas', $resumen['motivo']);
        $this->assertCount(2, $this->rondas());
    }

    public function test_un_archivo_que_ya_existia_no_cuenta_como_corregido(): void
    {
        $this->pedir($this->evaluador)->assertOk();

        $ronda = $this->resumen()['ronda_actual'];
        $this->assertSame([$this->hoja], $ronda['documentos_marcados']);
        $this->assertSame([], $ronda['documentos_reemplazados']);

        // Sin reemplazar el archivo, enviar la corrección falla y lista lo que falta.
        $this->actingAs($this->aspirante)->postJson("/api/postulaciones/{$this->postulacion->id}/correccion/enviar")
            ->assertStatus(422)->assertJsonPath('faltantes', [$this->hoja]);

        $this->responder('hoja_de_vida', false);
        $this->assertSame([$this->hoja], $this->resumen()['ronda_actual']['documentos_reemplazados']);
    }

    public function test_pedir_correccion_exige_un_documento_marcado_de_la_convocatoria(): void
    {
        $this->actingAs($this->evaluador)->putJson("/api/evaluaciones/{$this->postulacion->id}", [
            'estado' => 'correccion_solicitada',
            'observaciones' => 'Sin documentos.',
            'criterios' => [['criterio' => 'Evaluación', 'puntaje' => 0, 'ponderacion' => 1]],
            'perfil' => ['doc-'.$this->hoja => true, 'doc-'.$this->identidad => true],
        ])->assertStatus(422);

        $this->assertCount(0, $this->rondas());
    }

    public function test_un_reenvio_identico_tras_la_respuesta_pide_la_segunda_ronda(): void
    {
        $this->pedir($this->evaluador, 'Hoja borrosa.')->assertOk();
        $this->responder()->assertOk();

        // Mismo contenido que la primera solicitud: tras la respuesta del aspirante abre la ronda 2, no se descarta
        // como reenvío idéntico (el aspirante no podría volver a corregir).
        $this->pedir($this->evaluador, 'Hoja borrosa.')->assertOk();

        $this->assertSame([1, 2], $this->rondas()->pluck('numero')->all());
        $this->assertSame(1, $this->rondas()->where('abierta', 1)->count());
    }
}
