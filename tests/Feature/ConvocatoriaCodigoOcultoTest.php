<?php

namespace Tests\Feature;

use App\Enums\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ConvocatoriaIdentificadorOcultoAyudas;
use Tests\TestCase;

class ConvocatoriaCodigoOcultoTest extends TestCase
{
    use RefreshDatabase;
    use ConvocatoriaIdentificadorOcultoAyudas;

    public function test_el_listado_oculta_el_codigo_a_quien_no_gestiona(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->publicada($ficha, $instructor);

        // ElGestor (instructor) sí lo recibe.
        $delInstructor = $this->actingAs($instructor)->getJson('/api/convocatorias')->assertOk();
        $this->assertSame($convocatoria->codigo, $delInstructor->json('data.0.codigo'));

        // El Aspirante no.
        $delAspirante = $this->actingAs($this->aspirante($ficha))->getJson('/api/convocatorias')->assertOk();
        $this->assertNull($delAspirante->json('data.0.codigo'));
    }

    public function test_el_listado_oculta_el_codigo_al_aprendiz_general_pero_no_al_gestor(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->publicada($ficha, $instructor);

        // El aprendiz 'general' tiene 'convocatorias:gestionar' (ver
        // config/permissions.php) pero NO está en la lista blanca.
        $general = $this->aprendiz($ficha, 'general');
        $this->assertTrue($general->can('convocatorias:gestionar'));

        $delGeneral = $this->actingAs($general)->getJson('/api/convocatorias')->assertOk();
        $this->assertNull($delGeneral->json('data.0.codigo'));

        // El subrol 'gestor_convocatorias' sí está en la lista blanca.
        $gestor = $this->aprendiz($ficha, 'gestor_convocatorias');
        $this->assertTrue($gestor->can('convocatorias:gestionar'));

        $delGestor = $this->actingAs($gestor)->getJson('/api/convocatorias')->assertOk();
        $this->assertSame($convocatoria->codigo, $delGestor->json('data.0.codigo'));
    }

    public function test_el_listado_muestra_el_codigo_al_super_admin(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->publicada($ficha, $instructor);

        $respuesta = $this->actingAs($this->usuario(Role::SuperAdmin))
            ->getJson('/api/convocatorias')
            ->assertOk();

        $this->assertSame($convocatoria->codigo, $respuesta->json('data.0.codigo'));
    }

    // ── Detalle ────────────────────────────────────────────────────────

    public function test_el_detalle_oculta_el_codigo_a_quien_no_gestiona(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->publicada($ficha, $instructor);

        $delAspirante = $this->actingAs($this->aspirante($ficha))
            ->getJson('/api/convocatorias/'.$convocatoria->id)
            ->assertOk();
        $this->assertNull($delAspirante->json('data.codigo'));

        $delGeneral = $this->actingAs($this->aprendiz($ficha, 'general'))
            ->getJson('/api/convocatorias/'.$convocatoria->id)
            ->assertOk();
        $this->assertNull($delGeneral->json('data.codigo'));

        $delGestor = $this->actingAs($this->aprendiz($ficha, 'gestor_convocatorias'))
            ->getJson('/api/convocatorias/'.$convocatoria->id)
            ->assertOk();
        $this->assertSame($convocatoria->codigo, $delGestor->json('data.codigo'));

        $delInstructor = $this->actingAs($instructor)
            ->getJson('/api/convocatorias/'.$convocatoria->id)
            ->assertOk();
        $this->assertSame($convocatoria->codigo, $delInstructor->json('data.codigo'));
    }

    // ── Postulaciones (Mis postulaciones) ──────────────────────────────

}
