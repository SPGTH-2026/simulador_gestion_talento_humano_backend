<?php

namespace Tests\Feature;

use App\Models\Evaluacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EvaluacionReenvioReferenciaAyudas;
use Tests\TestCase;

class EvaluacionReenvioRevisionesTest extends TestCase
{
    use RefreshDatabase;
    use EvaluacionReenvioReferenciaAyudas;

    public function test_la_revision_nace_en_1_sube_en_1_con_cada_cambio_real_y_no_con_un_reenvio_identico(): void
    {
        $this->evaluar($this->evaluadorA)->assertOk();
        $this->assertSame(1, $this->revision($this->evaluadorA));

        $this->evaluar($this->evaluadorA)->assertOk(); // idéntico
        $this->assertSame(1, $this->revision($this->evaluadorA));

        $this->evaluar($this->evaluadorA, ['observaciones' => 'Primer cambio.'])->assertOk();
        $this->assertSame(2, $this->revision($this->evaluadorA));

        $this->evaluar($this->evaluadorA, ['observaciones' => 'Primer cambio.'])->assertOk(); // idéntico al anterior
        $this->assertSame(2, $this->revision($this->evaluadorA));

        // Tres cambios más en el mismo segundo: cada uno suma exactamente 1.
        foreach (['b', 'c', 'd'] as $i => $letra) {
            $this->evaluar($this->evaluadorA, ['observaciones' => "Cambio {$letra}."])->assertOk();
            $this->assertSame(3 + $i, $this->revision($this->evaluadorA));
        }
    }

    public function test_cada_evaluacion_invalidada_por_un_cambio_documental_suma_una_revision(): void
    {
        $this->conReferenciaVigente();
        $this->assertSame([1, 1], [$this->revision($this->evaluadorA), $this->revision($this->evaluadorB)]);

        $this->reemplazarHojaDeVida()->assertOk();

        $this->assertSame([2, 2], [$this->revision($this->evaluadorA), $this->revision($this->evaluadorB)]);

        // Una evaluación que ya no estaba finalizada no suma nada con otro reemplazo.
        $this->reemplazarHojaDeVida()->assertOk();
        $this->assertSame([2, 2], [$this->revision($this->evaluadorA), $this->revision($this->evaluadorB)]);
    }

    // ── Colisión en el mismo segundo (referencias nuevas) ──────────────

    public function test_una_invalidacion_seguida_de_una_reevaluacion_en_el_mismo_segundo_de_la_designacion_no_reactiva_la_referencia(): void
    {
        $a = $this->conReferenciaVigente(); // designada a las 10:00:00 con la evaluación de A tal como está

        // En ese mismo instante: el aspirante reemplaza un documento (la evaluación vuelve a «en proceso» y su fecha
        // retrocede un segundo) y el evaluador la rehace con EXACTAMENTE el mismo contenido que tenía al designarla.
        $this->reemplazarHojaDeVida()->assertOk();
        $this->evaluar($this->evaluadorA)->assertOk();
        $this->evaluar($this->evaluadorB)->assertOk();

        // Con la regla por fecha la referencia volvía a estar vigente por coincidencia de segundos. Con la revisión no.
        $this->assertSame($a->fresh()->updated_at->toIso8601String(), '2026-11-15T10:00:00+00:00');
        $this->assertReferenciaInvalidada();

        // Reconfirmar funciona y deja la referencia vigente sobre la revisión actual.
        $this->designar($a->fresh())->assertCreated();
        $this->assertReferenciaVigente();
    }

    public function test_reconfirmar_tras_un_cambio_real_deja_la_referencia_vigente_y_un_cambio_posterior_la_invalida_de_nuevo(): void
    {
        $a = $this->conReferenciaVigente();
        $this->evaluar($this->evaluadorA, ['observaciones' => 'Cambio uno.'])->assertOk();
        $this->assertReferenciaInvalidada();

        $this->designar($a->fresh())->assertCreated();
        $this->assertReferenciaVigente();
        $this->assertSame($this->revision($this->evaluadorA), \App\Models\ReferenciaDocumental::orderByDesc('id')->first()->evaluacion_revision);

        $this->evaluar($this->evaluadorA, ['observaciones' => 'Cambio dos.'])->assertOk();
        $this->assertReferenciaInvalidada();
    }

    // ── Referencias antiguas (relleno de la migración, sin respaldo por fecha) ──

    public function test_una_referencia_antigua_vigente_sigue_vigente_hasta_el_primer_cambio_real(): void
    {
        $this->evaluar($this->evaluadorA)->assertOk();
        $this->evaluar($this->evaluadorB)->assertOk();
        $a = Evaluacion::where('evaluador_id', $this->evaluadorA->id)->sole();
        $this->referenciaAntigua($a); // foto de fecha igual a la de la evaluación: vigente con la regla de antes

        $this->migracionDeRevision()->rellenarRevisionDeReferencias();

        $this->assertSame(1, \App\Models\ReferenciaDocumental::sole()->evaluacion_revision);
        $this->assertReferenciaVigente();

        // El primer cambio real (aunque sea en el mismo segundo) la invalida.
        $this->evaluar($this->evaluadorA, ['observaciones' => 'Primer cambio tras la migración.'])->assertOk();
        $this->assertReferenciaInvalidada();
    }

