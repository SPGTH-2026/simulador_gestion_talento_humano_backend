<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Convocatoria;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\PostulacionAspiranteAyudas;
use Tests\TestCase;

class PostulacionAspiranteAccesoTest extends TestCase
{
    use RefreshDatabase;
    use PostulacionAspiranteAyudas;

    public function test_aspirante_se_postula_y_recibe_201(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->convocatoriaAbierta($ficha, $instructor);

        $respuesta = $this->actingAs($aspirante)
            ->postJson("/api/convocatorias/{$convocatoria->id}/postulaciones")
            ->assertCreated();

        $respuesta->assertJsonStructure(['data' => [
            'id',
            'estado' => ['id', 'codigo', 'nombre'],
            'fecha_postulacion',
            'fecha_retiro',
            'convocatoria' => ['id', 'codigo', 'nombre', 'cargo', 'area', 'estado' => ['id', 'codigo', 'nombre'], 'fecha_apertura', 'fecha_cierre'],
        ]]);

        $this->assertSame('borrador', $respuesta->json('data.estado.codigo'));
        $this->assertNull($respuesta->json('data.fecha_retiro'));
        $this->assertNotNull($respuesta->json('data.fecha_postulacion'));
        $this->assertSame($convocatoria->id, $respuesta->json('data.convocatoria.id'));

        $this->assertDatabaseHas('postulaciones', [
            'convocatoria_id' => $convocatoria->id,
            'user_id' => $aspirante->id,
            'estado_id' => $this->parametroId('estado_postulacion', 'borrador'),
        ]);
    }

    public function test_instructor_no_puede_postularse_devuelve_403(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->convocatoriaAbierta($ficha, $instructor);

        $this->actingAs($instructor)
            ->postJson("/api/convocatorias/{$convocatoria->id}/postulaciones")
            ->assertForbidden()
            ->assertJsonPath('message', 'Solo los aspirantes pueden postularse a las convocatorias.');

        $this->assertDatabaseCount('postulaciones', 0);
    }

    public function test_super_admin_no_puede_postularse_devuelve_403(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $superAdmin = $this->usuario(Role::SuperAdmin);
        $convocatoria = $this->convocatoriaAbierta($ficha, $instructor);

        $this->actingAs($superAdmin)
            ->postJson("/api/convocatorias/{$convocatoria->id}/postulaciones")
            ->assertForbidden()
            ->assertJsonPath('message', 'Solo los aspirantes pueden postularse a las convocatorias.');

        $this->assertDatabaseCount('postulaciones', 0);
    }

    public function test_aprendiz_no_puede_postularse_devuelve_403(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->convocatoriaAbierta($ficha, $instructor);
        $aprendiz = $this->usuario(Role::Aprendiz, ['subrole' => 'general', 'ficha_id' => $ficha->id]);

        // Lo corta el permiso 'convocatorias:postular' antes del controlador.
        $this->actingAs($aprendiz)
            ->postJson("/api/convocatorias/{$convocatoria->id}/postulaciones")
            ->assertForbidden();

        $this->assertDatabaseCount('postulaciones', 0);
    }

    public function test_sin_sesion_devuelve_401(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->convocatoriaAbierta($ficha, $instructor);

        $this->postJson("/api/convocatorias/{$convocatoria->id}/postulaciones")
            ->assertUnauthorized();
    }

    // ── Ventana ────────────────────────────────────────────────────────

    public function test_convocatoria_no_publicada_devuelve_404(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $borrador = $this->crearConvocatoria($ficha, $instructor, [
            'estado_id' => $this->parametroId('estado_convocatoria', 'borrador'),
        ]);

        $this->actingAs($aspirante)
            ->postJson("/api/convocatorias/{$borrador->id}/postulaciones")
            ->assertNotFound()
            ->assertJsonPath('message', 'Convocatoria no encontrada.');
    }

}
