<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\SeleccionConfiguracion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeleccionConfiguracionAyudas;
use Tests\TestCase;

class SeleccionConfiguracionPesosTest extends TestCase
{
    use RefreshDatabase;
    use SeleccionConfiguracionAyudas;

    public function test_pueden_configurar_seleccionador_aprendiz_general_instructor_y_superadmin(): void
    {
        $ficha = $this->ficha('2896411');
        $convocatoria = $this->convocatoria($ficha);
        $cuerpo = ['entrevista_activa' => true, 'prueba_tecnica_activa' => false];

        $actores = [
            $this->seleccionador($ficha),
            $this->usuario(Role::Aprendiz, 'general', ['ficha_id' => $ficha->id]),
            $this->usuario(Role::Instructor, null, ['ficha_id' => $ficha->id]),
            $this->usuario(Role::SuperAdmin),
        ];

        foreach ($actores as $actor) {
            $this->guardar($actor, $convocatoria, $cuerpo)->assertOk();
            $this->actingAs($actor)->getJson($this->url($convocatoria))->assertOk()->assertJsonPath('data.opcion', 'solo_entrevista');
        }
        $this->assertSame(1, SeleccionConfiguracion::count());
    }

    // ── Pendiente de configurar vs sin actividades ─────────────────────

    public function test_se_distingue_pendiente_de_configurar_de_sin_actividades(): void
    {
        $ficha = $this->ficha('2896411');
        $convocatoria = $this->convocatoria($ficha);
        $seleccionador = $this->seleccionador($ficha);

        $this->actingAs($seleccionador)->getJson($this->url($convocatoria))->assertOk()
            ->assertJsonPath('data.configurada', false)
            ->assertJsonPath('data.opcion', null)
            ->assertJsonPath('data.entrevista', ['activa' => false, 'peso' => null])
            ->assertJsonPath('data.prueba_tecnica', ['activa' => false, 'peso' => null])
            ->assertJsonPath('data.editable', true);

        $this->guardar($seleccionador, $convocatoria, ['entrevista_activa' => false, 'prueba_tecnica_activa' => false])->assertOk();

        $this->actingAs($seleccionador)->getJson($this->url($convocatoria))->assertOk()
            ->assertJsonPath('data.configurada', true)
            ->assertJsonPath('data.opcion', 'sin_actividades')
            ->assertJsonPath('data.entrevista.peso', null)
            ->assertJsonPath('data.prueba_tecnica.peso', null)
            ->assertJsonPath('data.actualizada_por', $seleccionador->name);
    }

    // ── Opciones y pesos ───────────────────────────────────────────────

    public function test_las_cuatro_opciones_con_sus_pesos(): void
    {
        $ficha = $this->ficha('2896411');
        $convocatoria = $this->convocatoria($ficha);
        $actor = $this->seleccionador($ficha);

        $casos = [
            'sin_actividades' => [[false, false], [null, null]],
            'solo_entrevista' => [[true, false], [100, null]],
            'solo_prueba_tecnica' => [[false, true], [null, 100]],
            'ambas' => [[true, true], [50, 50]],
        ];

        foreach ($casos as $opcion => [[$e, $p], [$pesoE, $pesoP]]) {
            $this->guardar($actor, $convocatoria, ['entrevista_activa' => $e, 'prueba_tecnica_activa' => $p])
                ->assertOk()
                ->assertJsonPath('data.opcion', $opcion)
                ->assertJsonPath('data.entrevista', ['activa' => $e, 'peso' => $pesoE])
                ->assertJsonPath('data.prueba_tecnica', ['activa' => $p, 'peso' => $pesoP]);

            $fila = SeleccionConfiguracion::first();
            $this->assertSame([$pesoE, $pesoP], [$fila->peso_entrevista, $fila->peso_prueba_tecnica]);
        }
        $this->assertSame(1, SeleccionConfiguracion::count());
    }

