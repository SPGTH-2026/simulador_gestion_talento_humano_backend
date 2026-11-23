<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ConvocatoriaPostulacionesAyudas;
use Tests\TestCase;

class ConvocatoriaPostulantesDetalleTest extends TestCase
{
    use RefreshDatabase;
    use ConvocatoriaPostulacionesAyudas;

    public function test_detalle_aspirante_incluye_mi_postulacion_y_no_postulantes(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->convocatoriaAbierta($ficha, $instructor);
        $aspirante = $this->aspirante($ficha);
        $propia = $this->postular($convocatoria, $aspirante);

        $data = $this->actingAs($aspirante)
            ->getJson('/api/convocatorias/'.$convocatoria->id)
            ->assertOk()
            ->json('data');

        $this->assertSame($propia->id, $data['mi_postulacion']['id']);
        $this->assertSame('postulada', $data['mi_postulacion']['estado']['codigo']);
        $this->assertArrayNotHasKey('postulantes', $data);
    }

    public function test_detalle_aspirante_sin_postulacion_recibe_mi_postulacion_null(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->convocatoriaAbierta($ficha, $instructor);

        $data = $this->actingAs($this->aspirante($ficha))
            ->getJson('/api/convocatorias/'.$convocatoria->id)
            ->assertOk()
            ->json('data');

        $this->assertArrayHasKey('mi_postulacion', $data);
        $this->assertNull($data['mi_postulacion']);
        $this->assertArrayNotHasKey('postulantes', $data);
    }

    public function test_detalle_gestor_incluye_postulantes_y_no_mi_postulacion(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->convocatoriaAbierta($ficha, $instructor);

        $this->postular($convocatoria, $this->aspirante($ficha));
        $this->postular($convocatoria, $this->aspirante($ficha));
        $this->retirar($convocatoria, $this->aspirante($ficha));

        $data = $this->actingAs($instructor)
            ->getJson('/api/convocatorias/'.$convocatoria->id)
            ->assertOk()
            ->json('data');

        $this->assertSame(2, $data['postulantes']);
        $this->assertArrayNotHasKey('mi_postulacion', $data);
    }

    // ── Store / Update / Estado ────────────────────────────────────────

    public function test_crear_convocatoria_devuelve_postulantes_en_cero(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);

        $respuesta = $this->actingAs($instructor)
            ->postJson('/api/convocatorias', ['nombre' => 'Nueva'])
            ->assertCreated();

        $this->assertSame(0, $respuesta->json('data.postulantes'));
        $this->assertArrayNotHasKey('mi_postulacion', $respuesta->json('data'));
    }

    public function test_editar_convocatoria_devuelve_postulantes(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->convocatoriaAbierta($ficha, $instructor);

        $this->postular($convocatoria, $this->aspirante($ficha));
        $this->postular($convocatoria, $this->aspirante($ficha));

        $respuesta = $this->actingAs($instructor)
            ->patchJson('/api/convocatorias/'.$convocatoria->id, ['nombre' => 'Editada'])
            ->assertOk();

        $this->assertSame(2, $respuesta->json('data.postulantes'));
        $this->assertArrayNotHasKey('mi_postulacion', $respuesta->json('data'));
    }

    public function test_cambio_de_estado_devuelve_postulantes(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->convocatoriaAbierta($ficha, $instructor);

        $this->postular($convocatoria, $this->aspirante($ficha));

        $respuesta = $this->actingAs($instructor)
            ->patchJson('/api/convocatorias/'.$convocatoria->id.'/estado', ['estado' => 'en_proceso'])
            ->assertOk();

        $this->assertSame(1, $respuesta->json('data.postulantes'));
        $this->assertArrayNotHasKey('mi_postulacion', $respuesta->json('data'));
    }

}
