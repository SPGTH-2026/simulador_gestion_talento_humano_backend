<?php

namespace Tests\Feature;

use App\Models\Ficha;
use App\Models\SeleccionCalificacion;
use App\Models\SeleccionCalificacionCorreccion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeleccionCalificacionAyudas;
use Tests\TestCase;

class SeleccionCalificacionCorreccionTest extends TestCase
{
    use RefreshDatabase;
    use SeleccionCalificacionAyudas;

    public function test_un_reenvio_identico_no_duplica_ni_corrige(): void
    {
        [$seleccionador, $convocatoria, $postulacion] = $this->escenario();
        $cuerpo = ['nota' => 8.5, 'fecha_realizacion' => '2026-11-10', 'observacion' => 'Puntual'];

        $this->actingAs($seleccionador)->putJson($this->url($convocatoria, $postulacion, 'entrevista'), $cuerpo)->assertCreated();
        $this->actingAs($seleccionador)->putJson($this->url($convocatoria, $postulacion, 'entrevista'), $cuerpo)->assertOk();
        // El mismo valor expresado distinto (8.50) también es idéntico.
        $this->actingAs($seleccionador)->putJson($this->url($convocatoria, $postulacion, 'entrevista'), ['nota' => '8.50'] + $cuerpo)->assertOk();

        $this->assertSame(1, SeleccionCalificacion::count());
        $this->assertSame(0, SeleccionCalificacionCorreccion::count());
    }

    public function test_corregir_exige_motivo_y_conserva_el_historial(): void
    {
        [$seleccionador, $convocatoria, $postulacion] = $this->escenario();
        $segundo = $this->seleccionador(Ficha::first());
        $this->actingAs($seleccionador)->putJson($this->url($convocatoria, $postulacion, 'entrevista'), ['nota' => 6, 'fecha_realizacion' => '2026-11-09', 'observacion' => 'Primera'])->assertCreated();

        // Sin motivo no se corrige.
        $this->actingAs($seleccionador)->putJson($this->url($convocatoria, $postulacion, 'entrevista'), ['nota' => 7.5, 'fecha_realizacion' => '2026-11-09'])
            ->assertUnprocessable()->assertJsonValidationErrors('motivo');
        $this->assertSame('6.00', SeleccionCalificacion::first()->nota);

        // Con motivo, queda el valor anterior y el nuevo.
        $this->actingAs($segundo)->putJson($this->url($convocatoria, $postulacion, 'entrevista'), [
            'nota' => 7.5, 'fecha_realizacion' => '2026-11-10', 'observacion' => 'Revisada', 'motivo' => 'Error de digitación en la nota',
        ])->assertOk()
            ->assertJsonPath('data.actividades.entrevista.calificacion.nota', '7.50')
            ->assertJsonPath('data.actividades.entrevista.calificacion.registrada_por', $seleccionador->name)
            ->assertJsonPath('data.actividades.entrevista.calificacion.correcciones.0.nota_anterior', '6.00')
            ->assertJsonPath('data.actividades.entrevista.calificacion.correcciones.0.nota_nueva', '7.50')
            ->assertJsonPath('data.actividades.entrevista.calificacion.correcciones.0.corregida_por', $segundo->name);

        // Una segunda corrección conserva ambas.
        $this->actingAs($seleccionador)->putJson($this->url($convocatoria, $postulacion, 'entrevista'), [
            'nota' => 0, 'fecha_realizacion' => '2026-11-10', 'observacion' => 'Revisada', 'motivo' => 'Se anula por irregularidad',
        ])->assertOk();

        $this->assertSame(1, SeleccionCalificacion::count());
        $correcciones = SeleccionCalificacionCorreccion::orderBy('id')->get();
        $this->assertSame(['6.00', '7.50'], $correcciones->pluck('nota_anterior')->all());
        $this->assertSame(['7.50', '0.00'], $correcciones->pluck('nota_nueva')->all());
        $this->assertSame('Primera', $correcciones[0]->observacion_anterior);
        $this->assertSame('0.00', SeleccionCalificacion::first()->nota);
    }

    // ── Resultado adicional ────────────────────────────────────────────

    public function test_distingue_pendiente_de_configurar_de_sin_actividades(): void
    {
        $ficha = $this->ficha('2896411');
        $seleccionador = $this->seleccionador($ficha);

        $sinConfig = $this->convocatoria($ficha);
        $p1 = $this->candidatoHabilitado($sinConfig);
        $this->actingAs($seleccionador)->getJson($this->url($sinConfig, $p1))->assertOk()
            ->assertJsonPath('data.resultado_adicional', ['estado' => 'pendiente_de_configurar', 'valor' => null])
            ->assertJsonPath('data.configuracion.configurada', false);

        $sinActividades = $this->convocatoria($ficha);
        $this->configurar($sinActividades, false, false);
        $p2 = $this->candidatoHabilitado($sinActividades);
        $this->actingAs($seleccionador)->getJson($this->url($sinActividades, $p2))->assertOk()
            ->assertJsonPath('data.resultado_adicional', ['estado' => 'sin_actividades', 'valor' => null])
            ->assertJsonPath('data.progreso', ['requeridas' => 0, 'registradas' => 0, 'pendientes' => [], 'completo' => false])
            ->assertJsonPath('data.actividades.entrevista.estado', 'desactivada');
    }

}
