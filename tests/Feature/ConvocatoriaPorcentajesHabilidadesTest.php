<?php

namespace Tests\Feature;

use App\Models\ConvocatoriaSkill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ConvocatoriaGestionAyudas;
use Tests\TestCase;

/** Porcentajes de las habilidades de la convocatoria: junto con los de los documentos suman 100 %. */
class ConvocatoriaPorcentajesHabilidadesTest extends TestCase
{
    use RefreshDatabase;
    use ConvocatoriaGestionAyudas;

    /** Documentos 80 % + habilidades 20 % = 100 %. */
    private function documentos80(): array
    {
        return [
            ['codigo' => 'hoja_de_vida', 'ponderacion' => 30],
            ['codigo' => 'documento_identidad', 'ponderacion' => 20],
            ['codigo' => 'certificados_academicos', 'ponderacion' => 20],
            ['codigo' => 'certificados_laborales', 'ponderacion' => 10],
        ];
    }

    public function test_documentos_y_habilidades_que_suman_100_se_guardan_con_su_porcentaje(): void
    {
        $instructor = $this->instructor($this->ficha());

        $respuesta = $this->actingAs($instructor)->postJson('/api/convocatorias', [
            'nombre' => 'Con habilidades ponderadas',
            'documentos' => $this->documentos80(),
            'skills' => [
                ['nombre' => 'Excel', 'ponderacion' => 10],
                ['nombre' => 'Word', 'ponderacion' => 10],
            ],
        ])->assertCreated();

        $porcentajes = ConvocatoriaSkill::where('convocatoria_id', $respuesta->json('data.id'))
            ->pluck('ponderacion', 'nombre')
            ->map(fn ($v) => (float) $v)
            ->all();

        $this->assertSame(['Excel' => 10.0, 'Word' => 10.0], $porcentajes);
    }

    public function test_documentos_y_habilidades_que_no_suman_100_se_rechazan(): void
    {
        $instructor = $this->instructor($this->ficha());

        $this->actingAs($instructor)->postJson('/api/convocatorias', [
            'nombre' => 'Suma 90',
            'documentos' => $this->documentos80(),
            'skills' => [
                ['nombre' => 'Excel', 'ponderacion' => 10],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors('documentos');
    }

    public function test_una_habilidad_sin_porcentaje_se_rechaza_si_los_documentos_tienen_porcentaje(): void
    {
        $instructor = $this->instructor($this->ficha());

        $this->actingAs($instructor)->postJson('/api/convocatorias', [
            'nombre' => 'Habilidad sin porcentaje',
            'documentos' => $this->documentos80(),
            'skills' => [
                ['nombre' => 'Excel'],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors('skills');
    }

    public function test_habilidades_sin_porcentaje_siguen_siendo_validas_sin_ponderar_documentos(): void
    {
        $instructor = $this->instructor($this->ficha());

        $this->actingAs($instructor)->postJson('/api/convocatorias', [
            'nombre' => 'Sin ponderar',
            'skills' => [
                ['nombre' => 'Excel'],
            ],
        ])->assertCreated();
    }

    public function test_un_porcentaje_fuera_de_rango_se_rechaza(): void
    {
        $instructor = $this->instructor($this->ficha());

        $this->actingAs($instructor)->postJson('/api/convocatorias', [
            'nombre' => 'Porcentaje 150',
            'skills' => [
                ['nombre' => 'Excel', 'ponderacion' => 150],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors('skills.0');
    }
}
