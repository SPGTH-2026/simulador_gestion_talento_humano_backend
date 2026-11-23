<?php

namespace Tests\Feature;

use App\Models\Convocatoria;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ConvocatoriaGestionAyudas;
use Tests\TestCase;

class ConvocatoriaValidacionCrearTest extends TestCase
{
    use RefreshDatabase;
    use ConvocatoriaGestionAyudas;

    public function test_nombre_obligatorio_en_espanol(): void
    {
        $instructor = $this->instructor($this->ficha());

        $this->actingAs($instructor)
            ->postJson('/api/convocatorias', [])
            ->assertStatus(422)
            ->assertJsonPath('errors.nombre.0', 'El campo nombre es obligatorio.');
    }

    public function test_rechaza_campos_reservados(): void
    {
        $instructor = $this->instructor($this->ficha());

        $this->actingAs($instructor)
            ->postJson('/api/convocatorias', ['nombre' => 'X', 'codigo' => 'CONV-2026-999'])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['campos']]);
    }

    public function test_rechaza_clasificacion_invalida(): void
    {
        $instructor = $this->instructor($this->ficha());

        $this->actingAs($instructor)
            ->postJson('/api/convocatorias', ['nombre' => 'X', 'tipo_empleo' => 'no_existe'])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['tipo_empleo']]);
    }

    public function test_rechaza_codigo_e_id_juntos(): void
    {
        $instructor = $this->instructor($this->ficha());

        $this->actingAs($instructor)->postJson('/api/convocatorias', [
            'nombre' => 'X',
            'tipo_empleo' => 'medio_tiempo',
            'tipo_empleo_id' => $this->parametroId('tipo_empleo', 'tiempo_completo'),
        ])->assertStatus(422)->assertJsonStructure(['errors' => ['tipo_empleo']]);
    }

    public function test_rechaza_salario_max_menor_que_min(): void
    {
        $instructor = $this->instructor($this->ficha());

        $this->actingAs($instructor)->postJson('/api/convocatorias', [
            'nombre' => 'X',
            'salario_min' => 5000000,
            'salario_max' => 4000000,
        ])->assertStatus(422)->assertJsonStructure(['errors' => ['salario_max']]);
    }

    public function test_rechaza_fecha_cierre_anterior_a_apertura(): void
    {
        $instructor = $this->instructor($this->ficha());

        $this->actingAs($instructor)->postJson('/api/convocatorias', [
            'nombre' => 'X',
            'fecha_apertura' => '2026-11-15',
            'fecha_cierre' => '2026-10-15',
        ])->assertStatus(422)->assertJsonStructure(['errors' => ['fecha_cierre']]);
    }

    public function test_skills_se_recortan_y_se_descartan_las_vacias(): void
    {
        $instructor = $this->instructor($this->ficha());

        // Se recortan los espacios sobrantes y las vacías se descartan.
        $respuesta = $this->actingAs($instructor)->postJson('/api/convocatorias', [
            'nombre' => 'X',
            'skills' => ['  Excel  ', '', 'Power  BI'],
        ])->assertCreated();

        $this->assertSame(['Excel', 'Power BI'], $respuesta->json('data.skills'));
    }

    public function test_skills_repetidas_por_mayusculas_devuelve_422(): void
    {
        $instructor = $this->instructor($this->ficha());

        // Una skill repetida (sin distinguir mayúsculas ni espacios sobrantes)
        // es un error: no se guarda en silencio.
        $this->actingAs($instructor)
            ->postJson('/api/convocatorias', [
                'nombre' => 'X',
                'skills' => ['  Excel  ', 'excel', 'Power BI'],
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.skills.0', 'No puedes repetir la misma skill.');

        $this->assertSame(0, Convocatoria::count());
    }

    public function test_rechaza_mas_de_20_skills(): void
    {
        $instructor = $this->instructor($this->ficha());

        $skills = array_map(fn (int $i) => "Skill {$i}", range(1, 21));

        $this->actingAs($instructor)
            ->postJson('/api/convocatorias', ['nombre' => 'X', 'skills' => $skills])
            ->assertStatus(422)
            ->assertJsonPath('errors.skills.0', 'No puedes registrar más de 20 skills.');
    }

    public function test_rechaza_skill_demasiado_larga(): void
    {
        $instructor = $this->instructor($this->ficha());

        $this->actingAs($instructor)->postJson('/api/convocatorias', [
            'nombre' => 'X',
            'skills' => [str_repeat('a', 101)],
        ])->assertStatus(422)->assertJsonStructure(['errors' => ['skills.0']]);
    }

    public function test_rechaza_documento_invalido(): void
    {
        $instructor = $this->instructor($this->ficha());

        $this->actingAs($instructor)->postJson('/api/convocatorias', [
            'nombre' => 'X',
            'documentos' => ['no_existe'],
        ])->assertStatus(422)->assertJsonStructure(['errors' => ['documentos.0']]);
    }

    // ── Listado ────────────────────────────────────────────────────────

}
