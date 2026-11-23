<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Ficha;
use App\Models\Postulacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ConvocatoriaSkillsCatalogoAyudas;
use Tests\TestCase;

class ConvocatoriaSkillsListadoYFichaTest extends TestCase
{
    use RefreshDatabase;
    use ConvocatoriaSkillsCatalogoAyudas;

    public function test_el_listado_incluye_los_nombres_de_las_skills(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->publicada($this->crearConvocatoria($ficha, $instructor));
        $convocatoria->skills()->create(['nombre' => 'Excel avanzado']);

        $respuesta = $this->actingAs($instructor)
            ->getJson('/api/convocatorias')
            ->assertOk();

        $fila = collect($respuesta->json('data'))->firstWhere('id', $convocatoria->id);
        $this->assertSame(['Excel avanzado'], $fila['skills']);
        $this->assertSame('Excel avanzado', $fila['skills_detalle'][0]['nombre']);
        $this->assertNull($fila['skills_detalle'][0]['parametro_id']);
    }

    // ── Ficha: solo instructor y super admin ──────────────────────────

    public function test_el_instructor_recibe_la_ficha_de_la_convocatoria(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->publicada($this->crearConvocatoria($ficha, $instructor));

        $respuesta = $this->actingAs($instructor)
            ->getJson('/api/convocatorias/'.$convocatoria->id)
            ->assertOk();

        $this->assertSame($ficha->id, $respuesta->json('data.ficha.id'));
        $this->assertSame($ficha->codigo, $respuesta->json('data.ficha.codigo'));
    }

    public function test_el_aspirante_no_recibe_la_ficha_de_la_convocatoria(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->publicada($this->crearConvocatoria($ficha, $instructor));
        $aspirante = $this->aspirante($ficha);

        $respuesta = $this->actingAs($aspirante)
            ->getJson('/api/convocatorias/'.$convocatoria->id)
            ->assertOk();

        // La clave sigue existiendo (no se rompe el contrato), pero va en null.
        $this->assertNull($respuesta->json('data.ficha'));
    }

    public function test_el_gestor_de_convocatorias_no_recibe_la_ficha(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->publicada($this->crearConvocatoria($ficha, $instructor));
        $gestor = $this->gestorConvocatorias($ficha);

        // El subrol gestor_convocatorias SÍ tiene convocatorias:gestionar.
        $this->assertTrue($gestor->can('convocatorias:gestionar'));

        $respuesta = $this->actingAs($gestor)
            ->getJson('/api/convocatorias/'.$convocatoria->id)
            ->assertOk();

        $this->assertNull($respuesta->json('data.ficha'));
    }

    public function test_el_super_admin_recibe_la_ficha(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->publicada($this->crearConvocatoria($ficha, $instructor));
        $superAdmin = $this->usuario(Role::SuperAdmin);

        $respuesta = $this->actingAs($superAdmin)
            ->getJson('/api/convocatorias/'.$convocatoria->id)
            ->assertOk();

        $this->assertSame($ficha->id, $respuesta->json('data.ficha.id'));
    }

    // ── Conteo de postulantes: solo quien gestiona ─────────────────────

    public function test_el_conteo_de_postulantes_solo_lo_recibe_quien_gestiona(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->publicada($this->crearConvocatoria($ficha, $instructor));
        $aspirante = $this->aspirante($ficha);

        Postulacion::create([
            'convocatoria_id' => $convocatoria->id,
            'user_id' => $aspirante->id,
            'estado_id' => $this->parametroId('estado_postulacion', 'postulada'),
            'fecha_postulacion' => now(),
        ]);

        // Quien gestiona: recibe el conteo.
        $delInstructor = $this->actingAs($instructor)
            ->getJson('/api/convocatorias/'.$convocatoria->id)
            ->assertOk();
        $this->assertSame(1, $delInstructor->json('data.postulantes'));

        $delGestor = $this->actingAs($this->gestorConvocatorias($ficha))
            ->getJson('/api/convocatorias/'.$convocatoria->id)
            ->assertOk();
        $this->assertSame(1, $delGestor->json('data.postulantes'));

        // El aspirante: ni conteo ni ficha.
        $delAspirante = $this->actingAs($aspirante)
            ->getJson('/api/convocatorias/'.$convocatoria->id)
            ->assertOk();
        $this->assertNull($delAspirante->json('data.postulantes'));
        $this->assertNull($delAspirante->json('data.ficha'));
    }

}
