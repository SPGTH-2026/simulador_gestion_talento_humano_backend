<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Evaluacion;
use App\Models\Postulacion;
use App\Models\SeleccionCalificacion;
use App\Models\SeleccionConfiguracion;
use App\Models\SeleccionDecision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SeleccionDecisionAyudas;
use Tests\TestCase;

class SeleccionDecisionConsultaTest extends TestCase
{
    use RefreshDatabase;
    use SeleccionDecisionAyudas;

    public function test_no_se_libera_con_la_convocatoria_cerrada(): void
    {
        [$seleccionador, $convocatoria, [$a], $version] = $this->escenario(1, 1);
        $this->decidir($seleccionador, $convocatoria, $a, 'seleccionado', $version)->assertCreated();
        $convocatoria->forceFill(['estado_id' => $this->parametroId('estado_convocatoria', 'cerrada')])->save();

        $this->decidir($seleccionador, $convocatoria, $a, 'no_seleccionado', $version, 'Intento de rectificar en una cerrada')->assertUnprocessable();
        $this->assertSame('seleccionado', SeleccionDecision::first()->resultado);
    }

    // ── Rendimiento ────────────────────────────────────────────────────

    public function test_la_consulta_paginada_no_crece_con_los_candidatos(): void
    {
        [$seleccionador, $convocatoria, $personas, $version] = $this->escenario(40, 3);
        $this->decidir($seleccionador, $convocatoria, $personas[0], 'seleccionado', $version)->assertCreated();

        $consultas = function () use ($seleccionador, $convocatoria): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->decisiones($seleccionador, $convocatoria)->assertOk();
            $total = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $total;
        };

        $conTres = $consultas();
        $mas = array_map(fn (int $i) => $this->candidato($convocatoria, '5'), range(1, 22));
        $version = $this->confirmarLista($seleccionador, $convocatoria, array_merge($personas, $mas));
        foreach (array_slice($mas, 0, 5) as $p) {
            $this->decidir($seleccionador, $convocatoria, $p, 'no_seleccionado', $version)->assertCreated();
        }
        $conVeinticinco = $consultas();

