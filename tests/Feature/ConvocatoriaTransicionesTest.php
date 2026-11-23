<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ConvocatoriaEstadoAyudas;
use Tests\TestCase;

class ConvocatoriaTransicionesTest extends TestCase
{
    use RefreshDatabase;
    use ConvocatoriaEstadoAyudas;

    public function test_publicar_borrador_es_valido(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->crearConvocatoria($ficha, $instructor, $this->publicable());

        $respuesta = $this->actingAs($instructor)
            ->patchJson('/api/convocatorias/'.$convocatoria->id.'/estado', ['estado' => 'publicada'])
            ->assertOk();

        $this->assertSame('publicada', $respuesta->json('data.estado.codigo'));
        $this->assertSame(
            $this->parametroId('estado_convocatoria', 'publicada'),
            (int) $convocatoria->fresh()->estado_id,
        );
    }

    public function test_publicada_a_en_proceso_es_valido(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->conEstado($ficha, $instructor, 'publicada');

        $respuesta = $this->actingAs($instructor)
            ->patchJson('/api/convocatorias/'.$convocatoria->id.'/estado', ['estado' => 'en_proceso'])
            ->assertOk();

        $this->assertSame('en_proceso', $respuesta->json('data.estado.codigo'));
    }

    public function test_en_proceso_a_cerrada_es_valido(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->conEstado($ficha, $instructor, 'en_proceso');

        $respuesta = $this->actingAs($instructor)
            ->patchJson('/api/convocatorias/'.$convocatoria->id.'/estado', ['estado' => 'cerrada'])
            ->assertOk();

        $this->assertSame('cerrada', $respuesta->json('data.estado.codigo'));
    }

    public function test_repetir_el_estado_actual_responde_422(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->conEstado($ficha, $instructor, 'borrador');

        $this->actingAs($instructor)
            ->patchJson('/api/convocatorias/'.$convocatoria->id.'/estado', ['estado' => 'borrador'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['estado']);
    }

    public function test_saltar_un_paso_responde_422_con_mensaje_claro(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->crearConvocatoria($ficha, $instructor, $this->publicable());

        $respuesta = $this->actingAs($instructor)
            ->patchJson('/api/convocatorias/'.$convocatoria->id.'/estado', ['estado' => 'cerrada'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['estado']);

        $this->assertStringContainsString(
            'No se puede pasar de borrador a cerrada. El siguiente estado permitido es publicada.',
            $respuesta->json('errors.estado.0'),
        );
    }

    public function test_retroceder_responde_422(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->conEstado($ficha, $instructor, 'publicada');

        $this->actingAs($instructor)
            ->patchJson('/api/convocatorias/'.$convocatoria->id.'/estado', ['estado' => 'borrador'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['estado']);
    }

    public function test_cerrada_no_admite_mas_cambios(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->conEstado($ficha, $instructor, 'cerrada');

        $this->actingAs($instructor)
            ->patchJson('/api/convocatorias/'.$convocatoria->id.'/estado', ['estado' => 'en_proceso'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['estado']);
    }

    // ── Validación al publicar ─────────────────────────────────────────

}
