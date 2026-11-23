<?php

namespace Tests\Feature;

use App\Models\Convocatoria;
use App\Models\Postulacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\PostulacionBorradorAyudas;
use Tests\TestCase;

class PostulacionBorradorVentanaTest extends TestCase
{
    use RefreshDatabase;
    use PostulacionBorradorAyudas;

    public function test_enviar_con_la_ventana_cerrada_devuelve_422(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->crearConvocatoria($ficha, $instructor, [
            'estado_id' => $this->parametroId('estado_convocatoria', 'publicada'),
            'fecha_apertura' => now('America/Bogota')->subDays(10)->toDateString(),
            'fecha_cierre' => now('America/Bogota')->subDay()->toDateString(),
        ]);
        $postulacion = $this->crearPostulacion($convocatoria, $aspirante);

        $this->actingAs($aspirante)
            ->postJson("/api/postulaciones/{$postulacion->id}/enviar")
            ->assertStatus(422)
            ->assertJsonPath(
                'errors.postulacion.0',
                'La convocatoria ya cerró el '.$convocatoria->fecha_cierre->format('d/m/Y').'.',
            );
    }

    public function test_enviar_con_la_convocatoria_no_publicada_devuelve_404(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->crearConvocatoria($ficha, $instructor); // borrador
        $postulacion = $this->crearPostulacion($convocatoria, $aspirante);

        $this->actingAs($aspirante)
            ->postJson("/api/postulaciones/{$postulacion->id}/enviar")
            ->assertNotFound()
            ->assertJsonPath('message', 'Convocatoria no encontrada.');
    }

    public function test_carrera_en_el_envio_devuelve_422(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->convocatoriaAbierta($ficha, $instructor);
        $postulacion = $this->crearPostulacion($convocatoria, $aspirante);
        $postuladaId = $this->parametroId('estado_postulacion', 'postulada');

        // Otra petición envía/gana la carrera antes del UPDATE condicionado.
        $evento = 'eloquent.retrieved: '.Postulacion::class;
        Postulacion::retrieved(fn () => DB::table('postulaciones')->where('id', $postulacion->id)->update([
            'estado_id' => $postuladaId,
            'updated_at' => now(),
        ]));

        try {
            $this->actingAs($aspirante)
                ->postJson("/api/postulaciones/{$postulacion->id}/enviar")
                ->assertStatus(422)
                ->assertJsonPath('errors.postulacion.0', 'No se pudo enviar la postulación: su estado cambió. Inténtalo de nuevo.');
        } finally {
            Event::forget($evento);
        }
    }

    // ── El borrador se puede completar (documentos, skills, estado) ─────

    public function test_el_borrador_es_editable_y_su_get_lo_refleja(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->convocatoriaAbierta($ficha, $instructor);
        $convocatoria->documentos()->attach($this->parametroId('tipo_documento', 'hoja_de_vida'), ['obligatorio' => true]);
        $postulacion = $this->crearPostulacion($convocatoria, $aspirante);

        $this->actingAs($aspirante)
            ->getJson("/api/postulaciones/{$postulacion->id}/documentos")
            ->assertOk()
            ->assertJsonPath('data.editable', true)
            ->assertJsonPath('data.completo', false)
            ->assertJsonPath('data.postulacion.estado.codigo', 'borrador');

        $this->subirDocumento($aspirante, $postulacion, 'hoja_de_vida');

        $this->actingAs($aspirante)
            ->getJson("/api/postulaciones/{$postulacion->id}/documentos")
            ->assertOk()
            ->assertJsonPath('data.completo', true);
    }

}
