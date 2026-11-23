<?php

namespace Tests\Feature;

use App\Models\ReferenciaDocumental;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SeleccionReferenciaAyudas;
use Tests\TestCase;

class SeleccionReferenciaReconfirmarTest extends TestCase
{
    use RefreshDatabase;
    use SeleccionReferenciaAyudas;

    public function test_reconfirmar_con_una_unica_elegible_y_no_volver_a_la_referencia_anterior(): void
    {
        [$instructor, $convocatoria, $postulacion, $a, $b] = $this->casoContradictorio();
        $this->designar($instructor, $convocatoria, $postulacion, $a)->assertCreated();

        // Se reabre la referencia: queda una sola elegible (b), pero no se usa por descarte.
        $a->update(['estado' => 'en_proceso', 'pasa_seleccion' => null]);
        $this->assertSame('requiere_revision', $this->fila($instructor, $convocatoria, $postulacion)['estado_documental']);

        // Reconfirmar la única elegible sí es posible, porque la referencia quedó invalidada.
        $this->designar($instructor, $convocatoria, $postulacion, $b, 'Confirmo la única evaluación vigente.')->assertCreated();
        $fila = $this->fila($instructor, $convocatoria, $postulacion);
        $this->assertSame(['no_habilitado', 30], [$fila['estado_documental'], $fila['cumplimiento']]);

        // Vigente y única: ya no hace falta (ni se permite) otra designación.
        $this->designar($instructor, $convocatoria, $postulacion, $b)->assertUnprocessable();
        $this->assertSame(2, ReferenciaDocumental::count());
    }

    public function test_si_la_referencia_vigente_se_invalida_no_se_regresa_a_una_anterior(): void
    {
        [$instructor, $convocatoria, $postulacion, $a, $b] = $this->casoContradictorio();
        $this->designar($instructor, $convocatoria, $postulacion, $a)->assertCreated();
        $this->designar($instructor, $convocatoria, $postulacion, $b)->assertCreated();

        // La anterior (a) sigue intacta, pero la vigente es b: si b cambia, no se vuelve a a.
        $b->update(['estado' => 'en_proceso', 'pasa_seleccion' => null]);

        $this->assertSame('requiere_revision', $this->fila($instructor, $convocatoria, $postulacion)['estado_documental']);
    }

    public function test_si_la_evaluacion_se_elimina_el_historial_permanece(): void
    {
        [$instructor, $convocatoria, $postulacion, $a] = $this->casoContradictorio();
        $this->designar($instructor, $convocatoria, $postulacion, $a)->assertCreated();

        $a->delete();

        $evento = ReferenciaDocumental::first();
        $this->assertNotNull($evento);
        $this->assertNull($evento->evaluacion_id);
        $this->assertSame(90, $evento->cumplimiento);
        $this->assertSame('requiere_revision', $this->fila($instructor, $convocatoria, $postulacion)['estado_documental']);
    }

    public function test_el_cero_por_ciento_es_un_dato_valido_y_null_es_ausencia(): void
    {
        $ficha = $this->ficha('2896411');
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->convocatoria($ficha);
        $conCero = $this->postulacion($convocatoria);
        $this->evaluacion($conCero, 'finalizada', 0, false);
        $sinEvaluar = $this->postulacion($convocatoria);

        $this->assertSame(['no_habilitado', 0], [
            $this->fila($instructor, $convocatoria, $conCero)['estado_documental'],
            $this->fila($instructor, $convocatoria, $conCero)['cumplimiento'],
        ]);
        $this->assertNull($this->fila($instructor, $convocatoria, $sinEvaluar)['cumplimiento']);

        // Referencia con 0 %: se conserva el 0, no se convierte en null.
        $ficha2 = $this->ficha('2631620');
        $instructor2 = $this->instructor($ficha2);
        $convocatoria2 = $this->convocatoria($ficha2);
        $postulacion = $this->postulacion($convocatoria2);
        $cero = $this->evaluacion($postulacion, 'finalizada', 0, false);
        $this->evaluacion($postulacion, 'finalizada', 80, true);
        $this->designar($instructor2, $convocatoria2, $postulacion, $cero)->assertCreated();
        $this->assertSame(0, $this->fila($instructor2, $convocatoria2, $postulacion)['cumplimiento']);
    }

    // ── Regresión y rendimiento ────────────────────────────────────────

    public function test_regresion_un_solo_evaluador_sigue_siendo_automatico(): void
    {
        $ficha = $this->ficha('2896411');
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->convocatoria($ficha);
        $pendiente = $this->postulacion($convocatoria);
        $habilitada = $this->postulacion($convocatoria);
        $this->evaluacion($habilitada, 'finalizada', 85, true);
        $noHabilitada = $this->postulacion($convocatoria);
        $this->evaluacion($noHabilitada, 'finalizada', 40, false);
        $multiple = $this->postulacion($convocatoria);
        $this->evaluacion($multiple, 'finalizada', 90, true);
        $this->evaluacion($multiple, 'finalizada', 90, true);

        $this->assertSame(['pendiente', null], array_values(array_intersect_key($this->fila($instructor, $convocatoria, $pendiente), array_flip(['estado_documental', 'cumplimiento']))));
        $this->assertSame(['habilitado', 85], array_values(array_intersect_key($this->fila($instructor, $convocatoria, $habilitada), array_flip(['estado_documental', 'cumplimiento']))));
        $this->assertSame(['no_habilitado', 40], array_values(array_intersect_key($this->fila($instructor, $convocatoria, $noHabilitada), array_flip(['estado_documental', 'cumplimiento']))));
        $this->assertSame(['requiere_revision', null], array_values(array_intersect_key($this->fila($instructor, $convocatoria, $multiple), array_flip(['estado_documental', 'cumplimiento']))));
    }

    public function test_el_numero_de_consultas_del_listado_no_crece_con_candidatos_ni_referencias(): void
    {
        $ficha = $this->ficha('2896411');
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->convocatoria($ficha);

        $crear = function (int $cantidad) use ($instructor, $convocatoria): void {
            foreach (range(1, $cantidad) as $i) {
                $postulacion = $this->postulacion($convocatoria);
                $a = $this->evaluacion($postulacion, 'finalizada', 90, true);
                $this->evaluacion($postulacion, 'finalizada', 30, false);
                $this->designar($instructor, $convocatoria, $postulacion, $a)->assertCreated();
            }
        };

        $consultas = function () use ($instructor, $convocatoria): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($instructor)->getJson("/api/seleccion/convocatorias/{$convocatoria->id}/candidatos")->assertOk();
            $total = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $total;
        };

        $crear(2);
        $conDos = $consultas();
        $crear(15);
        $conDiecisiete = $consultas();

        $this->assertSame($conDos, $conDiecisiete);
    }

}
