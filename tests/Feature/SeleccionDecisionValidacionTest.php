<?php

namespace Tests\Feature;

use App\Models\Convocatoria;
use App\Models\Evaluacion;
use App\Models\SeleccionConfiguracion;
use App\Models\SeleccionDecision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeleccionDecisionAyudas;
use Tests\TestCase;

class SeleccionDecisionValidacionTest extends TestCase
{
    use RefreshDatabase;
    use SeleccionDecisionAyudas;

    public function test_solo_con_la_convocatoria_en_proceso(): void
    {
        [$seleccionador, $convocatoria, [$a], $version] = $this->escenario(1, 1);
        $convocatoria->forceFill(['estado_id' => $this->parametroId('estado_convocatoria', 'cerrada')])->save();

        $this->decidir($seleccionador, $convocatoria, $a, 'seleccionado', $version)->assertUnprocessable()->assertJsonValidationErrors('convocatoria');
        $this->assertSame(0, SeleccionDecision::count());
        // Consultar sigue siendo posible.
        $this->decisiones($seleccionador, $convocatoria)->assertOk()->assertJsonPath('meta.estado_convocatoria', 'cerrada');
    }

    public function test_sin_lista_confirmada_da_conflicto(): void
    {
        $ficha = $this->ficha();
        $convocatoria = $this->convocatoria($ficha, 1);
        SeleccionConfiguracion::create(['convocatoria_id' => $convocatoria->id, 'entrevista_activa' => true, 'prueba_tecnica_activa' => true, 'peso_entrevista' => 50, 'peso_prueba_tecnica' => 50]);
        $seleccionador = $this->seleccionador($ficha);
        $a = $this->candidato($convocatoria);

        $this->decidir($seleccionador, $convocatoria, $a, 'seleccionado', 1)->assertStatus(409)->assertJsonPath('codigo', 'lista_no_confirmada');
        $this->decisiones($seleccionador, $convocatoria)->assertOk()->assertJsonPath('meta.lista', null)->assertJsonPath('data', []);
        $this->assertSame(0, SeleccionDecision::count());
    }

    public function test_una_lista_reemplazada_da_conflicto_con_la_version_actual(): void
    {
        [$seleccionador, $convocatoria, [$a, $b], $version] = $this->escenario(2, 2);
        $nueva = $this->confirmarLista($seleccionador, $convocatoria, [$a]);
        $this->assertSame($version + 1, $nueva);

        $this->decidir($seleccionador, $convocatoria, $a, 'seleccionado', $version)
            ->assertStatus(409)->assertJsonPath('codigo', 'lista_reemplazada')->assertJsonPath('lista_version_actual', $nueva);
        $this->decidir($seleccionador, $convocatoria, $a, 'seleccionado', $nueva)->assertCreated();
    }

    public function test_un_candidato_fuera_de_la_lista_no_puede_decidirse(): void
    {
        [$seleccionador, $convocatoria, [$a], $version] = $this->escenario(2, 1);
        // Listo (habilitado y con todas sus notas) pero que no entró a la lista de finalistas confirmada.
        $sinLista = $this->candidato($convocatoria, '7');

        $this->decidir($seleccionador, $convocatoria, $sinLista, 'seleccionado', $version)->assertUnprocessable()->assertJsonValidationErrors('postulacion');
        $this->assertSame(0, SeleccionDecision::count());
        // Los finalistas de la lista sí pueden decidirse.
        $this->decidir($seleccionador, $convocatoria, $a, 'seleccionado', $version)->assertCreated();
    }

    public function test_la_perdida_de_habilitacion_de_un_finalista_exige_revisar_antes_de_decidir(): void
    {
        [$seleccionador, $convocatoria, [$a, $b], $version] = $this->escenario(2, 2);
        Evaluacion::where('postulacion_id', $b->id)->update(['estado' => 'en_proceso', 'pasa_seleccion' => null]);

        $this->decidir($seleccionador, $convocatoria, $a, 'seleccionado', $version)
            ->assertStatus(409)->assertJsonPath('codigo', 'lista_requiere_revision')
            ->assertJsonPath('revisar.0.postulacion_id', $b->id)->assertJsonPath('revisar.0.motivo', 'ya_no_elegible');
        $this->decisiones($seleccionador, $convocatoria)->assertJsonPath('meta.lista.requiere_revision', true);
        $this->assertSame(0, SeleccionDecision::count());
    }

    // ── Cupos ──────────────────────────────────────────────────────────

    public function test_se_selecciona_hasta_el_tope_y_se_rechaza_el_excedente(): void
    {
        [$seleccionador, $convocatoria, [$a, $b, $c], $version] = $this->escenario(2, 3);

        $this->decidir($seleccionador, $convocatoria, $a, 'seleccionado', $version)->assertCreated()
            ->assertJsonPath('data.cupos', ['vacantes_totales' => 2, 'cupos_ocupados' => 1, 'cupos_disponibles' => 1])
            ->assertJsonPath('data.estado', 'seleccionado')
            ->assertJsonPath('data.decision.decidida_por', $seleccionador->name)
            ->assertJsonPath('data.decision.lista_version', $version);
        $this->decidir($seleccionador, $convocatoria, $b, 'seleccionado', $version)->assertCreated()
            ->assertJsonPath('data.cupos.cupos_disponibles', 0);

        // El tercer cupo no existe: conflicto con el saldo actualizado.
        $this->decidir($seleccionador, $convocatoria, $c, 'seleccionado', $version)
            ->assertStatus(409)->assertJsonPath('codigo', 'sin_cupos')
            ->assertJsonPath('cupos', ['vacantes_totales' => 2, 'cupos_ocupados' => 2, 'cupos_disponibles' => 0]);
        // No seleccionar no consume cupo y sí se puede aunque no queden.
        $this->decidir($seleccionador, $convocatoria, $c, 'no_seleccionado', $version)->assertCreated()
            ->assertJsonPath('data.cupos.cupos_ocupados', 2);

        $this->assertSame(2, (int) Convocatoria::find($convocatoria->id)->vacantes); // el total original no cambia
        $this->assertSame(3, SeleccionDecision::count());
    }

    public function test_rectificar_a_no_seleccionado_libera_el_cupo(): void
    {
        [$seleccionador, $convocatoria, [$a, $b], $version] = $this->escenario(1, 2);
        $this->decidir($seleccionador, $convocatoria, $a, 'seleccionado', $version)->assertCreated();
        $this->decidir($seleccionador, $convocatoria, $b, 'seleccionado', $version)->assertStatus(409)->assertJsonPath('codigo', 'sin_cupos');

        $this->decidir($seleccionador, $convocatoria, $a, 'no_seleccionado', $version, 'Rectifico: no cumplió el perfil final')->assertOk()
            ->assertJsonPath('data.estado', 'no_seleccionado')->assertJsonPath('data.cupos.cupos_disponibles', 1);
        $this->decidir($seleccionador, $convocatoria, $b, 'seleccionado', $version)->assertCreated()
            ->assertJsonPath('data.cupos', ['vacantes_totales' => 1, 'cupos_ocupados' => 1, 'cupos_disponibles' => 0]);

        // Volver a seleccionar a quien se liberó consume cupo otra vez, y ya no queda.
        $this->decidir($seleccionador, $convocatoria, $a, 'seleccionado', $version, 'Reconsiderado por el comité')->assertStatus(409)->assertJsonPath('codigo', 'sin_cupos');
    }

    // ── Historial y reenvío ────────────────────────────────────────────

}
