<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Evaluacion;
use App\Models\SeleccionFinalistaLista;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SeleccionRankingAyudas;
use Tests\TestCase;

class SeleccionRankingHistorialYConflictosTest extends TestCase
{
    use RefreshDatabase;
    use SeleccionRankingAyudas;

    public function test_el_historial_de_versiones_se_pagina_y_la_lista_vigente_no_depende_de_la_pagina(): void
    {
        [$seleccionador, $convocatoria] = $this->escenarioConNotas(['9', '8'], 1);
        foreach (range(1, 25) as $version) {
            SeleccionFinalistaLista::create([
                'convocatoria_id' => $convocatoria->id,
                'version' => $version,
                'modo' => 'con_actividades',
                'objetivo' => 2,
            ]);
        }
        $url = "/api/seleccion/convocatorias/{$convocatoria->id}/finalistas";

        $pagina1 = $this->actingAs($seleccionador)->getJson($url)->assertOk();
        $pagina2 = $this->actingAs($seleccionador)->getJson("{$url}?page=2")->assertOk();

        $this->assertSame(range(25, 6), collect($pagina1->json('data.versiones'))->pluck('version')->all());
        $this->assertSame(range(5, 1), collect($pagina2->json('data.versiones'))->pluck('version')->all());
        $this->assertSame(['current_page' => 1, 'last_page' => 2, 'per_page' => 20, 'total' => 25], $pagina1->json('meta'));
        $this->assertSame(2, $pagina2->json('meta.current_page'));

        // `actual` es siempre la última confirmada (25), pida la página que se pida.
        $this->assertSame(25, $pagina1->json('data.actual.version'));
        $this->assertSame(25, $pagina2->json('data.actual.version'));
    }

    // ── Vigencia ───────────────────────────────────────────────────────

    public function test_una_nota_corregida_entre_la_consulta_y_la_confirmacion_da_conflicto(): void
    {
        [$seleccionador, $convocatoria, $c] = $this->escenarioConNotas(['9', '8', '7', '6'], 2);
        $version = $this->ranking($seleccionador, $convocatoria)->json('meta.version');

        // Otra persona corrige una nota mientras tanto (por la API real de calificaciones).
        $this->actingAs($seleccionador)->putJson("/api/seleccion/convocatorias/{$convocatoria->id}/candidatos/{$c[3]->id}/calificaciones/entrevista", [
            'nota' => 9.5, 'fecha_realizacion' => '2026-11-10', 'motivo' => 'Se recalificó tras la revisión',
        ])->assertOk();

        $this->confirmar($seleccionador, $convocatoria, $version, [$c[0], $c[1], $c[2], $c[3]])
            ->assertStatus(409)->assertJsonPath('codigo', 'ranking_desactualizado');
        $this->assertSame(0, SeleccionFinalistaLista::count());

        // Con la versión nueva sí se puede confirmar.
        $nueva = $this->ranking($seleccionador, $convocatoria)->json('meta.version');
        $this->assertNotSame($version, $nueva);
        $this->confirmar($seleccionador, $convocatoria, $nueva, [$c[0], $c[1], $c[2], $c[3]])->assertCreated();
    }

    public function test_una_referencia_reabierta_entre_la_consulta_y_la_confirmacion_da_conflicto(): void
    {
        $ficha = $this->ficha();
        $convocatoria = $this->convocatoria($ficha, 'en_proceso', 1);
        $this->configurar($convocatoria, true, false);
        $seleccionador = $this->seleccionador($ficha);
        $instructor = $this->usuario(Role::Instructor, null, ['ficha_id' => $ficha->id]);

        $postulacion = $this->postulacion($convocatoria);
        $elegida = $this->evaluacion($postulacion, 'finalizada', 90, true);
        $this->evaluacion($postulacion, 'finalizada', 20, false, true);
        $this->actingAs($instructor)->postJson("/api/seleccion/convocatorias/{$convocatoria->id}/candidatos/{$postulacion->id}/referencia-documental", [
            'evaluacion_id' => $elegida->id,
            'justificacion' => 'La primera evaluación tiene el soporte completo.',
        ])->assertCreated();
        $this->nota($postulacion, 'entrevista', '8');

        $version = $this->ranking($seleccionador, $convocatoria)->assertJsonPath('meta.total', 1)->json('meta.version');

        // La evaluación de referencia se reabre antes de confirmar.
        $elegida->update(['estado' => 'en_proceso', 'pasa_seleccion' => null]);

        $this->confirmar($seleccionador, $convocatoria, $version, [$postulacion], 'Confirmo con la información que tenía')
            ->assertStatus(409)->assertJsonPath('codigo', 'ranking_desactualizado');
        $this->assertSame(0, SeleccionFinalistaLista::count());
    }

