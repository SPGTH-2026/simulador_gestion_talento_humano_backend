<?php

namespace Tests\Feature;

use App\Models\Postulacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\PostulacionAspiranteAyudas;
use Tests\TestCase;

class PostulacionAspiranteVentanaTest extends TestCase
{
    use RefreshDatabase;
    use PostulacionAspiranteAyudas;

    public function test_no_se_puede_postular_antes_de_la_apertura_devuelve_422(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->crearConvocatoria($ficha, $instructor, [
            'estado_id' => $this->parametroId('estado_convocatoria', 'publicada'),
            'fecha_apertura' => now('America/Bogota')->addDay()->toDateString(),
            'fecha_cierre' => now('America/Bogota')->addDays(10)->toDateString(),
        ]);

        $this->actingAs($aspirante)
            ->postJson("/api/convocatorias/{$convocatoria->id}/postulaciones")
            ->assertStatus(422)
            ->assertJsonPath(
                'errors.postulacion.0',
                'La convocatoria aún no está abierta. Abre el '.$convocatoria->fecha_apertura->format('d/m/Y').'.',
            );
    }

    public function test_no_se_puede_postular_despues_del_cierre_devuelve_422(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->crearConvocatoria($ficha, $instructor, [
            'estado_id' => $this->parametroId('estado_convocatoria', 'publicada'),
            'fecha_apertura' => now('America/Bogota')->subDays(10)->toDateString(),
            'fecha_cierre' => now('America/Bogota')->subDay()->toDateString(),
        ]);

        $this->actingAs($aspirante)
            ->postJson("/api/convocatorias/{$convocatoria->id}/postulaciones")
            ->assertStatus(422)
            ->assertJsonPath(
                'errors.postulacion.0',
                'La convocatoria ya cerró el '.$convocatoria->fecha_cierre->format('d/m/Y').'.',
            );
    }

    public function test_se_puede_postular_el_dia_de_apertura_inclusive(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->crearConvocatoria($ficha, $instructor, [
            'estado_id' => $this->parametroId('estado_convocatoria', 'publicada'),
            'fecha_apertura' => now('America/Bogota')->toDateString(),
            'fecha_cierre' => now('America/Bogota')->addDays(5)->toDateString(),
        ]);

        $this->actingAs($aspirante)
            ->postJson("/api/convocatorias/{$convocatoria->id}/postulaciones")
            ->assertCreated();
    }

    public function test_se_puede_postular_el_dia_de_cierre_inclusive(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->crearConvocatoria($ficha, $instructor, [
            'estado_id' => $this->parametroId('estado_convocatoria', 'publicada'),
            'fecha_apertura' => now('America/Bogota')->subDays(5)->toDateString(),
            'fecha_cierre' => now('America/Bogota')->toDateString(),
        ]);

        $this->actingAs($aspirante)
            ->postJson("/api/convocatorias/{$convocatoria->id}/postulaciones")
            ->assertCreated();
    }

    // ── Postulación previa ─────────────────────────────────────────────

    public function test_ya_postulado_devuelve_422(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->convocatoriaAbierta($ficha, $instructor);
        $this->crearPostulacion($convocatoria, $aspirante);

        $this->actingAs($aspirante)
            ->postJson("/api/convocatorias/{$convocatoria->id}/postulaciones")
            ->assertStatus(422)
            ->assertJsonPath('errors.postulacion.0', 'Ya estás postulado en esta convocatoria.');

        $this->assertDatabaseCount('postulaciones', 1);
    }

    public function test_re_postulacion_tras_retiro_devuelve_200_y_reabre_el_borrador(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->convocatoriaAbierta($ficha, $instructor);
        $previa = $this->crearPostulacion($convocatoria, $aspirante, [
            'estado_id' => $this->parametroId('estado_postulacion', 'retirada'),
            'fecha_retiro' => now()->subDay(),
        ]);

        $respuesta = $this->actingAs($aspirante)
            ->postJson("/api/convocatorias/{$convocatoria->id}/postulaciones")
            ->assertOk();

        $this->assertSame($previa->id, $respuesta->json('data.id'));
        $this->assertSame('borrador', $respuesta->json('data.estado.codigo'));
        $this->assertNull($respuesta->json('data.fecha_retiro'));

        // No se crea otra fila: se reabrió la misma en borrador.
        $this->assertSame(1, Postulacion::query()
            ->where('convocatoria_id', $convocatoria->id)
            ->where('user_id', $aspirante->id)
            ->count());
    }

    // ── Aislamiento ────────────────────────────────────────────────────

}