    public function test_ponderacion_personalizada_y_peso_100_explicito(): void
    {
        $ficha = $this->ficha('2896411');
        $convocatoria = $this->convocatoria($ficha);
        $actor = $this->seleccionador($ficha);

        $this->guardar($actor, $convocatoria, ['entrevista_activa' => true, 'prueba_tecnica_activa' => true, 'peso_entrevista' => 40, 'peso_prueba_tecnica' => 60])
            ->assertOk()
            ->assertJsonPath('data.entrevista.peso', 40)
            ->assertJsonPath('data.prueba_tecnica.peso', 60);

        $this->guardar($actor, $convocatoria, ['entrevista_activa' => false, 'prueba_tecnica_activa' => true, 'peso_prueba_tecnica' => 100, 'peso_entrevista' => null])
            ->assertOk()
            ->assertJsonPath('data.prueba_tecnica.peso', 100)
            ->assertJsonPath('data.entrevista.peso', null);
    }

    public function test_pesos_invalidos_se_rechazan_sin_guardar(): void
    {
        $ficha = $this->ficha('2896411');
        $convocatoria = $this->convocatoria($ficha);
        $actor = $this->seleccionador($ficha);
        $ambas = ['entrevista_activa' => true, 'prueba_tecnica_activa' => true];

        // Un solo peso con ambas activas.
        $this->guardar($actor, $convocatoria, $ambas + ['peso_entrevista' => 40])->assertUnprocessable()->assertJsonValidationErrors('peso_entrevista');
        $this->guardar($actor, $convocatoria, $ambas + ['peso_prueba_tecnica' => 60])->assertUnprocessable()->assertJsonValidationErrors('peso_entrevista');
        // Suma distinta de 100.
        $this->guardar($actor, $convocatoria, $ambas + ['peso_entrevista' => 40, 'peso_prueba_tecnica' => 50])->assertUnprocessable()->assertJsonValidationErrors('peso_entrevista');
        $this->guardar($actor, $convocatoria, $ambas + ['peso_entrevista' => 60, 'peso_prueba_tecnica' => 60])->assertUnprocessable();
        // No positivos, fuera de rango o no enteros.
        $this->guardar($actor, $convocatoria, $ambas + ['peso_entrevista' => 0, 'peso_prueba_tecnica' => 100])->assertUnprocessable()->assertJsonValidationErrors('peso_entrevista');
        $this->guardar($actor, $convocatoria, $ambas + ['peso_entrevista' => 101, 'peso_prueba_tecnica' => -1])->assertUnprocessable();
        $this->guardar($actor, $convocatoria, $ambas + ['peso_entrevista' => 50.5, 'peso_prueba_tecnica' => 49.5])->assertUnprocessable();
        // Una sola actividad con un peso distinto de 100.
        $this->guardar($actor, $convocatoria, ['entrevista_activa' => true, 'prueba_tecnica_activa' => false, 'peso_entrevista' => 60])
            ->assertUnprocessable()->assertJsonValidationErrors('peso_entrevista');
        // Peso asignado a una actividad desactivada.
        $this->guardar($actor, $convocatoria, ['entrevista_activa' => true, 'prueba_tecnica_activa' => false, 'peso_prueba_tecnica' => 40])
            ->assertUnprocessable()->assertJsonValidationErrors('peso_prueba_tecnica');
        $this->guardar($actor, $convocatoria, ['entrevista_activa' => false, 'prueba_tecnica_activa' => false, 'peso_entrevista' => 100])
            ->assertUnprocessable()->assertJsonValidationErrors('peso_entrevista');
        // Contrato incompleto.
        $this->guardar($actor, $convocatoria, ['entrevista_activa' => true])->assertUnprocessable()->assertJsonValidationErrors('prueba_tecnica_activa');
        $this->guardar($actor, $convocatoria, [])->assertUnprocessable();

        $this->assertSame(0, SeleccionConfiguracion::count());
    }

}
