<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ConvocatoriaEstadoAyudas;
use Tests\TestCase;

class ConvocatoriaValidacionPublicarTest extends TestCase
{
    use RefreshDatabase;
    use ConvocatoriaEstadoAyudas;

    public function test_publicar_sin_campos_responde_un_error_por_campo(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->crearConvocatoria($ficha, $instructor);

        $this->actingAs($instructor)
            ->patchJson('/api/convocatorias/'.$convocatoria->id.'/estado', ['estado' => 'publicada'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['cargo', 'area', 'descripcion', 'fecha_apertura', 'fecha_cierre'])
            ->assertJsonMissingValidationErrors(['nombre']);
    }

    public function test_publicar_con_campo_en_blanco_responde_422(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->crearConvocatoria($ficha, $instructor, $this->publicable([
            'cargo' => '   ',
        ]));

        $this->actingAs($instructor)
            ->patchJson('/api/convocatorias/'.$convocatoria->id.'/estado', ['estado' => 'publicada'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['cargo']);
    }

    public function test_publicar_con_fecha_cierre_en_el_pasado_responde_422(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $ayer = now('America/Bogota')->subDay()->toDateString();
        $convocatoria = $this->crearConvocatoria($ficha, $instructor, $this->publicable([
            'fecha_apertura' => $ayer,
            'fecha_cierre' => $ayer,
        ]));

        $this->actingAs($instructor)
            ->patchJson('/api/convocatorias/'.$convocatoria->id.'/estado', ['estado' => 'publicada'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['fecha_cierre'])
            ->assertJsonMissingValidationErrors(['fecha_apertura']);
    }

    public function test_publicar_con_fecha_cierre_hoy_es_valido(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $hoy = now('America/Bogota')->toDateString();
        $convocatoria = $this->crearConvocatoria($ficha, $instructor, $this->publicable([
            'fecha_apertura' => $hoy,
            'fecha_cierre' => $hoy,
        ]));

        $this->actingAs($instructor)
            ->patchJson('/api/convocatorias/'.$convocatoria->id.'/estado', ['estado' => 'publicada'])
            ->assertOk();
    }

    public function test_respuesta_incluye_el_detalle(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->crearConvocatoria($ficha, $instructor, $this->publicable());

        $this->actingAs($instructor)
            ->patchJson('/api/convocatorias/'.$convocatoria->id.'/estado', ['estado' => 'publicada'])
            ->assertOk()
            ->assertJsonStructure(['data' => [
                'id', 'codigo', 'ficha', 'nombre', 'cargo', 'area', 'descripcion', 'estado',
                'fecha_apertura', 'fecha_cierre',
            ]]);
    }

}
