<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\RondasCorreccionAyudas;
use Tests\TestCase;

/**
 * El cumplimiento reparte 90 puntos entre los documentos y 10 entre las habilidades que pide la convocatoria. Una habilidad solo
 * suma si el aspirante la declaró y el evaluador la marcó «Cumple»; sin habilidades declaradas el tope es 90.
 */
class CumplimientoHabilidadesTest extends TestCase
{
    use RefreshDatabase;
    use RondasCorreccionAyudas;

    private function guardar(array $perfil)
    {
        return $this->actingAs($this->evaluador)->putJson("/api/evaluaciones/{$this->postulacion->id}", [
            'estado' => 'en_proceso',
            'criterios' => [['criterio' => 'Evaluación', 'puntaje' => 0, 'ponderacion' => 1]],
            'perfil' => $perfil,
        ]);
    }

    public function test_los_documentos_valen_90_y_las_habilidades_declaradas_y_marcadas_suman_hasta_10(): void
    {
        $convocatoria = $this->postulacion->convocatoria;
        $excel = $convocatoria->skills()->create(['nombre' => 'Excel', 'ponderacion' => 50]);
        $word = $convocatoria->skills()->create(['nombre' => 'Word', 'ponderacion' => 50]);
        $this->postulacion->skills()->create(['convocatoria_skill_id' => $excel->id, 'nombre' => 'Excel']);

        $documentos = ['doc-'.$this->hoja => true, 'doc-'.$this->identidad => true];

        // Documentos cumplidos, habilidad sin marcar: 90 %.
        $this->guardar($documentos + ['skill-'.$excel->id => false])
            ->assertOk()->assertJsonPath('data.evaluacion.cumplimiento', 90);

        // La habilidad declarada marcada «Cumple» vale la mitad del bloque (5 puntos): 95 %.
        $this->guardar($documentos + ['skill-'.$excel->id => true])
            ->assertOk()->assertJsonPath('data.evaluacion.cumplimiento', 95);

        // Una habilidad que el aspirante NO declaró no suma aunque llegue marcada.
        $this->guardar($documentos + ['skill-'.$excel->id => true, 'skill-'.$word->id => true])
            ->assertOk()->assertJsonPath('data.evaluacion.cumplimiento', 95);

        $this->actingAs($this->evaluador)->getJson("/api/evaluaciones/{$this->postulacion->id}")
            ->assertOk()
            ->assertJsonPath('data.puntos.bloque_documentos', 90)
            ->assertJsonPath('data.puntos.bloque_habilidades', 10)
            ->assertJsonPath('data.puntos.habilidades.'.$excel->id, 5);
    }

    public function test_sin_habilidades_en_la_convocatoria_los_documentos_valen_100(): void
    {
        $this->guardar(['doc-'.$this->hoja => true, 'doc-'.$this->identidad => true])
            ->assertOk()->assertJsonPath('data.evaluacion.cumplimiento', 100);
    }

    public function test_los_porcentajes_del_gestor_quedan_dentro_del_10_por_ciento(): void
    {
        // 50 % / 25 % / 25 % entre las habilidades: 5 %, 2,5 % y 2,5 % de la evaluación (suman el 10 % del bloque).
        $convocatoria = $this->postulacion->convocatoria;
        $uno = $convocatoria->skills()->create(['nombre' => 'Excel', 'ponderacion' => 50]);
        $dos = $convocatoria->skills()->create(['nombre' => 'Word', 'ponderacion' => 25]);
        $tres = $convocatoria->skills()->create(['nombre' => 'SQL', 'ponderacion' => 25]);

        $puntos = $this->actingAs($this->evaluador)->getJson("/api/evaluaciones/{$this->postulacion->id}")->assertOk()->json('data.puntos');

        $this->assertEqualsWithDelta(5.0, $puntos['habilidades'][$uno->id], 0.0001);
        $this->assertEqualsWithDelta(2.5, $puntos['habilidades'][$dos->id], 0.0001);
        $this->assertEqualsWithDelta(2.5, $puntos['habilidades'][$tres->id], 0.0001);
        $this->assertEqualsWithDelta(10.0, array_sum($puntos['habilidades']), 0.001);
    }

    public function test_sin_porcentajes_tres_habilidades_valen_exactamente_lo_mismo(): void
    {
        $convocatoria = $this->postulacion->convocatoria;
        $ids = collect(['Excel', 'Word', 'SQL'])->map(fn ($n) => $convocatoria->skills()->create(['nombre' => $n])->id);

        $habilidades = $this->actingAs($this->evaluador)->getJson("/api/evaluaciones/{$this->postulacion->id}")->assertOk()->json('data.puntos.habilidades');

        foreach ($ids as $id) {
            $this->assertEqualsWithDelta(3.3333, $habilidades[$id], 0.0001);
        }
        $this->assertCount(1, array_unique(array_values($habilidades)));
    }

    public function test_sin_porcentajes_las_habilidades_se_reparten_en_partes_iguales(): void
    {
        $convocatoria = $this->postulacion->convocatoria;
        $uno = $convocatoria->skills()->create(['nombre' => 'Excel']);
        $dos = $convocatoria->skills()->create(['nombre' => 'Word']);
        $tres = $convocatoria->skills()->create(['nombre' => 'SQL']);
        foreach ([$uno, $dos, $tres] as $skill) {
            $this->postulacion->skills()->create(['convocatoria_skill_id' => $skill->id, 'nombre' => $skill->nombre]);
        }

        $documentos = ['doc-'.$this->hoja => false, 'doc-'.$this->identidad => false];

        // Un tercio de 10 puntos por habilidad: dos de tres cumplidas son 6,67 % (con decimales, sin redondear al entero).
        $this->guardar($documentos + ['skill-'.$uno->id => true, 'skill-'.$dos->id => true, 'skill-'.$tres->id => false])
            ->assertOk()->assertJsonPath('data.evaluacion.cumplimiento', 6.67);
    }
}
