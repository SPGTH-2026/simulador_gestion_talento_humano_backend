<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Postulacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\MisPostulacionesAyudas;
use Tests\TestCase;

class MisPostulacionesEstadoTest extends TestCase
{
    use RefreshDatabase;
    use MisPostulacionesAyudas;

    public function test_estado_por_id_numerico_devuelve_422(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $postulacion = $this->crearPostulacion($this->convocatoriaAbierta($ficha, $instructor), $aspirante);

        $this->actingAs($aspirante)
            ->patchJson("/api/postulaciones/{$postulacion->id}/estado", ['estado' => '1'])
            ->assertStatus(422)
            ->assertJsonPath('errors.estado.0', 'El estado debe enviarse por su código, no por su id.');
    }

    public function test_estado_desconocido_devuelve_422(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $postulacion = $this->crearPostulacion($this->convocatoriaAbierta($ficha, $instructor), $aspirante);

        $this->actingAs($aspirante)
            ->patchJson("/api/postulaciones/{$postulacion->id}/estado", ['estado' => 'aprobada'])
            ->assertStatus(422)
            ->assertJsonPath('errors.estado.0', 'El estado no es válido.');
    }

    public function test_estado_igual_al_actual_devuelve_422(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $postulacion = $this->postulacionBorrador($this->convocatoriaAbierta($ficha, $instructor), $aspirante);

        $this->actingAs($aspirante)
            ->patchJson("/api/postulaciones/{$postulacion->id}/estado", ['estado' => 'borrador'])
            ->assertStatus(422)
            ->assertJsonPath('errors.estado.0', 'La postulación ya está en estado borrador.');
    }

    public function test_patch_sin_estado_devuelve_422(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $postulacion = $this->crearPostulacion($this->convocatoriaAbierta($ficha, $instructor), $aspirante);

        $this->actingAs($aspirante)
            ->patchJson("/api/postulaciones/{$postulacion->id}/estado", [])
            ->assertStatus(422)
            ->assertJsonPath('errors.estado.0', 'El campo estado es obligatorio.');
    }

    // ── PATCH: aislamiento ─────────────────────────────────────────────

    public function test_patch_de_postulacion_ajena_devuelve_404(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspiranteA = $this->aspirante($ficha);
        $aspiranteB = $this->aspirante($ficha);
        $postulacion = $this->crearPostulacion($this->convocatoriaAbierta($ficha, $instructor), $aspiranteA);

        $this->actingAs($aspiranteB)
            ->patchJson("/api/postulaciones/{$postulacion->id}/estado", ['estado' => 'retirada'])
            ->assertNotFound()
            ->assertJsonPath('message', 'Postulación no encontrada.');

        $this->assertDatabaseHas('postulaciones', [
            'id' => $postulacion->id,
            'estado_id' => $this->parametroId('estado_postulacion', 'postulada'),
        ]);
    }

    public function test_instructor_no_puede_cambiar_estado_de_aspirante_devuelve_404(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $postulacion = $this->crearPostulacion($this->convocatoriaAbierta($ficha, $instructor), $aspirante);

        $this->actingAs($instructor)
            ->patchJson("/api/postulaciones/{$postulacion->id}/estado", ['estado' => 'retirada'])
            ->assertNotFound();
    }

    public function test_patch_de_postulacion_inexistente_devuelve_404(): void
    {
        $aspirante = $this->aspirante($this->ficha());

        $this->actingAs($aspirante)
            ->patchJson('/api/postulaciones/999999/estado', ['estado' => 'retirada'])
            ->assertNotFound()
            ->assertJsonPath('message', 'Postulación no encontrada.');
    }

    public function test_patch_ajena_con_cuerpo_invalido_devuelve_404_no_422(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspiranteA = $this->aspirante($ficha);
        $aspiranteB = $this->aspirante($ficha);
        $postulacion = $this->crearPostulacion($this->convocatoriaAbierta($ficha, $instructor), $aspiranteA);

        // El cuerpo vacio seria 422, pero el aislamiento corre ANTES: 404.
        $this->actingAs($aspiranteB)
            ->patchJson("/api/postulaciones/{$postulacion->id}/estado", [])
            ->assertNotFound()
            ->assertJsonPath('message', 'Postulación no encontrada.');
    }

    public function test_patch_sin_sesion_devuelve_401(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $postulacion = $this->crearPostulacion($this->convocatoriaAbierta($ficha, $instructor), $aspirante);

        $this->patchJson("/api/postulaciones/{$postulacion->id}/estado", ['estado' => 'retirada'])
            ->assertUnauthorized();
    }

    public function test_aprendiz_no_puede_cambiar_estado_devuelve_403(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $aprendiz = $this->usuario(Role::Aprendiz, ['subrole' => 'general', 'ficha_id' => $ficha->id]);
        $postulacion = $this->crearPostulacion($this->convocatoriaAbierta($ficha, $instructor), $aspirante);

        $this->actingAs($aprendiz)
            ->patchJson("/api/postulaciones/{$postulacion->id}/estado", ['estado' => 'retirada'])
            ->assertForbidden();
    }

    // ── PATCH: carreras (UPDATE condicionado) ──────────────────────────

    public function test_carrera_en_retiro_devuelve_422(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $postulacion = $this->crearPostulacion($this->convocatoriaAbierta($ficha, $instructor), $aspirante);
        $retiradaId = $this->parametroId('estado_postulacion', 'retirada');

        // Otra peticion retira primero: al enlazar la ruta, la fila ya no esta
        // postulada, pero el modelo conserva el estado leido. El UPDATE
        // condicionado afecta 0 filas -> 422 (nunca 500).
        $evento = 'eloquent.retrieved: '.Postulacion::class;
        Postulacion::retrieved(function () use ($postulacion, $retiradaId) {
            DB::table('postulaciones')->where('id', $postulacion->id)->update([
                'estado_id' => $retiradaId,
                'fecha_retiro' => now(),
                'updated_at' => now(),
            ]);
        });

        try {
            $this->actingAs($aspirante)
                ->patchJson("/api/postulaciones/{$postulacion->id}/estado", ['estado' => 'retirada'])
                ->assertStatus(422)
                ->assertJsonPath('errors.estado.0', 'No se pudo actualizar la postulación: su estado cambió. Inténtalo de nuevo.');
        } finally {
            // Quita SOLO el listener de este evento (no flushEventListeners()).
            Event::forget($evento);
        }
    }

    public function test_carrera_en_reapertura_devuelve_422(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $postulacion = $this->postulacionRetirada($this->convocatoriaAbierta($ficha, $instructor), $aspirante);
        $postuladaId = $this->parametroId('estado_postulacion', 'postulada');

        $evento = 'eloquent.retrieved: '.Postulacion::class;
        Postulacion::retrieved(function () use ($postulacion, $postuladaId) {
            DB::table('postulaciones')->where('id', $postulacion->id)->update([
                'estado_id' => $postuladaId,
                'fecha_retiro' => null,
                'updated_at' => now(),
            ]);
        });

        try {
            $this->actingAs($aspirante)
                ->patchJson("/api/postulaciones/{$postulacion->id}/estado", ['estado' => 'borrador'])
                ->assertStatus(422)
                ->assertJsonPath('errors.estado.0', 'No se pudo actualizar la postulación: su estado cambió. Inténtalo de nuevo.');
        } finally {
            Event::forget($evento);
        }
    }

}
