<?php

namespace Tests\Feature;

use App\Enums\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeleccionComparacionAyudas;
use Tests\TestCase;

class SeleccionComparacionAccesoTest extends TestCase
{
    use RefreshDatabase;
    use SeleccionComparacionAyudas;

    public function test_un_invitado_recibe_401(): void
    {
        [, $convocatoria, [$a, $b]] = $this->escenario(2);
        $this->app['auth']->forgetGuards();

        $this->getJson("/api/seleccion/convocatorias/{$convocatoria->id}/comparacion?postulaciones[]={$a->id}&postulaciones[]={$b->id}")->assertUnauthorized();
    }

    public function test_sin_permiso_de_seleccion_recibe_403(): void
    {
        [, $convocatoria, [$a, $b], $ficha] = $this->escenario(2);
        $evaluador = $this->usuario(Role::Aprendiz, 'evaluador', ['ficha_id' => $ficha->id]);
        $aspirante = $this->usuario(Role::Aspirante, null, ['ficha_id' => $ficha->id]);

        $this->comparar($evaluador, $convocatoria, [$a, $b])->assertForbidden();
        $this->comparar($aspirante, $convocatoria, [$a, $b])->assertForbidden();
    }

    public function test_una_convocatoria_de_otra_ficha_responde_404(): void
    {
        [, $convocatoria, [$a, $b]] = $this->escenario(2);
        $otro = $this->seleccionador($this->ficha('2631620'));

        $this->comparar($otro, $convocatoria, [$a, $b])->assertNotFound();
    }

    public function test_una_postulacion_de_otra_convocatoria_o_inexistente_responde_404_aunque_sea_de_la_misma_ficha(): void
    {
        [$seleccionador, $convocatoria, [$a, $b], $ficha] = $this->escenario(2);
        $otraConvocatoria = $this->convocatoria($ficha);
        $ajena = $this->candidato($otraConvocatoria);

        $this->comparar($seleccionador, $convocatoria, [$a, $ajena])->assertNotFound();
        $this->comparar($seleccionador, $convocatoria, [$a, 999999])->assertNotFound();
        $this->comparar($seleccionador, $convocatoria, [$a, $b])->assertOk(); // y las propias sí
    }

    public function test_exige_de_dos_a_cinco_postulaciones_distintas(): void
    {
        [$seleccionador, $convocatoria, $personas] = $this->escenario(6);
        $url = "/api/seleccion/convocatorias/{$convocatoria->id}/comparacion";

        $this->comparar($seleccionador, $convocatoria, [$personas[0]])->assertUnprocessable()->assertJsonValidationErrors('postulaciones');
        $this->comparar($seleccionador, $convocatoria, $personas)->assertUnprocessable()->assertJsonValidationErrors('postulaciones');
        $this->comparar($seleccionador, $convocatoria, [$personas[0], $personas[0]])->assertUnprocessable();
        $this->actingAs($seleccionador)->getJson($url)->assertUnprocessable()->assertJsonValidationErrors('postulaciones');
        $this->actingAs($seleccionador)->getJson("{$url}?postulaciones={$personas[0]->id}")->assertUnprocessable();
        $this->comparar($seleccionador, $convocatoria, array_slice($personas, 0, 5))->assertOk()->assertJsonCount(5, 'data');
    }

    // ── Contenido ──────────────────────────────────────────────────────

}
