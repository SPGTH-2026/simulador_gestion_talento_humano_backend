<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ConvocatoriaGestionAyudas;
use Tests\TestCase;

class ConvocatoriaDetalleTest extends TestCase
{
    use RefreshDatabase;
    use ConvocatoriaGestionAyudas;

    public function test_detalle_completo_incluye_skills_y_documentos(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);

        $creada = $this->actingAs($instructor)->postJson('/api/convocatorias', [
            'nombre' => 'Detalle',
            'skills' => ['Excel avanzado'],
        ])->assertCreated();

        $respuesta = $this->actingAs($instructor)
            ->getJson('/api/convocatorias/'.$creada->json('data.id'))
            ->assertOk();

        $respuesta->assertJsonStructure(['data' => [
            'id', 'codigo', 'ficha', 'nombre', 'cargo', 'area', 'descripcion', 'estado',
            'vacantes', 'salario_min', 'salario_max', 'tipo_convocatoria', 'tipo_empleo',
            'tipo_contrato', 'modalidad', 'funciones', 'responsabilidades', 'perfil_requerido',
            'formacion', 'experiencia', 'competencias', 'conocimientos_especificos',
            'fecha_apertura', 'fecha_cierre', 'fecha_evaluacion', 'skills', 'documentos',
        ]]);
        $this->assertSame(['Excel avanzado'], $respuesta->json('data.skills'));
    }

    public function test_detalle_fuera_de_alcance_responde_404(): void
    {
        $fichaA = $this->ficha('3173334');
        $fichaB = $this->ficha('2631620');
        $instructorA = $this->instructor($fichaA);
        $instructorB = $this->instructor($fichaB);
        $ajena = $this->crearConvocatoria($fichaB, $instructorB, ['codigo' => 'B-1']);

        $this->actingAs($instructorA)
            ->getJson('/api/convocatorias/'.$ajena->id)
            ->assertNotFound();
    }

    public function test_aspirante_no_ve_detalle_de_borrador(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $borrador = $this->crearConvocatoria($ficha, $instructor, ['codigo' => 'BOR-1']);

        $this->actingAs($aspirante)
            ->getJson('/api/convocatorias/'.$borrador->id)
            ->assertNotFound();
    }

    public function test_aspirante_ve_detalle_de_publicada(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $publicada = $this->crearConvocatoria($ficha, $instructor, [
            'codigo' => 'PUB-1',
            'estado_id' => $this->parametroId('estado_convocatoria', 'publicada'),
        ]);

        // El Aspirante ve el detalle, pero NO el código: es un dato de gestión.
        $this->actingAs($aspirante)
            ->getJson('/api/convocatorias/'.$publicada->id)
            ->assertOk()
            ->assertJsonPath('data.codigo', null)
            ->assertJsonPath('data.id', $publicada->id);

        // Quien sí puede gestionar lo recibe.
        $this->actingAs($instructor)
            ->getJson('/api/convocatorias/'.$publicada->id)
            ->assertOk()
            ->assertJsonPath('data.codigo', 'PUB-1');
    }

    // ── Edición ────────────────────────────────────────────────────────

}
