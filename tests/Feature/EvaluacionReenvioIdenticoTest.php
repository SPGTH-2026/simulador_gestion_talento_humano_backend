<?php

namespace Tests\Feature;

use App\Models\Evaluacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\EvaluacionReenvioReferenciaAyudas;
use Tests\TestCase;

class EvaluacionReenvioIdenticoTest extends TestCase
{
    use RefreshDatabase;
    use EvaluacionReenvioReferenciaAyudas;

    public function test_reenviar_la_evaluacion_identica_conserva_la_referencia_vigente_y_no_toca_nada(): void
    {
        $a = $this->conReferenciaVigente();
        $antes = $a->fresh();

        Carbon::setTestNow('2026-11-15 10:05:00');
        $this->evaluar($this->evaluadorA)->assertOk();

        $despues = $a->fresh();
        $this->assertReferenciaVigente();
        $this->assertSame($antes->revision, $despues->revision, 'Un reenvío idéntico conserva la revisión.');
        $this->assertSame($antes->updated_at->toIso8601String(), $despues->updated_at->toIso8601String());
        $this->assertDatabaseCount('evaluaciones', 2);
        $this->assertDatabaseCount('evaluacion_detalles', 4);
    }

    public function test_reenviar_con_las_claves_del_perfil_y_los_criterios_en_otro_orden_tambien_es_identico(): void
    {
        // El orden en que llegan las claves no es contenido: MySQL normaliza el JSON y la pantalla las envía en el
        // orden de los documentos de la convocatoria.
        $this->conReferenciaVigente(['perfil' => ['doc-'.$this->doc['id'] => true, 'doc-'.$this->doc['hv'] => true]]);

        Carbon::setTestNow('2026-11-15 10:05:00');
        $this->evaluar($this->evaluadorA, [
            'perfil' => ['doc-'.$this->doc['hv'] => true, 'doc-'.$this->doc['id'] => true],
            'criterios' => [
                ['criterio' => 'Experiencia', 'puntaje' => 60, 'ponderacion' => 1],
                ['criterio' => 'Formación', 'puntaje' => 80, 'ponderacion' => 1],
            ],
        ])->assertOk();

        $this->assertReferenciaVigente();
    }

    // ── Cambios reales: invalidan, aunque cumplimiento y total no cambien ──

    public function test_un_cambio_de_criterio_con_el_mismo_puntaje_total_invalida_la_referencia(): void
    {
        $this->conReferenciaVigente();

        // Mismo instante que la designación, mismo total (70) y mismo cumplimiento: solo cambia el nombre de un criterio.
        $this->evaluar($this->evaluadorA, ['criterios' => [
            ['criterio' => 'Formación académica', 'puntaje' => 80, 'ponderacion' => 1],
            ['criterio' => 'Experiencia', 'puntaje' => 60, 'ponderacion' => 1],
        ]])->assertOk();

        $this->assertReferenciaInvalidada();
    }

    public function test_cambiar_los_puntajes_de_los_criterios_dejando_el_mismo_total_invalida_la_referencia(): void
    {
        $this->conReferenciaVigente();

        // 80/60 → 60/80: el promedio sigue siendo 70.
        $this->evaluar($this->evaluadorA, ['criterios' => [
            ['criterio' => 'Formación', 'puntaje' => 60, 'ponderacion' => 1],
            ['criterio' => 'Experiencia', 'puntaje' => 80, 'ponderacion' => 1],
        ]])->assertOk();

        $this->assertReferenciaInvalidada();
        $this->assertSame('70.00', (string) Evaluacion::where('evaluador_id', $this->evaluadorA->id)->value('puntaje_total'));
    }

    public function test_cambiar_que_documento_cumple_con_el_mismo_cumplimiento_invalida_la_referencia(): void
    {
        // Con 50 % y 50 %, cumplir uno u otro da el mismo 50 % (no habilitado): cambia el perfil, no el cumplimiento.
        $this->conReferenciaVigente(['perfil' => ['doc-'.$this->doc['hv'] => true, 'doc-'.$this->doc['id'] => false]]);
        $this->assertSame('no_habilitado', $this->referencia()['estado_documental']);

        $this->evaluar($this->evaluadorA, ['perfil' => ['doc-'.$this->doc['hv'] => false, 'doc-'.$this->doc['id'] => true]])->assertOk();

        $this->assertReferenciaInvalidada();
    }

    public function test_cambiar_solo_las_observaciones_invalida_la_referencia(): void
    {
        $this->conReferenciaVigente();

        $this->evaluar($this->evaluadorA, ['observaciones' => 'Cambio la observación.'])->assertOk();

        $this->assertReferenciaInvalidada();
    }

    public function test_un_cambio_real_en_un_segundo_posterior_tambien_invalida_la_referencia(): void
    {
        $this->conReferenciaVigente();

        Carbon::setTestNow('2026-11-15 10:00:07');
        $this->evaluar($this->evaluadorA, ['perfil' => ['doc-'.$this->doc['hv'] => true, 'doc-'.$this->doc['id'] => false]])->assertOk();

        $this->assertReferenciaInvalidada();
    }

    public function test_varios_cambios_reales_dentro_del_mismo_segundo_invalidan_cada_vez_y_se_puede_reconfirmar(): void
    {
        $a = $this->conReferenciaVigente();

        $this->evaluar($this->evaluadorA, ['observaciones' => 'Primer cambio.'])->assertOk();
        $this->assertReferenciaInvalidada();

        // Reconfirmar la referencia (misma evaluación, ya cambiada) la deja vigente otra vez...
        $this->designar($a->fresh())->assertCreated();
        $this->assertReferenciaVigente();

        // ...y otro cambio real en ese mismo segundo vuelve a invalidarla.
        $this->evaluar($this->evaluadorA, ['observaciones' => 'Segundo cambio.'])->assertOk();
        $this->assertReferenciaInvalidada();
    }

    public function test_un_reenvio_identico_de_una_evaluacion_nueva_no_la_duplica(): void
    {
        $this->evaluar($this->evaluadorA)->assertOk();
        $this->evaluar($this->evaluadorA)->assertOk();

        $this->assertDatabaseCount('evaluaciones', 1);
        $this->assertDatabaseCount('evaluacion_detalles', 2);
    }

    // ── Reemplazar un documento tras varios cambios de evaluación en el mismo segundo ──

}
