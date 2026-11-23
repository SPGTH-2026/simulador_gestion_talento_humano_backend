<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Postulacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ConvocatoriaCerradaOcultaAyudas;
use Tests\TestCase;

class ConvocatoriaCerradaDetalleTest extends TestCase
{
    use RefreshDatabase;
    use ConvocatoriaCerradaOcultaAyudas;

    public function test_el_aspirante_recibe_404_en_el_detalle_de_una_cerrada_y_200_en_la_vigente(): void
    {
        $ficha = $this->ficha();
        $c = $this->cuatroConvocatorias($ficha, $this->instructor($ficha));
        $aspirante = $this->aspirante($ficha);

        $this->actingAs($aspirante)->getJson("/api/convocatorias/{$c['vencida']->id}")->assertNotFound();
        $this->actingAs($aspirante)->getJson("/api/convocatorias/{$c['cerrada']->id}")->assertNotFound();
        $this->actingAs($aspirante)->getJson("/api/convocatorias/{$c['enProceso']->id}")->assertNotFound();
        $this->actingAs($aspirante)->getJson("/api/convocatorias/{$c['vigente']->id}")->assertOk();
    }

    public function test_el_aprendiz_general_recibe_404_en_el_detalle_de_una_cerrada(): void
    {
        $ficha = $this->ficha();
        $c = $this->cuatroConvocatorias($ficha, $this->instructor($ficha));
        $general = $this->aprendiz($ficha, 'general');

        $this->actingAs($general)->getJson("/api/convocatorias/{$c['vencida']->id}")->assertNotFound();
        $this->actingAs($general)->getJson("/api/convocatorias/{$c['cerrada']->id}")->assertNotFound();
        $this->actingAs($general)->getJson("/api/convocatorias/{$c['enProceso']->id}")->assertOk();
    }

    public function test_el_gestor_y_el_instructor_ven_el_detalle_de_una_cerrada(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $c = $this->cuatroConvocatorias($ficha, $instructor);

        $this->actingAs($instructor)->getJson("/api/convocatorias/{$c['cerrada']->id}")->assertOk();
        $this->actingAs($this->aprendiz($ficha, 'gestor_convocatorias'))
            ->getJson("/api/convocatorias/{$c['vencida']->id}")->assertOk();
    }

    /**
     * Cierra la tabla de detalle por rol: al super admin le llega igual que al
     * instructor (200) y el evaluador no puede ni pedirla (403 por permiso).
     */
    public function test_el_super_admin_ve_la_cerrada_y_el_evaluador_recibe_403_en_el_detalle(): void
    {
        $ficha = $this->ficha();
        $c = $this->cuatroConvocatorias($ficha, $this->instructor($ficha));

        $this->actingAs($this->usuario(Role::SuperAdmin))
            ->getJson("/api/convocatorias/{$c['cerrada']->id}")->assertOk();

        $this->actingAs($this->aprendiz($ficha, 'evaluador'))
            ->getJson("/api/convocatorias/{$c['cerrada']->id}")->assertForbidden();
    }

    // ── Mis postulaciones ───────────────────────────────────────────────

    public function test_mis_postulaciones_sigue_mostrando_la_convocatoria_cerrada_del_aspirante(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $c = $this->cuatroConvocatorias($ficha, $instructor);
        $aspirante = $this->aspirante($ficha);

        Postulacion::create([
            'convocatoria_id' => $c['vencida']->id,
            'user_id' => $aspirante->id,
            'estado_id' => $this->parametroId('estado_postulacion', 'postulada'),
            'fecha_postulacion' => now(),
        ]);

        $respuesta = $this->actingAs($aspirante)->getJson('/api/postulaciones')->assertOk();

        $this->assertContains($c['vencida']->id, array_map('intval', array_column(array_column($respuesta->json('data'), 'convocatoria'), 'id')));
    }

    public function test_no_se_puede_postular_a_una_convocatoria_cerrada(): void
    {
        $ficha = $this->ficha();
        $c = $this->cuatroConvocatorias($ficha, $this->instructor($ficha));
        $aspirante = $this->aspirante($ficha);

        $this->actingAs($aspirante)->postJson("/api/convocatorias/{$c['vencida']->id}/postulaciones")
            ->assertStatus(422);
        $this->actingAs($aspirante)->postJson("/api/convocatorias/{$c['cerrada']->id}/postulaciones")
            ->assertNotFound();
    }

}
