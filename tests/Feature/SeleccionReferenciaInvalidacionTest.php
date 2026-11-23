<?php

namespace Tests\Feature;

use App\Models\ReferenciaDocumental;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeleccionReferenciaAyudas;
use Tests\TestCase;

class SeleccionReferenciaInvalidacionTest extends TestCase
{
    use RefreshDatabase;
    use SeleccionReferenciaAyudas;

    public function test_una_evaluacion_nueva_que_contradice_a_la_referencia_no_la_invalida(): void
    {
        [$instructor, $convocatoria, $postulacion, $a] = $this->casoContradictorio();
        $this->designar($instructor, $convocatoria, $postulacion, $a)->assertCreated();

        // Otro evaluador finaliza después, con un resultado opuesto al de la referencia (a: 90 % habilitado).
        $this->travel(10)->minutes();
        $nueva = $this->evaluacion($postulacion, 'finalizada', 10, false);

        $fila = $this->fila($instructor, $convocatoria, $postulacion);
        $this->assertSame(['habilitado', 90], [$fila['estado_documental'], $fila['cumplimiento']]);

        $datos = $this->actingAs($instructor)->getJson($this->url($convocatoria, $postulacion))->json('data');
        $this->assertTrue($datos['referencia']['vigente']);
        $this->assertSame($a->id, $datos['referencia']['evaluacion_id']);
        // La nueva evaluación aparece como candidata para una rectificación futura, sin alterar el resultado.
        $this->assertContains($nueva->id, collect($datos['evaluaciones'])->pluck('id')->all());
        $this->assertSame(1, ReferenciaDocumental::count());
    }

    public function test_si_la_referencia_se_reabre_obliga_a_revisar_aunque_quede_otra_finalizada(): void
    {
        [$instructor, $convocatoria, $postulacion, $a, $b] = $this->casoContradictorio();
        $this->designar($instructor, $convocatoria, $postulacion, $a)->assertCreated();

        // El evaluador reabre la evaluación elegida: pasa a en_proceso sin resultado.
        $a->update(['estado' => 'en_proceso', 'pasa_seleccion' => null]);

        $fila = $this->fila($instructor, $convocatoria, $postulacion);
        $this->assertSame(['requiere_revision', null], [$fila['estado_documental'], $fila['cumplimiento']]);

        $datos = $this->actingAs($instructor)->getJson($this->url($convocatoria, $postulacion))->json('data');
        $this->assertFalse($datos['referencia']['vigente']);
        $this->assertSame([$b->id], collect($datos['evaluaciones'])->pluck('id')->all());
    }

    public function test_la_invalidacion_documental_sin_timestamps_tambien_se_detecta(): void
    {
        [$instructor, $convocatoria, $postulacion, $a, $b] = $this->casoContradictorio();
        $this->designar($instructor, $convocatoria, $postulacion, $a)->assertCreated();

        // Igual que PostulacionDocumentoController::invalidarEvaluacionesPorCambioDocumental.
        $a->timestamps = false;
        $a->estado = 'en_proceso';
        $a->pasa_seleccion = null;
        $a->save();

        $this->assertSame('requiere_revision', $this->fila($instructor, $convocatoria, $postulacion)['estado_documental']);
    }

    public function test_si_la_referencia_se_edita_o_se_vuelve_a_finalizar_obliga_a_revisar(): void
    {
        [$instructor, $convocatoria, $postulacion, $a] = $this->casoContradictorio();
        $this->designar($instructor, $convocatoria, $postulacion, $a)->assertCreated();

        // Mismo resultado, pero editada después: no se asume que sigue siendo lo que se revisó.
        // (Aquí el cambio se hace sobre el modelo; en el servidor cada cambio real de la evaluación suma una revisión, que
        // es lo que mide la vigencia. El comportamiento por la API está en EvaluacionReenvioReferenciaTest.)
        $this->travel(5)->minutes();
        $a->update(['observaciones' => 'Ajuste posterior']);
        $a->increment('revision');
        $this->assertSame('requiere_revision', $this->fila($instructor, $convocatoria, $postulacion)['estado_documental']);

        // Cambia el cumplimiento y vuelve a finalizar.
        $this->designar($instructor, $convocatoria, $postulacion, $a->fresh(), 'Reconfirmo tras la edición del evaluador.')->assertCreated();
        $this->assertSame('habilitado', $this->fila($instructor, $convocatoria, $postulacion)['estado_documental']);
        $this->travel(5)->minutes();
        $a->update(['cumplimiento' => 55, 'pasa_seleccion' => false]);
        $a->increment('revision');
        $fila = $this->fila($instructor, $convocatoria, $postulacion);
        $this->assertSame(['requiere_revision', null], [$fila['estado_documental'], $fila['cumplimiento']]);
    }

}
