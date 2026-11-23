<?php

namespace Tests\Feature;

use App\Models\Evaluacion;
use App\Models\SeleccionDecision;
use App\Models\SeleccionDecisionEvento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeleccionDecisionAyudas;
use Tests\TestCase;

class SeleccionDecisionLiberarTest extends TestCase
{
    use RefreshDatabase;
    use SeleccionDecisionAyudas;

    public function test_una_decision_que_pierde_vigencia_sigue_ocupando_cupo_y_se_puede_liberar(): void
    {
        [$seleccionador, $convocatoria, [$a, $b], $version] = $this->escenario(1, 2);
        $this->decidir($seleccionador, $convocatoria, $a, 'seleccionado', $version)->assertCreated();

        // El finalista seleccionado pierde la habilitación documental.
        Evaluacion::where('postulacion_id', $a->id)->update(['estado' => 'en_proceso', 'pasa_seleccion' => null]);

        $respuesta = $this->decisiones($seleccionador, $convocatoria)->assertOk();
        $respuesta->assertJsonPath('meta.cupos.cupos_ocupados', 1)->assertJsonPath('meta.lista.requiere_revision', true);
        $fila = collect($respuesta->json('data'))->firstWhere('postulacion_id', $a->id);
        $this->assertSame('seleccionado', $fila['estado']);
        $this->assertTrue($fila['requiere_revision']);
        $this->assertSame('ya_no_elegible', $fila['motivo_revision']);

        // No se pueden tomar decisiones nuevas mientras la lista requiera revisión, pero liberar sí.
        $this->decidir($seleccionador, $convocatoria, $b, 'seleccionado', $version)->assertStatus(409)->assertJsonPath('codigo', 'lista_requiere_revision');
        $this->decidir($seleccionador, $convocatoria, $a, 'no_seleccionado', $version, 'Pierde la habilitación documental; se libera el cupo')
            ->assertOk()->assertJsonPath('data.cupos', ['vacantes_totales' => 1, 'cupos_ocupados' => 0, 'cupos_disponibles' => 1]);
        $this->assertSame(2, SeleccionDecisionEvento::count());
    }

    public function test_se_puede_liberar_aunque_el_candidato_ya_no_este_en_la_lista_vigente(): void
    {
        [$seleccionador, $convocatoria, [$a, $b], $version] = $this->escenario(1, 2);
        $this->decidir($seleccionador, $convocatoria, $a, 'seleccionado', $version)->assertCreated();

        // Se confirma una lista nueva sin el seleccionado.
        $nueva = $this->confirmarLista($seleccionador, $convocatoria, [$b]);

        $listado = $this->decisiones($seleccionador, $convocatoria)->assertOk();
        $fila = collect($listado->json('data'))->firstWhere('postulacion_id', $a->id);
        $this->assertFalse($fila['en_lista']);
        $this->assertSame('fuera_de_lista', $fila['motivo_revision']);
        $this->assertSame('seleccionado', $fila['estado']);
        $listado->assertJsonPath('meta.cupos.cupos_ocupados', 1);

        // Sin enviar la versión (ni siquiera la vieja) se puede liberar; nunca volver a seleccionarlo.
        $this->decidir($seleccionador, $convocatoria, $a, 'no_seleccionado', null, 'Ya no figura entre los finalistas; se libera el cupo')
            ->assertOk()->assertJsonPath('data.cupos.cupos_disponibles', 1);
        $this->decidir($seleccionador, $convocatoria, $a, 'seleccionado', $nueva)->assertUnprocessable()->assertJsonValidationErrors('postulacion');
        // La liberación quedó trazada con la versión de lista vigente en ese momento.
        $this->assertSame($nueva, SeleccionDecisionEvento::orderByDesc('id')->first()->lista_version);
    }

