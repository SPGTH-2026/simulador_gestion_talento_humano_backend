<?php

namespace Tests\Feature;

use App\Models\Parametro;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\DatosPersonalesAyudas;
use Tests\Concerns\RondasCorreccionAyudas;
use Tests\TestCase;

/**
 * Revisión de la autorización del representante por el evaluador. Es un requisito por edad: el evaluador la marca «Cumple»
 * o «No cumple» y, si no cumple, la devuelve con una ronda de corrección. NO pesa en el cumplimiento de la evaluación.
 */
class EvaluacionAutorizacionMenorTest extends TestCase
{
    use DatosPersonalesAyudas;
    use RefreshDatabase;
    use RondasCorreccionAyudas;

    /** Id del parámetro de la autorización (el escenario común ya sembró el catálogo en su setUp). */
    private function autorizacion(): int
    {
        return $this->parametroId('tipo_documento', 'autorizacion_representante');
    }

    private function hacerMenor(): void
    {
        $this->conDatosPersonales($this->postulacion, ['fecha_nacimiento' => now()->subYears(17)->toDateString()]);
    }

    private function subirAutorizacion(): void
    {
        $this->postulacion->documentos()->create([
            'nombre_original' => 'autorizacion.pdf', 'ruta' => "postulaciones/test/{$this->postulacion->id}-aut.pdf",
            'disco' => 'local', 'mime' => 'application/pdf', 'tamano_bytes' => 1024, 'parametro_id' => $this->autorizacion(),
        ]);
    }

    /** @param array<string, bool> $marcas */
    private function evaluar(string $estado, array $marcas, string $observaciones = 'Revisado.')
    {
        return $this->actingAs($this->evaluador)->putJson("/api/evaluaciones/{$this->postulacion->id}", [
            'estado' => $estado,
            'observaciones' => $observaciones,
            'criterios' => [['criterio' => 'Evaluación', 'puntaje' => 100, 'ponderacion' => 1]],
            'perfil' => $marcas,
        ]);
    }

    private function todoCumple(array $extra = []): array
    {
        return ["doc-{$this->hoja}" => true, "doc-{$this->identidad}" => true] + $extra;
    }

    public function test_el_evaluador_ve_la_autorizacion_del_menor_sin_porcentaje(): void
    {
        $this->hacerMenor();
        $this->subirAutorizacion();

        $documentos = $this->actingAs($this->evaluador)
            ->getJson("/api/revision/postulaciones/{$this->postulacion->id}/documentos")->assertOk()->json('data.documentos');
        $fila = collect($documentos)->firstWhere('parametro.codigo', 'autorizacion_representante');

        $this->assertNotNull($fila);
        $this->assertTrue($fila['requisito_edad']);
        $this->assertNull($fila['ponderacion']);
        $this->assertSame('subido', $fila['estado']);
    }

    public function test_marcar_la_autorizacion_no_cambia_el_cumplimiento(): void
    {
        $this->hacerMenor();
        $this->subirAutorizacion();

        $sin = $this->evaluar('en_proceso', $this->todoCumple())->assertOk()->json('data.evaluacion.cumplimiento');
        $con = $this->evaluar('en_proceso', $this->todoCumple(["doc-{$this->autorizacion()}" => false]))->assertOk()->json('data.evaluacion.cumplimiento');

        $this->assertSame(100, $sin);
        $this->assertSame(100, $con);
    }

    public function test_no_se_finaliza_la_evaluacion_de_un_menor_sin_autorizacion_cargada(): void
    {
        $this->hacerMenor();

        $this->evaluar('finalizada', $this->todoCumple())
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'No se puede finalizar la evaluación: faltan documentos obligatorios (Autorización del representante legal). Solicita la corrección al aspirante.']);

        $this->subirAutorizacion();

        $this->evaluar('finalizada', $this->todoCumple(["doc-{$this->autorizacion()}" => true]))->assertOk();
    }

    public function test_un_mayor_de_edad_se_finaliza_sin_autorizacion(): void
    {
        $this->conDatosPersonales($this->postulacion);

        $this->evaluar('finalizada', $this->todoCumple())->assertOk();
    }

    public function test_el_evaluador_puede_devolver_la_autorizacion_para_corregir_y_el_aspirante_la_reemplaza(): void
    {
        $this->hacerMenor();
        $this->subirAutorizacion();

        $this->evaluar('correccion_solicitada', $this->todoCumple(["doc-{$this->autorizacion()}" => false]), 'La autorización está ilegible.')
            ->assertOk();

        $this->assertSame([$this->autorizacion()], $this->rondas()->sole()->documentos_marcados);

        $this->responder('autorizacion_representante')->assertOk();
    }

    public function test_no_se_puede_devolver_la_autorizacion_de_un_mayor_de_edad(): void
    {
        $this->conDatosPersonales($this->postulacion);

        $this->evaluar('correccion_solicitada', $this->todoCumple(["doc-{$this->autorizacion()}" => false]))
            ->assertStatus(422);

        $this->assertCount(0, $this->rondas());
    }
}
