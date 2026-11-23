<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\EvaluacionReenvioReferenciaAyudas;
use Tests\TestCase;

class EvaluacionReenvioDocumentalTest extends TestCase
{
    use RefreshDatabase;
    use EvaluacionReenvioReferenciaAyudas;

    public function test_reemplazar_un_documento_justo_despues_de_varios_cambios_en_el_mismo_segundo_se_detecta(): void
    {
        $a = $this->conReferenciaVigente();
        $this->variosCambiosEnElMismoSegundo();
        $this->designar($a->fresh())->assertCreated();
        $this->assertReferenciaVigente();

        // Mismo instante: el reemplazo llega después de los cambios (la fecha de A ya está adelantada).
        $this->reemplazarHojaDeVida()->assertOk();

        // Las dos evaluaciones vuelven a «en proceso» y pierden su elegibilidad.
        foreach ([$this->evaluadorA, $this->evaluadorB] as $evaluador) {
            $this->assertDatabaseHas('evaluaciones', ['evaluador_id' => $evaluador->id, 'estado' => 'en_proceso', 'pasa_seleccion' => null]);
        }
        // La referencia pierde vigencia y la postulación exige una revisión nueva.
        $r = $this->referencia();
        $this->assertFalse($r['referencia']['vigente']);
        $this->assertSame('requiere_revision', $r['estado_documental']);
        // El evaluador ve el documento como actualizado, aunque la fecha de su evaluación se hubiera adelantado.
        $this->assertSame(['doc-'.$this->doc['hv']], $this->documentosActualizados($this->evaluadorA));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('segundosDespues')]
    public function test_reemplazar_un_documento_despues_de_cambios_adelantados_tambien_se_detecta(int $segundos): void
    {
        $this->conReferenciaVigente();
        $this->variosCambiosEnElMismoSegundo(); // la fecha de A queda en 10:00:03, adelantada al reloj

        Carbon::setTestNow(Carbon::parse('2026-11-15 10:00:00')->addSeconds($segundos));
        $this->reemplazarHojaDeVida()->assertOk();

        $this->assertDatabaseHas('evaluaciones', ['evaluador_id' => $this->evaluadorA->id, 'estado' => 'en_proceso', 'pasa_seleccion' => null]);
        $this->assertFalse($this->referencia()['referencia']['vigente']);
        $this->assertSame(['doc-'.$this->doc['hv']], $this->documentosActualizados($this->evaluadorA));
    }

    public function test_tras_el_reemplazo_el_evaluador_rehace_la_evaluacion_y_se_puede_reconfirmar_la_referencia(): void
    {
        $a = $this->conReferenciaVigente();
        $this->variosCambiosEnElMismoSegundo();

        Carbon::setTestNow('2026-11-15 10:05:00');
        $this->reemplazarHojaDeVida()->assertOk();

        // Un minuto después el evaluador vuelve a finalizar: ya no quedan documentos pendientes de revisar.
        Carbon::setTestNow('2026-11-15 10:06:00');
        $this->evaluar($this->evaluadorA, ['observaciones' => 'Tercer cambio.'])->assertOk();
        $this->evaluar($this->evaluadorB)->assertOk();
        $this->assertSame([], $this->documentosActualizados($this->evaluadorA));
        $this->assertSame('requiere_revision', $this->referencia()['estado_documental']);

        $this->designar($a->fresh())->assertCreated();
        $this->assertReferenciaVigente();
    }

    // ── Revisión de la evaluación ──────────────────────────────────────

}
