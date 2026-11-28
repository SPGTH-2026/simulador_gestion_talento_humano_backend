<?php

namespace Tests\Feature;

use App\Models\ConvocatoriaSkill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ConvocatoriaGestionAyudas;
use Tests\TestCase;

/** Porcentajes de la convocatoria: los documentos suman 100 entre sí y las habilidades suman 100 entre sí. */
class ConvocatoriaPorcentajesHabilidadesTest extends TestCase
{
    use RefreshDatabase;
    use ConvocatoriaGestionAyudas;

    private function documentos100(): array
    {
        return [
            ['codigo' => 'hoja_de_vida', 'ponderacion' => 40],
            ['codigo' => 'documento_identidad', 'ponderacion' => 30],
            ['codigo' => 'certificados_academicos', 'ponderacion' => 20],
            ['codigo' => 'certificados_laborales', 'ponderacion' => 10],
        ];
    }

    public function test_documentos_y_habilidades_que_suman_100_cada_uno_se_guardan_con_su_porcentaje(): void
    {
        $instructor = $this->instructor($this->ficha());

        $respuesta = $this->actingAs($instructor)->postJson('/api/convocatorias', [
            'nombre' => 'Con habilidades ponderadas',
            'documentos' => $this->documentos100(),
            'skills' => [
                ['nombre' => 'Excel', 'ponderacion' => 60],
                ['nombre' => 'Word', 'ponderacion' => 40],
            ],
        ])->assertCreated();

        $porcentajes = ConvocatoriaSkill::where('convocatoria_id', $respuesta->json('data.id'))
            ->pluck('ponderacion', 'nombre')
            ->map(fn ($v) => (float) $v)
            ->all();

        $this->assertSame(['Excel' => 60.0, 'Word' => 40.0], $porcentajes);
    }

    public function test_documentos_que_no_suman_100_se_rechazan(): void
    {
        $instructor = $this->instructor($this->ficha());
        $documentos = $this->documentos100();
        $documentos[3]['ponderacion'] = 0;

        $this->actingAs($instructor)->postJson('/api/convocatorias', [
            'nombre' => 'Documentos suman 90',
            'documentos' => $documentos,
        ])->assertStatus(422)->assertJsonValidationErrors('documentos');
    }

    public function test_habilidades_que_no_suman_100_se_rechazan(): void
    {
        $instructor = $this->instructor($this->ficha());

        $this->actingAs($instructor)->postJson('/api/convocatorias', [
            'nombre' => 'Habilidades suman 10',
            'skills' => [
                ['nombre' => 'Excel', 'ponderacion' => 10],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors('skills');
    }

    public function test_una_habilidad_sin_porcentaje_se_rechaza_si_otra_lo_tiene(): void
    {
        $instructor = $this->instructor($this->ficha());

        $this->actingAs($instructor)->postJson('/api/convocatorias', [
            'nombre' => 'Habilidad sin porcentaje',
            'skills' => [
                ['nombre' => 'Excel', 'ponderacion' => 100],
                ['nombre' => 'Word'],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors('skills');
    }

    public function test_cada_bloque_es_independiente_del_otro(): void
    {
        $instructor = $this->instructor($this->ficha());

        // Habilidades ponderadas con documentos sin ponderar: válido.
        $this->actingAs($instructor)->postJson('/api/convocatorias', [
            'nombre' => 'Solo habilidades ponderadas',
            'skills' => [
                ['nombre' => 'Excel', 'ponderacion' => 70],
                ['nombre' => 'Word', 'ponderacion' => 30],
            ],
        ])->assertCreated();

        // Documentos ponderados con habilidades sin ponderar: válido.
        $this->actingAs($instructor)->postJson('/api/convocatorias', [
            'nombre' => 'Solo documentos ponderados',
            'documentos' => $this->documentos100(),
            'skills' => [['nombre' => 'Excel']],
        ])->assertCreated();
    }

    public function test_tres_habilidades_iguales_se_aceptan_aunque_sumen_99_99(): void
    {
        $instructor = $this->instructor($this->ficha());

        $respuesta = $this->actingAs($instructor)->postJson('/api/convocatorias', [
            'nombre' => 'Tres habilidades iguales',
            'skills' => [
                ['nombre' => 'Excel', 'ponderacion' => 33.3333],
                ['nombre' => 'Word', 'ponderacion' => 33.3333],
                ['nombre' => 'SQL', 'ponderacion' => 33.3333],
            ],
        ])->assertCreated();

        // La base guarda dos decimales y las tres valen exactamente lo mismo.
        $porcentajes = ConvocatoriaSkill::where('convocatoria_id', $respuesta->json('data.id'))->pluck('ponderacion')->map(fn ($v) => (float) $v)->unique()->all();
        $this->assertSame([33.33], array_values($porcentajes));
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
