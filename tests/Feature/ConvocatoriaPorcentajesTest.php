<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ConvocatoriaGestionAyudas;
use Tests\TestCase;

class ConvocatoriaPorcentajesTest extends TestCase
{
    use RefreshDatabase;
    use ConvocatoriaGestionAyudas;

    public function test_crear_con_porcentajes_de_documentos_que_suman_100(): void
    {
        $instructor = $this->instructor($this->ficha());

        $respuesta = $this->actingAs($instructor)->postJson('/api/convocatorias', [
            'nombre' => 'Con porcentajes',
            'documentos' => [
                ['codigo' => 'hoja_de_vida', 'ponderacion' => 40],
                ['codigo' => 'documento_identidad', 'ponderacion' => 30],
                ['codigo' => 'certificados_academicos', 'ponderacion' => 20],
                ['codigo' => 'certificados_laborales', 'ponderacion' => 10],
            ],
        ])->assertCreated();

        $pesos = collect($respuesta->json('data.documentos'))
            ->mapWithKeys(fn ($d) => [$d['codigo'] => $d['ponderacion']])
            ->all();

        $this->assertEquals(40, $pesos['hoja_de_vida']);
        $this->assertEquals(10, $pesos['certificados_laborales']);
    }

    public function test_porcentajes_que_no_suman_100_se_rechazan(): void
    {
        $instructor = $this->instructor($this->ficha());

        $this->actingAs($instructor)->postJson('/api/convocatorias', [
            'nombre' => 'Porcentajes malos',
            'documentos' => [
                ['codigo' => 'hoja_de_vida', 'ponderacion' => 50],
                ['codigo' => 'documento_identidad', 'ponderacion' => 30],
                ['codigo' => 'certificados_academicos', 'ponderacion' => 10],
                ['codigo' => 'certificados_laborales', 'ponderacion' => 5],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors('documentos');
    }

    public function test_porcentajes_incompletos_se_rechazan(): void
    {
        $instructor = $this->instructor($this->ficha());

        $this->actingAs($instructor)->postJson('/api/convocatorias', [
            'nombre' => 'Porcentajes incompletos',
            'documentos' => [
                ['codigo' => 'hoja_de_vida', 'ponderacion' => 100],
                ['codigo' => 'documento_identidad'],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors('documentos');
    }

    public function test_sin_porcentajes_la_convocatoria_queda_sin_configurar(): void
    {
        $instructor = $this->instructor($this->ficha());

        $respuesta = $this->actingAs($instructor)->postJson('/api/convocatorias', [
            'nombre' => 'Sin porcentajes',
            'documentos' => ['hoja_de_vida', 'documento_identidad'],
        ])->assertCreated();

        foreach ($respuesta->json('data.documentos') as $documento) {
            $this->assertNull($documento['ponderacion']);
        }
    }

}
