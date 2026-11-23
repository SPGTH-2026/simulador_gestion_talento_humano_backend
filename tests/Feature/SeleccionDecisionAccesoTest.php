<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Ficha;
use App\Models\SeleccionDecision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeleccionDecisionAyudas;
use Tests\TestCase;

class SeleccionDecisionAccesoTest extends TestCase
{
    use RefreshDatabase;
    use SeleccionDecisionAyudas;

    public function test_invitado_recibe_401_en_las_tres_rutas(): void
    {
        [, $convocatoria, [$p]] = $this->escenario(1, 1);
        $base = "/api/seleccion/convocatorias/{$convocatoria->id}/decisiones";
        $this->app['auth']->forgetGuards(); // el escenario autenticó a un usuario: aquí se actúa como invitado

        $this->getJson($base)->assertUnauthorized();
        $this->putJson("{$base}/{$p->id}", [])->assertUnauthorized();
        $this->getJson("{$base}/{$p->id}/historial")->assertUnauthorized();
    }

    public function test_sin_permisos_recibe_403_y_no_guarda(): void
    {
        [, $convocatoria, [$p], $version] = $this->escenario(1, 1);
        $ficha = Ficha::first();
        $base = "/api/seleccion/convocatorias/{$convocatoria->id}/decisiones";

        foreach ([$this->usuario(Role::Aprendiz, 'evaluador', ['ficha_id' => $ficha->id]), $this->usuario(Role::Aspirante, null, ['ficha_id' => $ficha->id])] as $actor) {
            $this->actingAs($actor)->getJson($base)->assertForbidden();
            $this->decidir($actor, $convocatoria, $p, 'seleccionado', $version)->assertForbidden();
            $this->actingAs($actor)->getJson("{$base}/{$p->id}/historial")->assertForbidden();
            // La autorización se resuelve antes que la existencia.
            $this->actingAs($actor)->putJson('/api/seleccion/convocatorias/999999/decisiones/999999', [])->assertForbidden();
        }
        $this->assertSame(0, SeleccionDecision::count());
    }

    public function test_ficha_ajena_postulacion_de_otra_convocatoria_e_inexistentes_responden_404(): void
    {
        [$seleccionador, $convocatoria, [$p], $version] = $this->escenario(1, 1);
        $otraFicha = $this->ficha('2631620');
        $ajena = $this->convocatoria($otraFicha);
        $pAjena = $this->candidato($ajena);
        $otraMismaFicha = $this->convocatoria(Ficha::first());
        $pOtra = $this->candidato($otraMismaFicha);
        $base = "/api/seleccion/convocatorias/{$ajena->id}/decisiones";

        $this->decisiones($seleccionador, $ajena)->assertNotFound();
        $this->decidir($seleccionador, $ajena, $pAjena, 'seleccionado', 1)->assertNotFound();
        $this->actingAs($seleccionador)->getJson("{$base}/{$pAjena->id}/historial")->assertNotFound();
        // Postulación de otra convocatoria de la misma ficha, e inexistentes.
        $this->decidir($seleccionador, $convocatoria, $pOtra, 'seleccionado', $version)->assertNotFound();
        $this->actingAs($seleccionador)->getJson("/api/seleccion/convocatorias/{$convocatoria->id}/decisiones/{$pOtra->id}/historial")->assertNotFound();
        $this->actingAs($seleccionador)->putJson("/api/seleccion/convocatorias/{$convocatoria->id}/decisiones/999999", [])->assertNotFound();
        $this->actingAs($seleccionador)->getJson('/api/seleccion/convocatorias/999999/decisiones')->assertNotFound();

        $this->assertSame(0, SeleccionDecision::count());
    }

    // ── Condiciones para decidir ───────────────────────────────────────

    public function test_un_finalista_sin_decision_aparece_como_pendiente_y_con_los_cupos(): void
    {
        [$seleccionador, $convocatoria, [$a, $b], $version] = $this->escenario(2, 2);

        $respuesta = $this->decisiones($seleccionador, $convocatoria)->assertOk();

        $respuesta->assertJsonPath('meta.cupos', ['vacantes_totales' => 2, 'cupos_ocupados' => 0, 'cupos_disponibles' => 2])
            ->assertJsonPath('meta.pendientes', 2)
            ->assertJsonPath('meta.lista.version', $version)
            ->assertJsonPath('meta.lista.requiere_revision', false)
            ->assertJsonPath('data.0.postulacion_id', $a->id)
            ->assertJsonPath('data.0.estado', 'pendiente')
            ->assertJsonPath('data.0.decision', null)
            ->assertJsonPath('data.0.en_lista', true);
    }

    public function test_exige_justificacion_resultado_valido_y_version_de_la_lista(): void
    {
        [$seleccionador, $convocatoria, [$a], $version] = $this->escenario(1, 1);

        $this->decidir($seleccionador, $convocatoria, $a, 'seleccionado', $version, null)->assertUnprocessable()->assertJsonValidationErrors('justificacion');
        $this->decidir($seleccionador, $convocatoria, $a, 'seleccionado', $version, 'corta')->assertUnprocessable()->assertJsonValidationErrors('justificacion');
        $this->decidir($seleccionador, $convocatoria, $a, 'talvez', $version)->assertUnprocessable()->assertJsonValidationErrors('resultado');
        $this->decidir($seleccionador, $convocatoria, $a, 'seleccionado', null)->assertUnprocessable()->assertJsonValidationErrors('lista_version');
        $this->decidir($seleccionador, $convocatoria, $a, 'pendiente', $version)->assertUnprocessable();

        $this->assertSame(0, SeleccionDecision::count());
    }

}
