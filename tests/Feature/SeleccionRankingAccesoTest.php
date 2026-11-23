<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Evaluacion;
use App\Models\SeleccionCalificacion;
use App\Models\SeleccionFinalistaLista;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeleccionRankingAyudas;
use Tests\TestCase;

class SeleccionRankingAccesoTest extends TestCase
{
    use RefreshDatabase;
    use SeleccionRankingAyudas;

    public function test_invitado_recibe_401_en_todas_las_rutas(): void
    {
        $convocatoria = $this->convocatoria($this->ficha());
        $base = "/api/seleccion/convocatorias/{$convocatoria->id}";

        $this->getJson("{$base}/ranking")->assertUnauthorized();
        $this->putJson("{$base}/ranking/objetivo", ['objetivo' => 3])->assertUnauthorized();
        $this->getJson("{$base}/finalistas")->assertUnauthorized();
        $this->postJson("{$base}/finalistas", [])->assertUnauthorized();
    }

    public function test_sin_permisos_recibe_403_y_la_autorizacion_va_antes_que_la_existencia(): void
    {
        $ficha = $this->ficha();
        $convocatoria = $this->convocatoria($ficha);
        $base = "/api/seleccion/convocatorias/{$convocatoria->id}";

        foreach ([$this->usuario(Role::Aprendiz, 'evaluador', ['ficha_id' => $ficha->id]), $this->usuario(Role::Aspirante, null, ['ficha_id' => $ficha->id])] as $actor) {
            $this->actingAs($actor)->getJson("{$base}/ranking")->assertForbidden();
            $this->actingAs($actor)->putJson("{$base}/ranking/objetivo", ['objetivo' => 3])->assertForbidden();
            $this->actingAs($actor)->getJson("{$base}/finalistas")->assertForbidden();
            $this->actingAs($actor)->postJson("{$base}/finalistas", ['version' => 'x', 'postulacion_ids' => [1]])->assertForbidden();
            $this->actingAs($actor)->getJson('/api/seleccion/convocatorias/999999/ranking')->assertForbidden();
        }
        $this->assertSame(0, SeleccionFinalistaLista::count());
    }

    public function test_ficha_ajena_responde_404_y_el_superadmin_ve_todas(): void
    {
        $fichaA = $this->ficha('2896411');
        $ajena = $this->convocatoria($this->ficha('2631620'));
        $this->configurar($ajena);
        $this->candidato($ajena, '8', '8');
        $seleccionador = $this->seleccionador($fichaA);
        $base = "/api/seleccion/convocatorias/{$ajena->id}";

        $this->actingAs($seleccionador)->getJson("{$base}/ranking")->assertNotFound();
        $this->actingAs($seleccionador)->putJson("{$base}/ranking/objetivo", ['objetivo' => 3])->assertNotFound();
        $this->actingAs($seleccionador)->getJson("{$base}/finalistas")->assertNotFound();
        $this->actingAs($seleccionador)->postJson("{$base}/finalistas", ['version' => 'x', 'postulacion_ids' => [1]])->assertNotFound();
        $this->actingAs($seleccionador)->getJson('/api/seleccion/convocatorias/999999/ranking')->assertNotFound();

        $this->ranking($this->usuario(Role::SuperAdmin), $ajena)->assertOk()->assertJsonPath('meta.total', 1);
    }

    // ── Elegibilidad ───────────────────────────────────────────────────

    public function test_solo_los_candidatos_listos_entran_al_ranking(): void
    {
        $ficha = $this->ficha();
        $convocatoria = $this->convocatoria($ficha);
        $this->configurar($convocatoria);
        $seleccionador = $this->seleccionador($ficha);

        $listo = $this->candidato($convocatoria, '8', '9');
        $this->candidato($convocatoria, '8', null);                                  // falta la prueba
        $this->candidato($convocatoria, null, null);                                 // sin notas
        $sinEval = $this->postulacion($convocatoria);                                // pendiente documental
        $this->nota($sinEval, 'entrevista', '9');
        $this->nota($sinEval, 'prueba_tecnica', '9');
        $noHab = $this->postulacion($convocatoria);
        $this->evaluacion($noHab, 'finalizada', 30, false);
        $this->nota($noHab, 'entrevista', '9');
        $this->nota($noHab, 'prueba_tecnica', '9');
        $revision = $this->postulacion($convocatoria);
        $this->evaluacion($revision, 'finalizada', 90, true);
        $this->evaluacion($revision, 'finalizada', 20, false, true);
        $this->nota($revision, 'entrevista', '9');
        $this->nota($revision, 'prueba_tecnica', '9');
        $retirada = $this->postulacion($convocatoria, 'retirada');
        $this->evaluacion($retirada, 'finalizada', 90, true);
        $this->nota($retirada, 'entrevista', '9');
        $this->nota($retirada, 'prueba_tecnica', '9');

        $respuesta = $this->ranking($seleccionador, $convocatoria)->assertOk();

        $this->assertSame([$listo->id], collect($respuesta->json('data'))->pluck('postulacion_id')->all());
        $respuesta->assertJsonPath('meta.listos', 1)->assertJsonPath('meta.no_listos', 5)->assertJsonPath('meta.postuladas', 6);
        $this->assertSame(
            ['postulacion_id', 'aspirante', 'posicion', 'resultado_adicional', 'cumplimiento_documental', 'sugerencia', 'finalista'],
            array_keys($respuesta->json('data.0')),
        );
        foreach (['email', 'observacion', 'documentos', 'motivo'] as $prohibido) {
            $this->assertStringNotContainsString($prohibido, $respuesta->getContent());
        }
    }

    public function test_con_la_configuracion_pendiente_nadie_esta_listo(): void
    {
        $ficha = $this->ficha();
        $convocatoria = $this->convocatoria($ficha);
        $this->candidato($convocatoria, null, null);

        $this->ranking($this->seleccionador($ficha), $convocatoria)->assertOk()
            ->assertJsonPath('meta.modo', 'pendiente_de_configurar')
            ->assertJsonPath('meta.total', 0)
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.postuladas', 1);
    }

    public function test_una_habilitacion_invalidada_saca_al_candidato_pero_conserva_sus_notas(): void
    {
        [$seleccionador, $convocatoria, [$a, $b]] = $this->escenarioConNotas(['9', '8']);

        $this->ranking($seleccionador, $convocatoria)->assertJsonPath('meta.total', 2);
        Evaluacion::where('postulacion_id', $a->id)->update(['estado' => 'en_proceso', 'pasa_seleccion' => null]);

        $this->assertSame([$b->id], collect($this->ranking($seleccionador, $convocatoria)->json('data'))->pluck('postulacion_id')->all());
        $this->assertSame(2, SeleccionCalificacion::where('postulacion_id', $a->id)->count());
    }

    // ── Orden, ponderación y posiciones ────────────────────────────────

}