    public function test_si_cambia_algo_despues_la_lista_se_conserva_y_se_marca_para_revision(): void
    {
        [$seleccionador, $convocatoria, $c] = $this->escenarioConNotas(['9', '8', '7', '6'], 2);
        $version = $this->ranking($seleccionador, $convocatoria)->json('meta.version');
        $this->confirmar($seleccionador, $convocatoria, $version, [$c[0], $c[1], $c[2], $c[3]])->assertCreated();
        $urlFinalistas = "/api/seleccion/convocatorias/{$convocatoria->id}/finalistas";

        // Una nota cambia y otro finalista pierde la habilitación.
        $this->actingAs($seleccionador)->putJson("/api/seleccion/convocatorias/{$convocatoria->id}/candidatos/{$c[1]->id}/calificaciones/entrevista", [
            'nota' => 2, 'fecha_realizacion' => '2026-11-10', 'motivo' => 'Se anula por una irregularidad',
        ])->assertOk();
        Evaluacion::where('postulacion_id', $c[3]->id)->update(['estado' => 'en_proceso', 'pasa_seleccion' => null]);

        $actual = $this->actingAs($seleccionador)->getJson($urlFinalistas)->assertOk()->json('data.actual');
        $this->assertTrue($actual['requiere_revision']);
        $this->assertEqualsCanonicalizing(
            [['postulacion_id' => $c[1]->id, 'motivo' => 'cambio_resultado'], ['postulacion_id' => $c[3]->id, 'motivo' => 'ya_no_elegible']],
            $actual['revisar'],
        );
        // La lista registrada no se tocó: sigue con sus cuatro finalistas y sus resultados originales.
        $this->assertCount(4, $actual['finalistas']);
        $this->assertSame('8.00', collect($actual['finalistas'])->firstWhere('postulacion_id', $c[1]->id)['resultado_adicional']);
        $this->ranking($seleccionador, $convocatoria)->assertJsonPath('meta.lista_confirmada.requiere_revision', true);

        // Reconfirmar el mismo conjunto con el cambio ya revisado crea una versión nueva.
        $nueva = $this->ranking($seleccionador, $convocatoria)->json('meta.version');
        $this->confirmar($seleccionador, $convocatoria, $nueva, [$c[0], $c[1], $c[2]], 'Se retira a quien perdió la habilitación')
            ->assertCreated()->assertJsonPath('data.version', 2)->assertJsonPath('data.requiere_revision', false);
        $this->assertSame(2, SeleccionFinalistaLista::count());
    }

    // ── Rendimiento ────────────────────────────────────────────────────

    public function test_las_consultas_del_ranking_no_crecen_con_los_candidatos(): void
    {
        $ficha = $this->ficha();
        $convocatoria = $this->convocatoria($ficha);
        $this->configurar($convocatoria);
        $seleccionador = $this->seleccionador($ficha);

        $consultas = function () use ($seleccionador, $convocatoria): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->ranking($seleccionador, $convocatoria)->assertOk();
            $total = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $total;
        };

        foreach (range(1, 3) as $i) {
            $this->candidato($convocatoria, (string) $i, (string) $i);
        }
        $conTres = $consultas();
        foreach (range(4, 25) as $i) {
            $this->candidato($convocatoria, (string) ($i % 10), '5');
        }
        $conVeinticinco = $consultas();

        $this->assertSame($conTres, $conVeinticinco);
    }

}
