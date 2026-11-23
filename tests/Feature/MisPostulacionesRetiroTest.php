<?php

namespace Tests\Feature;

use App\Models\Convocatoria;
use App\Models\Postulacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MisPostulacionesAyudas;
use Tests\TestCase;

class MisPostulacionesRetiroTest extends TestCase
{
    use RefreshDatabase;
    use MisPostulacionesAyudas;

    public function test_aspirante_retira_su_postulacion(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $postulacion = $this->crearPostulacion($this->convocatoriaAbierta($ficha, $instructor), $aspirante);

        $respuesta = $this->actingAs($aspirante)
            ->patchJson("/api/postulaciones/{$postulacion->id}/estado", ['estado' => 'retirada'])
            ->assertOk();

        $this->assertSame($postulacion->id, $respuesta->json('data.id'));
        $this->assertSame('retirada', $respuesta->json('data.estado.codigo'));
        $this->assertNotNull($respuesta->json('data.fecha_retiro'));

        $this->assertDatabaseHas('postulaciones', [
            'id' => $postulacion->id,
            'estado_id' => $this->parametroId('estado_postulacion', 'retirada'),
        ]);
        $this->assertNotNull(Postulacion::find($postulacion->id)->fecha_retiro);
    }

    public function test_retiro_cuando_ya_esta_retirada_devuelve_422(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $postulacion = $this->postulacionRetirada($this->convocatoriaAbierta($ficha, $instructor), $aspirante);

        $this->actingAs($aspirante)
            ->patchJson("/api/postulaciones/{$postulacion->id}/estado", ['estado' => 'retirada'])
            ->assertStatus(422)
            ->assertJsonPath('errors.estado.0', 'La postulación ya está en estado retirada.');
    }

    public function test_retiro_con_convocatoria_no_publicada_devuelve_422(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $enProceso = $this->crearConvocatoria($ficha, $instructor, [
            'estado_id' => $this->parametroId('estado_convocatoria', 'en_proceso'),
        ]);
        $postulacion = $this->crearPostulacion($enProceso, $aspirante);

        $this->actingAs($aspirante)
            ->patchJson("/api/postulaciones/{$postulacion->id}/estado", ['estado' => 'retirada'])
            ->assertStatus(422)
            ->assertJsonPath('errors.estado.0', 'No puedes retirar tu postulación: la convocatoria ya no está publicada.');

        $this->assertDatabaseHas('postulaciones', [
            'id' => $postulacion->id,
            'estado_id' => $this->parametroId('estado_postulacion', 'postulada'),
        ]);
    }

    // ── PATCH: reapertura (retirada -> borrador) ───────────────────────

    public function test_reactivar_postulacion_retirada_en_ventana_abierta(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $postulacion = $this->postulacionRetirada($this->convocatoriaAbierta($ficha, $instructor), $aspirante);

        $respuesta = $this->actingAs($aspirante)
            ->patchJson("/api/postulaciones/{$postulacion->id}/estado", ['estado' => 'borrador'])
            ->assertOk();

        $this->assertSame($postulacion->id, $respuesta->json('data.id'));
        $this->assertSame('borrador', $respuesta->json('data.estado.codigo'));
        $this->assertNull($respuesta->json('data.fecha_retiro'));
        $this->assertSame(1, Postulacion::query()
            ->where('convocatoria_id', $postulacion->convocatoria_id)
            ->where('user_id', $aspirante->id)
            ->count());
    }

    /**
     * 'postulada' ya NO se alcanza con el PATCH: solo con el envío, que valida
     * los documentos. El PATCH guía al endpoint correcto.
     */
    public function test_patch_a_postulada_devuelve_422_y_guia_al_envio(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $postulacion = $this->postulacionBorrador($this->convocatoriaAbierta($ficha, $instructor), $aspirante);

        $this->actingAs($aspirante)
            ->patchJson("/api/postulaciones/{$postulacion->id}/estado", ['estado' => 'postulada'])
            ->assertStatus(422)
            ->assertJsonPath('errors.estado.0', 'Para enviar tu postulación usa el endpoint de envío.');

        // Sigue en borrador: el PATCH no lo cambió.
        $this->assertSame(
            $this->parametroId('estado_postulacion', 'borrador'),
            $postulacion->fresh()->estado_id,
        );
    }

    public function test_reactivar_antes_de_apertura_devuelve_422(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->crearConvocatoria($ficha, $instructor, [
            'estado_id' => $this->parametroId('estado_convocatoria', 'publicada'),
            'fecha_apertura' => now('America/Bogota')->addDay()->toDateString(),
            'fecha_cierre' => now('America/Bogota')->addDays(10)->toDateString(),
        ]);
        $postulacion = $this->postulacionRetirada($convocatoria, $aspirante);

        $this->actingAs($aspirante)
            ->patchJson("/api/postulaciones/{$postulacion->id}/estado", ['estado' => 'borrador'])
            ->assertStatus(422)
            ->assertJsonPath(
                'errors.estado.0',
                'La convocatoria aún no está abierta. Abre el '.$convocatoria->fecha_apertura->format('d/m/Y').'.',
            );
    }

    public function test_reactivar_despues_del_cierre_devuelve_422(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->crearConvocatoria($ficha, $instructor, [
            'estado_id' => $this->parametroId('estado_convocatoria', 'publicada'),
            'fecha_apertura' => now('America/Bogota')->subDays(10)->toDateString(),
            'fecha_cierre' => now('America/Bogota')->subDay()->toDateString(),
        ]);
        $postulacion = $this->postulacionRetirada($convocatoria, $aspirante);

        $this->actingAs($aspirante)
            ->patchJson("/api/postulaciones/{$postulacion->id}/estado", ['estado' => 'borrador'])
            ->assertStatus(422)
            ->assertJsonPath(
                'errors.estado.0',
                'La convocatoria ya cerró el '.$convocatoria->fecha_cierre->format('d/m/Y').'.',
            );
    }

    public function test_reactivar_con_convocatoria_no_publicada_devuelve_404(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $borrador = $this->crearConvocatoria($ficha, $instructor);
        $postulacion = $this->postulacionRetirada($borrador, $aspirante);

        $this->actingAs($aspirante)
            ->patchJson("/api/postulaciones/{$postulacion->id}/estado", ['estado' => 'borrador'])
            ->assertNotFound()
            ->assertJsonPath('message', 'Convocatoria no encontrada.');
    }

    // ── PATCH: validación del cuerpo ───────────────────────────────────

}
