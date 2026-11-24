<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Convocatoria;
use App\Models\Postulacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\PostulacionAspiranteAyudas;
use Tests\TestCase;

class PostulacionAspiranteReglasTest extends TestCase
{
    use RefreshDatabase;
    use PostulacionAspiranteAyudas;

    public function test_convocatoria_de_otra_ficha_devuelve_404(): void
    {
        $fichaA = $this->ficha('3173334');
        $fichaB = $this->ficha('2631620');
        $instructorB = $this->instructor($fichaB);
        $aspiranteA = $this->aspirante($fichaA);
        $convocatoriaB = $this->convocatoriaAbierta($fichaB, $instructorB);

        $this->actingAs($aspiranteA)
            ->postJson("/api/convocatorias/{$convocatoriaB->id}/postulaciones")
            ->assertNotFound();
    }

    public function test_convocatoria_inexistente_devuelve_404(): void
    {
        $aspirante = $this->aspirante($this->ficha());

        $this->actingAs($aspirante)
            ->postJson('/api/convocatorias/999999/postulaciones')
            ->assertNotFound()
            ->assertJsonPath('message', 'Convocatoria no encontrada.');
    }

    public function test_aspirante_sin_ficha_no_puede_postularse_devuelve_403(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspiranteSinFicha = $this->usuario(Role::Aspirante);
        $convocatoria = $this->convocatoriaAbierta($ficha, $instructor);

        $this->actingAs($aspiranteSinFicha)
            ->postJson("/api/convocatorias/{$convocatoria->id}/postulaciones")
            ->assertForbidden()
            ->assertJsonPath('code', 'ficha_requerida');
    }

    // ── Documentos (no se exigen al crear el borrador) ─────────────────

    public function test_crear_el_borrador_no_exige_documentos(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->convocatoriaAbierta($ficha, $instructor);
        $convocatoria->documentos()->attach(
            $this->parametroId('tipo_documento', 'hoja_de_vida'),
            ['obligatorio' => true],
        );

        $this->actingAs($aspirante)
            ->postJson("/api/convocatorias/{$convocatoria->id}/postulaciones")
            ->assertCreated();
    }

    // ── Respaldo del UNIQUE: carreras ──────────────────────────────────

    public function test_carrera_por_unique_devuelve_422(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->convocatoriaAbierta($ficha, $instructor);

        // Simula otra petición que gana la carrera justo antes del INSERT real.
        $evento = 'eloquent.creating: '.Postulacion::class;
        Postulacion::creating(function (Postulacion $modelo) {
            DB::table('postulaciones')->insert([
                'convocatoria_id' => $modelo->convocatoria_id,
                'user_id' => $modelo->user_id,
                'estado_id' => $modelo->estado_id,
                'fecha_postulacion' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        try {
            $this->actingAs($aspirante)
                ->postJson("/api/convocatorias/{$convocatoria->id}/postulaciones")
                ->assertStatus(422)
                ->assertJsonPath('errors.postulacion.0', 'Ya estás postulado en esta convocatoria.');
        } finally {
            // Quita SOLO el listener de este evento (no flushEventListeners()).
            Event::forget($evento);
        }
    }

    public function test_reactivacion_concurrente_devuelve_422(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->convocatoriaAbierta($ficha, $instructor);
        $previa = $this->crearPostulacion($convocatoria, $aspirante, [
            'estado_id' => $this->parametroId('estado_postulacion', 'retirada'),
            'fecha_retiro' => now()->subDay(),
        ]);
        $postuladaId = $this->parametroId('estado_postulacion', 'postulada');

        // Otra petición reactiva primero: al leer la postulación, la fila deja de
        // estar retirada. El UPDATE condicionado afecta 0 filas -> 422 (nunca 500).
        $evento = 'eloquent.retrieved: '.Postulacion::class;
        Postulacion::retrieved(function () use ($previa, $postuladaId) {
            DB::table('postulaciones')->where('id', $previa->id)->update([
                'estado_id' => $postuladaId,
                'fecha_retiro' => null,
                'updated_at' => now(),
            ]);
        });

        try {
            $this->actingAs($aspirante)
                ->postJson("/api/convocatorias/{$convocatoria->id}/postulaciones")
                ->assertStatus(422)
                ->assertJsonPath('errors.postulacion.0', 'Ya estás postulado en esta convocatoria.');
        } finally {
            Event::forget($evento);
        }
    }

}
