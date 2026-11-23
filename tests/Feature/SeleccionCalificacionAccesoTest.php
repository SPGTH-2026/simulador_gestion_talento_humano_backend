<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Ficha;
use App\Models\SeleccionCalificacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeleccionCalificacionAyudas;
use Tests\TestCase;

class SeleccionCalificacionAccesoTest extends TestCase
{
    use RefreshDatabase;
    use SeleccionCalificacionAyudas;

    public function test_invitado_recibe_401(): void
    {
        [, $convocatoria, $postulacion] = $this->escenario();

        $this->getJson($this->url($convocatoria, $postulacion))->assertUnauthorized();
        $this->putJson($this->url($convocatoria, $postulacion, 'entrevista'), ['nota' => 8])->assertUnauthorized();
    }

    public function test_sin_permisos_de_seleccion_recibe_403_y_no_guarda(): void
    {
        [, $convocatoria, $postulacion, $ficha] = $this->escenario();

        foreach ([
            $this->usuario(Role::Aprendiz, 'evaluador', ['ficha_id' => $ficha->id]),
            $this->usuario(Role::Aspirante, null, ['ficha_id' => $ficha->id]),
        ] as $actor) {
            $this->actingAs($actor)->getJson($this->url($convocatoria, $postulacion))->assertForbidden();
            $this->calificar($actor, $convocatoria, $postulacion, 'entrevista', ['nota' => 8])->assertForbidden();
        }
        $this->assertSame(0, SeleccionCalificacion::count());
    }

    public function test_la_autorizacion_se_resuelve_antes_que_la_existencia_y_exige_correo_verificado(): void
    {
        [, $convocatoria, $postulacion, $ficha] = $this->escenario();
        $evaluador = $this->usuario(Role::Aprendiz, 'evaluador', ['ficha_id' => $ficha->id]);
        $sinVerificar = $this->usuario(Role::Aprendiz, 'seleccionador', ['ficha_id' => $ficha->id, 'email_verified_at' => null]);

        $this->actingAs($evaluador)->getJson('/api/seleccion/convocatorias/999999/candidatos/999999/calificaciones')->assertForbidden();
        $this->actingAs($sinVerificar)->getJson($this->url($convocatoria, $postulacion))->assertForbidden();
    }

    public function test_ficha_ajena_postulacion_de_otra_convocatoria_e_inexistentes_responden_404(): void
    {
        [$seleccionador, $convocatoria, $postulacion, $ficha] = $this->escenario();
        $otraConvocatoria = $this->convocatoria($ficha);
        $postulacionAjena = $this->candidatoHabilitado($otraConvocatoria);
        $fichaAjena = $this->ficha('2631620');
        $convocatoriaAjena = $this->convocatoria($fichaAjena);
        $this->configurar($convocatoriaAjena);
        $candidatoAjeno = $this->candidatoHabilitado($convocatoriaAjena);

        // Ficha ajena.
        $this->actingAs($seleccionador)->getJson($this->url($convocatoriaAjena, $candidatoAjeno))->assertNotFound();
        $this->calificar($seleccionador, $convocatoriaAjena, $candidatoAjeno, 'entrevista', ['nota' => 8])->assertNotFound();
        // Postulación de otra convocatoria de la misma ficha.
        $this->actingAs($seleccionador)->getJson($this->url($convocatoria, $postulacionAjena))->assertNotFound();
        $this->calificar($seleccionador, $convocatoria, $postulacionAjena, 'entrevista', ['nota' => 8])->assertNotFound();
        // Inexistentes y actividad desconocida.
        $this->actingAs($seleccionador)->getJson("/api/seleccion/convocatorias/999999/candidatos/{$postulacion->id}/calificaciones")->assertNotFound();
        $this->actingAs($seleccionador)->getJson("/api/seleccion/convocatorias/{$convocatoria->id}/candidatos/999999/calificaciones")->assertNotFound();
        $this->calificar($seleccionador, $convocatoria, $postulacion, 'asado', ['nota' => 8])->assertNotFound();

        $this->assertSame(0, SeleccionCalificacion::count());
    }

    // ── Registro y valores ─────────────────────────────────────────────

}
