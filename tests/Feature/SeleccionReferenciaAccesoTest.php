<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\ReferenciaDocumental;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeleccionReferenciaAyudas;
use Tests\TestCase;

class SeleccionReferenciaAccesoTest extends TestCase
{
    use RefreshDatabase;
    use SeleccionReferenciaAyudas;

    public function test_invitado_recibe_401(): void
    {
        [, $convocatoria, $postulacion, $a] = $this->casoContradictorio();

        $this->getJson($this->url($convocatoria, $postulacion))->assertUnauthorized();
        $this->postJson($this->url($convocatoria, $postulacion), ['evaluacion_id' => $a->id, 'justificacion' => self::JUSTIFICACION])
            ->assertUnauthorized();
    }

    public function test_quien_no_es_instructor_ni_superadmin_recibe_403_aunque_tenga_el_permiso(): void
    {
        [, $convocatoria, $postulacion, $a, , $ficha] = $this->casoContradictorio();

        $actores = [
            // Estos dos aprendices sí tienen seleccion:decidir, pero no son decisores.
            $this->usuario(Role::Aprendiz, 'seleccionador', ['ficha_id' => $ficha->id]),
            $this->usuario(Role::Aprendiz, 'general', ['ficha_id' => $ficha->id]),
            $this->usuario(Role::Aprendiz, 'evaluador', ['ficha_id' => $ficha->id]),
            $this->usuario(Role::Aspirante, null, ['ficha_id' => $ficha->id]),
        ];

        foreach ($actores as $actor) {
            $this->actingAs($actor)->getJson($this->url($convocatoria, $postulacion))->assertForbidden();
            $this->designar($actor, $convocatoria, $postulacion, $a)->assertForbidden();
        }
        $this->assertSame(0, ReferenciaDocumental::count());
    }

    public function test_la_autorizacion_se_resuelve_antes_que_la_existencia(): void
    {
        $ficha = $this->ficha('2896411');
        $seleccionador = $this->usuario(Role::Aprendiz, 'seleccionador', ['ficha_id' => $ficha->id]);

        $this->actingAs($seleccionador)
            ->getJson('/api/seleccion/convocatorias/999998/candidatos/999999/referencia-documental')
            ->assertForbidden();
    }

    public function test_correo_sin_verificar_recibe_403(): void
    {
        [, $convocatoria, $postulacion, , , $ficha] = $this->casoContradictorio();
        $sinVerificar = $this->usuario(Role::Instructor, null, ['ficha_id' => $ficha->id, 'email_verified_at' => null]);

        $this->actingAs($sinVerificar)->getJson($this->url($convocatoria, $postulacion))->assertForbidden();
    }

    public function test_instructor_de_otra_ficha_recibe_404_y_el_superadmin_opera_en_cualquiera(): void
    {
        [, $convocatoria, $postulacion, $a] = $this->casoContradictorio();
        $ajeno = $this->instructor($this->ficha('2631620'));
        $superadmin = $this->usuario(Role::SuperAdmin);

        $this->actingAs($ajeno)->getJson($this->url($convocatoria, $postulacion))->assertNotFound();
        $this->designar($ajeno, $convocatoria, $postulacion, $a)->assertNotFound();
        $this->assertSame(0, ReferenciaDocumental::count());

        $this->actingAs($superadmin)->getJson($this->url($convocatoria, $postulacion))->assertOk();
        $this->designar($superadmin, $convocatoria, $postulacion, $a)->assertCreated();
    }

    public function test_recursos_que_no_pertenecen_al_padre_responden_404(): void
    {
        [$instructor, $convocatoria, $postulacion, $a, , $ficha] = $this->casoContradictorio();
        $otraConvocatoria = $this->convocatoria($ficha);
        $postulacionAjena = $this->postulacion($otraConvocatoria);
        $evaluacionAjena = $this->evaluacion($postulacionAjena, 'finalizada', 70, true);

        // Postulación de otra convocatoria.
        $this->actingAs($instructor)->getJson($this->url($convocatoria, $postulacionAjena))->assertNotFound();
        $this->designar($instructor, $convocatoria, $postulacionAjena, $evaluacionAjena)->assertNotFound();
        // Evaluación de otra postulación.
        $this->designar($instructor, $convocatoria, $postulacion, $evaluacionAjena)->assertNotFound();
        // Inexistentes.
        $this->actingAs($instructor)->getJson("/api/seleccion/convocatorias/999999/candidatos/{$postulacion->id}/referencia-documental")->assertNotFound();
        $this->actingAs($instructor)->getJson("/api/seleccion/convocatorias/{$convocatoria->id}/candidatos/999999/referencia-documental")->assertNotFound();
        $this->actingAs($instructor)->postJson($this->url($convocatoria, $postulacion), ['evaluacion_id' => 999999, 'justificacion' => self::JUSTIFICACION])->assertNotFound();

        $this->assertSame(0, ReferenciaDocumental::count());
    }

    // ── Consulta ───────────────────────────────────────────────────────

}
