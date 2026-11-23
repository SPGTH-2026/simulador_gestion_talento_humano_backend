<?php

namespace Tests\Feature;

use App\Models\Ficha;
use App\Models\SeleccionFinalistaLista;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SeleccionRankingAyudas;
use Tests\TestCase;

class SeleccionRankingListaTest extends TestCase
{
    use RefreshDatabase;
    use SeleccionRankingAyudas;

    public function test_confirmar_la_sugerencia_no_exige_justificacion_y_guarda_quien_y_cuando(): void
    {
        [$seleccionador, $convocatoria, $c] = $this->escenarioConNotas(['9', '8', '7', '6', '5'], 2);
        $version = $this->ranking($seleccionador, $convocatoria)->json('meta.version');

        $this->confirmar($seleccionador, $convocatoria, $version, [$c[0], $c[1], $c[2], $c[3]])
            ->assertCreated()
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.confirmada_por', $seleccionador->name)
            ->assertJsonPath('data.motivo', null)
            ->assertJsonPath('data.requiere_revision', false)
            ->assertJsonPath('data.finalistas.0.posicion', 1)
            ->assertJsonPath('data.finalistas.0.resultado_adicional', '9.00');

        $this->ranking($seleccionador, $convocatoria)->assertJsonPath('meta.lista_confirmada.version', 1)
            ->assertJsonPath('meta.lista_confirmada.requiere_revision', false)
            ->assertJsonPath('data.0.finalista', true)->assertJsonPath('data.4.finalista', false);
    }

    public function test_modificar_la_sugerencia_o_resolver_un_empate_exige_justificacion(): void
    {
        [$seleccionador, $convocatoria, $c] = $this->escenarioConNotas(['9', '8', '7', '6', '5'], 2);
        $version = $this->ranking($seleccionador, $convocatoria)->json('meta.version');

        // Distinto de la sugerencia (4 sugeridos): sin justificación no.
        $this->confirmar($seleccionador, $convocatoria, $version, [$c[0], $c[1]])->assertUnprocessable()->assertJsonValidationErrors('justificacion');
        $this->confirmar($seleccionador, $convocatoria, $version, [$c[0], $c[1], 99999])->assertUnprocessable()->assertJsonValidationErrors('postulacion_ids');
        $this->confirmar($seleccionador, $convocatoria, $version, [$c[0], $c[1]], 'corta')->assertUnprocessable()->assertJsonValidationErrors('justificacion');
        $this->confirmar($seleccionador, $convocatoria, $version, [$c[0], $c[1]], 'Solo dos pasan a la siguiente fase')->assertCreated()
            ->assertJsonPath('data.motivo', 'Solo dos pasan a la siguiente fase');
        $this->confirmar($seleccionador, $convocatoria, $version, [])->assertUnprocessable()->assertJsonValidationErrors('postulacion_ids');

        // Empate en el corte: aunque se elija exactamente lo "sugerido", hay que justificar.
        [$s2, $conv2, $e] = $this->escenarioConNotas(['9', '8', '8', '8', '8'], 2, '2631620');
        $v2 = $this->ranking($s2, $conv2)->json('meta.version');
        $this->confirmar($s2, $conv2, $v2, [$e[0]])->assertUnprocessable()->assertJsonValidationErrors('justificacion');
        $this->confirmar($s2, $conv2, $v2, [$e[0], $e[1], $e[2], $e[3]], 'Desempate acordado por el comité')->assertCreated();
    }

    public function test_solo_candidatos_listos_y_con_la_convocatoria_en_proceso(): void
    {
        [$seleccionador, $convocatoria, $c] = $this->escenarioConNotas(['9', '8'], 1);
        $incompleto = $this->candidato($convocatoria, '9', null);
        $version = $this->ranking($seleccionador, $convocatoria)->json('meta.version');

        $this->confirmar($seleccionador, $convocatoria, $version, [$c[0], $incompleto], 'Incluyo a uno sin la prueba técnica')
            ->assertUnprocessable()->assertJsonValidationErrors('postulacion_ids');

        $convocatoria->forceFill(['estado_id' => $this->parametroId('estado_convocatoria', 'cerrada')])->save();
        $this->confirmar($seleccionador, $convocatoria, $version, [$c[0], $c[1]])->assertUnprocessable()->assertJsonValidationErrors('convocatoria');
        $this->assertSame(0, SeleccionFinalistaLista::count());
    }

    public function test_un_reenvio_identico_no_crea_otra_version_y_cambiar_el_conjunto_si(): void
    {
        [$seleccionador, $convocatoria, $c] = $this->escenarioConNotas(['9', '8', '7', '6'], 2);
        $version = $this->ranking($seleccionador, $convocatoria)->json('meta.version');
        $todos = [$c[0], $c[1], $c[2], $c[3]];

        $this->confirmar($seleccionador, $convocatoria, $version, $todos)->assertCreated();
        $this->confirmar($seleccionador, $convocatoria, $version, array_reverse($todos))->assertOk()->assertJsonPath('data.version', 1);
        $this->assertSame(1, SeleccionFinalistaLista::count());

        // Reemplazar: otra versión; la anterior se conserva.
        $this->confirmar($seleccionador, $convocatoria, $version, [$c[0], $c[1]], 'Reducimos la lista a dos finalistas')
            ->assertCreated()->assertJsonPath('data.version', 2)->assertJsonPath('data.motivo', 'Reducimos la lista a dos finalistas');

        $this->assertSame(2, SeleccionFinalistaLista::count());
        $historial = $this->actingAs($seleccionador)->getJson("/api/seleccion/convocatorias/{$convocatoria->id}/finalistas")->assertOk();
        $historial->assertJsonPath('data.actual.version', 2)->assertJsonPath('data.versiones.0.version', 2)
            ->assertJsonPath('data.versiones.1.version', 1)->assertJsonPath('data.versiones.1.total', 4);
        $this->assertSame(4, SeleccionFinalistaLista::where('version', 1)->first()->miembros()->count());
    }

    public function test_una_lista_con_una_postulacion_de_otra_convocatoria_se_rechaza_completa(): void
    {
        [$seleccionador, $convocatoria, $c] = $this->escenarioConNotas(['9', '8', '7', '6'], 2);
        $otra = $this->convocatoria(Ficha::first(), 'en_proceso', 2);
        $this->configurar($otra);
        $ajena = $this->candidato($otra, '9', '9');
        $version = $this->ranking($seleccionador, $convocatoria)->json('meta.version');

        // Válida + ajena (de otra convocatoria) + inexistente: ninguna queda guardada.
        foreach ([[$c[0], $ajena], [$c[0], $c[1], $c[2], $c[3], $ajena], [$c[0], 999999]] as $mezcla) {
            $respuesta = $this->confirmar($seleccionador, $convocatoria, $version, $mezcla, 'Mezcla con una postulación que no corresponde')
                ->assertUnprocessable()->assertJsonValidationErrors('postulacion_ids');
            // No revela datos de la postulación ajena.
            $this->assertStringNotContainsString((string) $ajena->user->name, $respuesta->getContent());
        }

        $this->assertSame(0, SeleccionFinalistaLista::count());
        $this->assertSame(0, DB::table('seleccion_finalistas_miembros')->count());
    }

}
