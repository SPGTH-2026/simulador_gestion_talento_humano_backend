<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\SeleccionConfiguracion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeleccionConfiguracionAyudas;
use Tests\TestCase;

class SeleccionConfiguracionAccesoTest extends TestCase
{
    use RefreshDatabase;
    use SeleccionConfiguracionAyudas;

    public function test_invitado_recibe_401(): void
    {
        $convocatoria = $this->convocatoria($this->ficha('2896411'));

        $this->getJson($this->url($convocatoria))->assertUnauthorized();
        $this->putJson($this->url($convocatoria), ['entrevista_activa' => true, 'prueba_tecnica_activa' => false])->assertUnauthorized();
    }

    public function test_sin_permisos_de_seleccion_recibe_403_y_no_guarda(): void
    {
        $ficha = $this->ficha('2896411');
        $convocatoria = $this->convocatoria($ficha);
        $sinPermiso = [
            $this->usuario(Role::Aprendiz, 'evaluador', ['ficha_id' => $ficha->id]),
            $this->usuario(Role::Aprendiz, 'gestor_convocatorias', ['ficha_id' => $ficha->id]),
            $this->usuario(Role::Aspirante, null, ['ficha_id' => $ficha->id]),
        ];

        foreach ($sinPermiso as $actor) {
            $this->actingAs($actor)->getJson($this->url($convocatoria))->assertForbidden();
            $this->guardar($actor, $convocatoria, ['entrevista_activa' => true, 'prueba_tecnica_activa' => false])->assertForbidden();
        }
        $this->assertSame(0, SeleccionConfiguracion::count());
    }

    public function test_la_autorizacion_se_resuelve_antes_que_la_existencia(): void
    {
        $evaluador = $this->usuario(Role::Aprendiz, 'evaluador', ['ficha_id' => $this->ficha('2896411')->id]);

        $this->actingAs($evaluador)->getJson('/api/seleccion/convocatorias/999999/configuracion')->assertForbidden();
    }

    public function test_correo_sin_verificar_recibe_403(): void
    {
        $ficha = $this->ficha('2896411');
        $convocatoria = $this->convocatoria($ficha);
        $sinVerificar = $this->usuario(Role::Aprendiz, 'seleccionador', ['ficha_id' => $ficha->id, 'email_verified_at' => null]);

        $this->actingAs($sinVerificar)->getJson($this->url($convocatoria))->assertForbidden();
        $this->guardar($sinVerificar, $convocatoria, ['entrevista_activa' => true, 'prueba_tecnica_activa' => false])->assertForbidden();
    }

    public function test_convocatoria_ajena_o_inexistente_responde_404(): void
    {
        $fichaA = $this->ficha('2896411');
        $ajena = $this->convocatoria($this->ficha('2631620'));
        $seleccionador = $this->seleccionador($fichaA);
        $cuerpo = ['entrevista_activa' => true, 'prueba_tecnica_activa' => false];

        $this->actingAs($seleccionador)->getJson($this->url($ajena))->assertNotFound();
        $this->guardar($seleccionador, $ajena, $cuerpo)->assertNotFound();
        $this->actingAs($seleccionador)->getJson('/api/seleccion/convocatorias/999999/configuracion')->assertNotFound();
        $this->actingAs($seleccionador)->putJson('/api/seleccion/convocatorias/999999/configuracion', $cuerpo)->assertNotFound();
        $this->assertSame(0, SeleccionConfiguracion::count());

        // Un instructor de otra ficha tampoco la ve; el superadmin sí, en cualquier ficha.
        $instructorAjeno = $this->usuario(Role::Instructor, null, ['ficha_id' => $fichaA->id]);
        $this->guardar($instructorAjeno, $ajena, $cuerpo)->assertNotFound();
        $this->guardar($this->usuario(Role::SuperAdmin), $ajena, $cuerpo)->assertOk();
    }

}
