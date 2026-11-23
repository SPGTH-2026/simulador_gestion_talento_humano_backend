<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\SeleccionDecision;
use App\Models\SeleccionDecisionEvento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeleccionDecisionAyudas;
use Tests\TestCase;

class SeleccionDecisionHistorialTest extends TestCase
{
    use RefreshDatabase;
    use SeleccionDecisionAyudas;

    public function test_un_reenvio_identico_devuelve_lo_existente_sin_duplicar_eventos(): void
    {
        [$seleccionador, $convocatoria, [$a], $version] = $this->escenario(1, 1);
        $this->decidir($seleccionador, $convocatoria, $a, 'seleccionado', $version)->assertCreated();

        $this->decidir($seleccionador, $convocatoria, $a, 'seleccionado', $version)->assertOk()
            ->assertJsonPath('data.estado', 'seleccionado')->assertJsonPath('data.cupos.cupos_ocupados', 1);
        // Idéntico aunque la versión enviada ya no coincida.
        $this->decidir($seleccionador, $convocatoria, $a, 'seleccionado', $version + 5)->assertOk();

        $this->assertSame(1, SeleccionDecision::count());
        $this->assertSame(1, SeleccionDecisionEvento::count());
    }

    public function test_el_historial_conserva_anterior_nuevo_actor_motivo_y_version(): void
    {
        [$seleccionador, $convocatoria, [$a], $version] = $this->escenario(1, 1);
        $otro = $this->usuario(Role::Instructor, null, ['ficha_id' => $convocatoria->ficha_id]);

        $this->decidir($seleccionador, $convocatoria, $a, 'seleccionado', $version, 'Primera decisión del comité')->assertCreated();
        $this->decidir($otro, $convocatoria, $a, 'no_seleccionado', $version, 'Se rectifica por incumplir un requisito')->assertOk();
        // Mismo resultado con otro motivo: queda como evento, sin tocar el cupo.
        $this->decidir($otro, $convocatoria, $a, 'no_seleccionado', $version, 'Se amplía el motivo de la rectificación')->assertOk();

        $this->assertSame(1, SeleccionDecision::count());
        $historial = $this->actingAs($seleccionador)->getJson("/api/seleccion/convocatorias/{$convocatoria->id}/decisiones/{$a->id}/historial")->assertOk();
        $historial->assertJsonPath('meta.total', 3)
            ->assertJsonPath('data.0.resultado_nuevo', 'no_seleccionado')
            ->assertJsonPath('data.0.resultado_anterior', 'no_seleccionado')
            ->assertJsonPath('data.1.resultado_anterior', 'seleccionado')
            ->assertJsonPath('data.1.resultado_nuevo', 'no_seleccionado')
            ->assertJsonPath('data.1.registrado_por', $otro->name)
            ->assertJsonPath('data.1.motivo', 'Se rectifica por incumplir un requisito')
            ->assertJsonPath('data.1.lista_version', $version)
            ->assertJsonPath('data.2.resultado_anterior', null)
            ->assertJsonPath('data.2.resultado_nuevo', 'seleccionado');
        $this->assertSame(['resultado_anterior', 'resultado_nuevo', 'motivo', 'lista_version', 'registrado_por', 'registrado_en'], array_keys($historial->json('data.0')));
    }

    public function test_el_historial_se_pagina(): void
    {
        [$seleccionador, $convocatoria, [$a], $version] = $this->escenario(1, 1);
        $this->decidir($seleccionador, $convocatoria, $a, 'seleccionado', $version)->assertCreated();
        $decision = SeleccionDecision::first();
        foreach (range(1, 24) as $i) {
            SeleccionDecisionEvento::create(['decision_id' => $decision->id, 'resultado_anterior' => 'seleccionado', 'resultado_nuevo' => 'seleccionado', 'motivo' => "Evento {$i}", 'lista_version' => $version]);
        }
        $url = "/api/seleccion/convocatorias/{$convocatoria->id}/decisiones/{$a->id}/historial";

        $p1 = $this->actingAs($seleccionador)->getJson($url)->assertOk();
        $p2 = $this->actingAs($seleccionador)->getJson("{$url}?page=2")->assertOk();

        $this->assertCount(20, $p1->json('data'));
        $this->assertCount(5, $p2->json('data'));
        $this->assertSame(['current_page' => 1, 'last_page' => 2, 'per_page' => 20, 'total' => 25], $p1->json('meta'));
    }

    // ── Decisiones que pierden vigencia ────────────────────────────────

}