    public function test_una_referencia_antigua_ya_invalida_sigue_invalida_aunque_la_fecha_vuelva_a_coincidir(): void
    {
        $this->evaluar($this->evaluadorA)->assertOk();
        $this->evaluar($this->evaluadorB)->assertOk();
        $a = Evaluacion::where('evaluador_id', $this->evaluadorA->id)->sole();
        // La foto guarda una fecha anterior: con la regla de antes ya estaba invalidada.
        $this->referenciaAntigua($a, '2026-11-15 09:59:00');

        $this->migracionDeRevision()->rellenarRevisionDeReferencias();

        $this->assertSame(0, \App\Models\ReferenciaDocumental::sole()->evaluacion_revision);
        $this->assertReferenciaInvalidada();

        // Coincidencia de segundos: la fecha de la evaluación vuelve a ser la de la foto, con el mismo cumplimiento y
        // resultado. Con el respaldo por fecha esto la reactivaba; ahora no existe ese respaldo.
        \Illuminate\Support\Facades\DB::table('evaluaciones')->where('id', $a->id)->update(['updated_at' => '2026-11-15 09:59:00']);
        $this->assertReferenciaInvalidada();

        // Reconfirmar la deja vigente con la revisión actual.
        $this->designar($a->fresh())->assertCreated();
        $this->assertReferenciaVigente();
    }

    public function test_el_relleno_deja_en_cero_la_referencia_cuya_evaluacion_ya_no_existe_o_se_reabrio(): void
    {
        $this->evaluar($this->evaluadorA)->assertOk();
        $this->evaluar($this->evaluadorB)->assertOk();
        $a = Evaluacion::where('evaluador_id', $this->evaluadorA->id)->sole();
        $b = Evaluacion::where('evaluador_id', $this->evaluadorB->id)->sole();

        $reabierta = $this->referenciaAntigua($a);
        Evaluacion::whereKey($a->id)->update(['estado' => 'en_proceso', 'pasa_seleccion' => null]);
        $huerfana = $this->referenciaAntigua($b);
        Evaluacion::whereKey($b->id)->delete(); // la fila de la referencia queda con evaluacion_id nulo

        $this->migracionDeRevision()->rellenarRevisionDeReferencias();

        $this->assertSame(0, $reabierta->fresh()->evaluacion_revision);
        $this->assertSame(0, $huerfana->fresh()->evaluacion_revision);
    }

    public function test_el_relleno_trata_un_cumplimiento_nulo_como_la_regla_anterior_y_no_como_cero(): void
    {
        $this->evaluar($this->evaluadorA)->assertOk();
        $this->evaluar($this->evaluadorB)->assertOk();
        $a = Evaluacion::where('evaluador_id', $this->evaluadorA->id)->sole();
        $b = Evaluacion::where('evaluador_id', $this->evaluadorB->id)->sole();

        // Datos antiguos: evaluaciones finalizadas con resultado (pasa_seleccion) pero SIN cumplimiento (nulo). La referencia
        // guarda su foto en 0 (la columna no admite nulo) con la misma fecha y el mismo resultado.
        \Illuminate\Support\Facades\DB::table('evaluaciones')->whereIn('id', [$a->id, $b->id])->update(['cumplimiento' => null, 'pasa_seleccion' => false]);
        \Illuminate\Support\Facades\DB::table('evaluaciones')->where('id', $b->id)->update(['cumplimiento' => 0]); // control: 0 real
        $crear = fn (Evaluacion $e) => \App\Models\ReferenciaDocumental::create([
            'postulacion_id' => $this->postulacion->id, 'evaluacion_id' => $e->id, 'evaluador_id' => $e->evaluador_id,
            'designado_por' => $this->instructor->id, 'justificacion' => 'Referencia anterior a la migración.',
            'cumplimiento' => 0, 'pasa_seleccion' => false, 'evaluacion_actualizada_en' => $e->fresh()->updated_at,
        ]);
        $conNulo = $crear($a);
        $conCero = $crear($b);

        $this->migracionDeRevision()->rellenarRevisionDeReferencias();

        // Bajo la regla anterior (null === 0 es falso) la del cumplimiento nulo ya era inválida: queda en 0.
        $this->assertSame(0, $conNulo->fresh()->evaluacion_revision);
        // La del cumplimiento 0 real sí era vigente: conserva la revisión de su evaluación.
        $this->assertSame($b->fresh()->revision, $conCero->fresh()->evaluacion_revision);
        $this->assertGreaterThan(0, $conCero->fresh()->evaluacion_revision);
    }

}