    public function test_la_liberacion_registra_la_version_del_servidor_y_conserva_la_seleccion_original(): void
    {
        [$seleccionador, $convocatoria, [$a, $b], $v1] = $this->escenario(1, 2);
        $this->decidir($seleccionador, $convocatoria, $a, 'seleccionado', $v1)->assertCreated();

        // La versión 2 ya no incluye al seleccionado.
        $v2 = $this->confirmarLista($seleccionador, $convocatoria, [$b]);
        $this->assertSame($v1 + 1, $v2);

        // Se rectifica enviando la versión antigua (1): el servidor usa la vigente (2).
        $this->decidir($seleccionador, $convocatoria, $a, 'no_seleccionado', $v1, 'Ya no figura entre los finalistas; se libera')
            ->assertOk()->assertJsonPath('data.cupos.cupos_disponibles', 1)->assertJsonPath('data.decision.lista_version', $v2);

        $eventos = SeleccionDecisionEvento::orderBy('id')->get();
        $this->assertCount(2, $eventos);
        $this->assertSame([null, 'seleccionado'], [$eventos[0]->resultado_anterior, $eventos[0]->resultado_nuevo]);
        $this->assertSame($v1, $eventos[0]->lista_version);          // la selección original sigue sustentada por la versión 1
        $this->assertSame(['seleccionado', 'no_seleccionado'], [$eventos[1]->resultado_anterior, $eventos[1]->resultado_nuevo]);
        $this->assertSame($v2, $eventos[1]->lista_version);          // la rectificación, por la vigente (2)

        // Con una versión inexistente ocurre lo mismo: se registra la vigente del servidor.
        [$s2, $conv2, [$c], $w1] = $this->escenarioEnOtraFicha();
        $this->decidir($s2, $conv2, $c, 'seleccionado', $w1)->assertCreated();
        $this->decidir($s2, $conv2, $c, 'no_seleccionado', 999, 'Rectifico con una versión inexistente')->assertOk();
        $this->assertSame($w1, SeleccionDecisionEvento::where('decision_id', SeleccionDecision::where('postulacion_id', $c->id)->value('id'))->orderByDesc('id')->first()->lista_version);
    }

    public function test_editar_el_motivo_de_un_no_seleccionado_sigue_las_validaciones_normales(): void
    {
        [$seleccionador, $convocatoria, [$a, $b], $v1] = $this->escenario(1, 2);
        $this->decidir($seleccionador, $convocatoria, $a, 'no_seleccionado', $v1, 'No cumplió el perfil requerido')->assertCreated();

        // Sin la versión de la lista no es una liberación: se exige, igual que cualquier decisión.
        $this->decidir($seleccionador, $convocatoria, $a, 'no_seleccionado', null, 'Motivo ampliado de la decisión')
            ->assertUnprocessable()->assertJsonValidationErrors('lista_version');
        // Con una versión que ya no es la vigente: conflicto.
        $v2 = $this->confirmarLista($seleccionador, $convocatoria, [$a]);
        $this->assertSame($v1 + 1, $v2);
        $this->decidir($seleccionador, $convocatoria, $a, 'no_seleccionado', $v1, 'Motivo ampliado de la decisión')
            ->assertStatus(409)->assertJsonPath('codigo', 'lista_reemplazada');
        // Con la versión vigente y el candidato en la lista: se edita y queda en el historial.
        $this->decidir($seleccionador, $convocatoria, $a, 'no_seleccionado', $v2, 'Motivo ampliado de la decisión')->assertOk();
        $this->assertSame(2, SeleccionDecisionEvento::count());

        // Si la lista requiere revisión, tampoco se puede editar el motivo.
        Evaluacion::where('postulacion_id', $a->id)->update(['estado' => 'en_proceso', 'pasa_seleccion' => null]);
        $this->decidir($seleccionador, $convocatoria, $a, 'no_seleccionado', $v2, 'Otro motivo distinto del anterior')
            ->assertStatus(409)->assertJsonPath('codigo', 'lista_requiere_revision');
        $this->assertSame(2, SeleccionDecisionEvento::count());
    }

}
