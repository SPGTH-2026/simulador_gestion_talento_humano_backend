<?php

namespace Tests\Feature;

use App\Models\Convocatoria;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ConvocatoriaGestionAyudas;
use Tests\TestCase;

class ConvocatoriaEdicionTest extends TestCase
{
    use RefreshDatabase;
    use ConvocatoriaGestionAyudas;

    public function test_edicion_parcial_en_borrador_conserva_lo_no_enviado(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->crearConvocatoria($ficha, $instructor, ['nombre' => 'Original', 'cargo' => 'Instructor']);

        $respuesta = $this->actingAs($instructor)
            ->patchJson('/api/convocatorias/'.$convocatoria->id, ['nombre' => 'Actualizado'])
            ->assertOk();

        $this->assertSame('Actualizado', $respuesta->json('data.nombre'));
        $this->assertSame('Instructor', $respuesta->json('data.cargo'));
    }

    public function test_edicion_en_publicada_permite_todo(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->crearConvocatoria($ficha, $instructor, [
            'estado_id' => $this->parametroId('estado_convocatoria', 'publicada'),
            'nombre' => 'Publicada',
        ]);

        $respuesta = $this->actingAs($instructor)->patchJson('/api/convocatorias/'.$convocatoria->id, [
            'nombre' => 'Publicada editada',
            'salario_max' => 9000000,
        ])->assertOk();

        $this->assertSame('Publicada editada', $respuesta->json('data.nombre'));
        $this->assertEquals(9000000.0, $respuesta->json('data.salario_max'));
        $this->assertSame('publicada', $respuesta->json('data.estado.codigo'));
    }

    public function test_edicion_en_proceso_responde_422(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->crearConvocatoria($ficha, $instructor, [
            'estado_id' => $this->parametroId('estado_convocatoria', 'en_proceso'),
        ]);

        $this->actingAs($instructor)
            ->patchJson('/api/convocatorias/'.$convocatoria->id, ['nombre' => 'X'])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['estado']]);
    }

    public function test_edicion_en_cerrada_responde_422(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->crearConvocatoria($ficha, $instructor, [
            'estado_id' => $this->parametroId('estado_convocatoria', 'cerrada'),
        ]);

        $this->actingAs($instructor)
            ->patchJson('/api/convocatorias/'.$convocatoria->id, ['nombre' => 'X'])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['estado']]);
    }

    public function test_edicion_rechaza_reservados(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->crearConvocatoria($ficha, $instructor);

        $this->actingAs($instructor)
            ->patchJson('/api/convocatorias/'.$convocatoria->id, ['codigo' => 'OTRO'])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['campos']]);

        $this->assertSame($convocatoria->codigo, $convocatoria->fresh()->codigo);
    }

    public function test_edicion_reemplaza_skills(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->crearConvocatoria($ficha, $instructor);
        $convocatoria->skills()->create(['nombre' => 'Antigua']);

        $respuesta = $this->actingAs($instructor)
            ->patchJson('/api/convocatorias/'.$convocatoria->id, ['skills' => []])
            ->assertOk();

        $this->assertSame([], $respuesta->json('data.skills'));
        $this->assertSame(0, $convocatoria->fresh()->skills()->count());
    }

    public function test_edicion_reemplaza_documentos_opcionales_y_conserva_obligatorios(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);

        $creada = $this->actingAs($instructor)->postJson('/api/convocatorias', [
            'nombre' => 'Docs',
            'documentos' => ['documento_identidad'],
        ])->assertCreated();

        $respuesta = $this->actingAs($instructor)->patchJson('/api/convocatorias/'.$creada->json('data.id'), [
            'documentos' => ['libreta_militar'],
        ])->assertOk();

        // Los obligatorios se mantienen y se suma el opcional elegido.
        $this->assertEqualsCanonicalizing(
            ['hoja_de_vida', 'documento_identidad', 'certificados_academicos', 'certificados_laborales', 'libreta_militar'],
            $this->codigosDocumentos($respuesta->json('data.documentos')),
        );
        $this->assertSame(5, Convocatoria::find($creada->json('data.id'))->documentos()->count());
    }

}