        $this->assertSame($conTres, $conVeinticinco);
        $this->decisiones($seleccionador, $convocatoria)->assertJsonPath('meta.total', 25)->assertJsonPath('meta.last_page', 2);
    }

    // ── Evidencia de cada fila: lo confirmado, lo actual y lo que sustentó la decisión ──────────────

    public function test_cada_fila_trae_lo_confirmado_lo_actual_y_el_sustento_de_su_decision(): void
    {
        [$seleccionador, $convocatoria, [$a, $b], $version] = $this->escenario(1, 2);
        $this->decidir($seleccionador, $convocatoria, $a, 'seleccionado', $version)->assertCreated();

        $respuesta = $this->decisiones($seleccionador, $convocatoria)->assertOk();
        $respuesta->assertJsonPath('meta.lista.modo', 'con_actividades')
            ->assertJsonPath('meta.lista.confirmada_por', $seleccionador->name);
        $this->assertNotNull($respuesta->json('meta.lista.confirmada_en'));

        $fila = $this->fila($seleccionador, $convocatoria, $a);
        $this->assertSame(['lista_version' => $version, 'posicion' => 1, 'resultado' => '10.00'], $fila['confirmada']);
        $this->assertSame(1, $fila['actual']['posicion']);
        $this->assertSame('10.00', $fila['actual']['resultado']);
        $this->assertSame('habilitado', $fila['actual']['estado_documental']);
        $this->assertSame(85, $fila['actual']['cumplimiento_documental']);
        $this->assertTrue($fila['actual']['listo']);
        $this->assertSame(['lista_version' => $version, 'pertenece' => true, 'posicion' => 1, 'resultado' => '10.00'], collect($fila['sustento'])->except('confirmada_en')->all());
        $this->assertNotNull($fila['sustento']['confirmada_en']);

        // Sin decisión no hay sustento, pero sí lo confirmado y lo actual.
        $sinDecision = $this->fila($seleccionador, $convocatoria, $b);
        $this->assertNull($sinDecision['sustento']);
        $this->assertSame(2, $sinDecision['confirmada']['posicion']);
        $this->assertSame('9.00', $sinDecision['actual']['resultado']);
    }

    public function test_lo_actual_cambia_pero_lo_confirmado_se_conserva(): void
    {
        [$seleccionador, $convocatoria, [$a, $b], $version] = $this->escenario(2, 2);
        $this->decidir($seleccionador, $convocatoria, $a, 'seleccionado', $version)->assertCreated();

        // Después de confirmar, una nota baja y el candidato pasa de 10 a 6 (tras ambas actividades).
        SeleccionCalificacion::where('postulacion_id', $a->id)->update(['nota' => '6']);

        $fila = $this->fila($seleccionador, $convocatoria, $a);
        $this->assertSame('10.00', $fila['confirmada']['resultado']);   // lo confirmado no se toca
        $this->assertSame(1, $fila['confirmada']['posicion']);
        $this->assertSame('6.00', $fila['actual']['resultado']);        // lo actual sí cambia
        $this->assertSame(2, $fila['actual']['posicion']);              // ahora b (9) lo supera
        $this->assertSame('cambio_resultado', $fila['motivo_revision']);
        $this->assertSame('10.00', $fila['sustento']['resultado']);     // el sustento sigue siendo el de la versión registrada
        $this->decisiones($seleccionador, $convocatoria)->assertJsonPath('meta.lista.requiere_revision', true);
    }

    public function test_un_candidato_fuera_de_la_lista_conserva_el_sustento_de_la_version_de_su_decision(): void
    {
        [$seleccionador, $convocatoria, [$a, $b], $v1] = $this->escenario(1, 2);
        $this->decidir($seleccionador, $convocatoria, $a, 'seleccionado', $v1)->assertCreated();
        $v2 = $this->confirmarLista($seleccionador, $convocatoria, [$b]);

        $fila = $this->fila($seleccionador, $convocatoria, $a);
        $this->assertFalse($fila['en_lista']);
        $this->assertNull($fila['confirmada']);                          // la lista vigente (v2) no lo contiene
        $this->assertSame($v1, $fila['sustento']['lista_version']);      // su decisión se tomó con la v1, que sí lo contiene
        $this->assertTrue($fila['sustento']['pertenece']);
        $this->assertSame(1, $fila['sustento']['posicion']);
        $this->assertSame('10.00', $fila['sustento']['resultado']);
        $this->assertSame(1, $fila['actual']['posicion']);               // hoy sigue siendo candidato
        $this->assertNotSame($v2, $fila['sustento']['lista_version']);
    }

    public function test_al_liberar_se_registra_la_version_vigente_aunque_no_incluya_al_candidato(): void
    {
        [$seleccionador, $convocatoria, [$a, $b], $v1] = $this->escenario(1, 2);
        $this->decidir($seleccionador, $convocatoria, $a, 'seleccionado', $v1)->assertCreated();
        $v2 = $this->confirmarLista($seleccionador, $convocatoria, [$b]);

        // La lista v2 además pasa a requerir revisión: aun así se puede liberar el cupo.
        SeleccionCalificacion::where('postulacion_id', $b->id)->update(['nota' => '4']);
        $this->decisiones($seleccionador, $convocatoria)->assertJsonPath('meta.lista.requiere_revision', true);
        $this->decidir($seleccionador, $convocatoria, $a, 'no_seleccionado', null, 'Ya no figura entre los finalistas; se libera el cupo')->assertOk();

        // La decisión quedó sustentada por la v2 (la vigente al liberar), que no tiene fila para este candidato.
        $fila = $this->fila($seleccionador, $convocatoria, $a);
        $this->assertSame($v2, $fila['sustento']['lista_version']);
        $this->assertFalse($fila['sustento']['pertenece']);
        $this->assertNull($fila['sustento']['posicion']);
        $this->assertNull($fila['sustento']['resultado']);
        $this->assertNotNull($fila['sustento']['confirmada_en']);        // la versión existe; solo que no contiene al candidato
        $this->assertNotNull($fila['actual']);                           // su situación de hoy sí se informa
        $this->assertSame('fuera_de_lista', $fila['motivo_revision']);
    }

    public function test_solo_documental_no_trae_posicion_ni_resultado_pero_si_los_datos_documentales(): void
    {
        $ficha = $this->ficha();
        $convocatoria = $this->convocatoria($ficha, 1);
        SeleccionConfiguracion::create(['convocatoria_id' => $convocatoria->id, 'entrevista_activa' => false, 'prueba_tecnica_activa' => false]);
        $seleccionador = $this->seleccionador($ficha);
        $evaluador = $this->usuario(Role::Aprendiz, 'evaluador', ['ficha_id' => $ficha->id]);
        $personas = array_map(function () use ($convocatoria, $evaluador) {
            $p = $this->postulacion($convocatoria);
            Evaluacion::create(['postulacion_id' => $p->id, 'evaluador_id' => $evaluador->id, 'estado' => 'finalizada', 'cumplimiento' => 92, 'pasa_seleccion' => true]);

            return $p;
        }, [1, 2]);
        $version = $this->confirmarLista($seleccionador, $convocatoria, $personas);
        $this->decidir($seleccionador, $convocatoria, $personas[0], 'seleccionado', $version)->assertCreated();

        $fila = $this->fila($seleccionador, $convocatoria, $personas[0]);
        $this->assertSame(['lista_version' => $version, 'posicion' => null, 'resultado' => null], $fila['confirmada']);
        $this->assertNull($fila['actual']['posicion']);
        $this->assertNull($fila['actual']['resultado']);
        $this->assertSame('habilitado', $fila['actual']['estado_documental']);
        $this->assertSame(92, $fila['actual']['cumplimiento_documental']);
        $this->assertTrue($fila['actual']['listo']);
        $this->assertTrue($fila['sustento']['pertenece']);
        $this->assertNull($fila['sustento']['posicion']);
        $this->assertNull($fila['sustento']['resultado']);
        $this->decisiones($seleccionador, $convocatoria)->assertJsonPath('meta.lista.modo', 'sin_actividades');
    }

    public function test_un_candidato_que_dejo_de_estar_postulado_conserva_el_sustento_pero_no_tiene_situacion_actual(): void
    {
        [$seleccionador, $convocatoria, [$a, $b], $version] = $this->escenario(2, 2);
        $this->decidir($seleccionador, $convocatoria, $a, 'seleccionado', $version)->assertCreated();
        Postulacion::whereKey($a->id)->update(['estado_id' => $this->parametroId('estado_postulacion', 'retirada')]);

        $fila = $this->fila($seleccionador, $convocatoria, $a);
        $this->assertNull($fila['actual']);
        $this->assertTrue($fila['sustento']['pertenece']);
        $this->assertSame('10.00', $fila['sustento']['resultado']);
        $this->assertSame('ya_no_elegible', $fila['motivo_revision']);
    }

}
