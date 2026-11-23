<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SeleccionDocumentosCompletosAyudas;
use Tests\TestCase;

class SeleccionDocumentosBloqueoTest extends TestCase
{
    use RefreshDatabase;
    use SeleccionDocumentosCompletosAyudas;

    public function test_una_postulacion_incompleta_con_evaluacion_finalizada_no_aparece_como_lista(): void
    {
        $completa = $this->candidato();
        $incompleta = $this->candidato(['hv']); // le falta el documento de identidad, pero su evaluación dice «Cumple»

        $respuesta = $this->ranking();
        $this->assertSame([$completa->id], $this->listos());
        $respuesta->assertJsonPath('meta.listos', 1)->assertJsonPath('meta.no_listos', 1);
        $this->assertNotContains($incompleta->id, $this->listos());
    }

    public function test_un_opcional_ausente_no_bloquea(): void
    {
        $sinOpcional = $this->candidato(['hv', 'id']); // sin tarjeta profesional (opcional)
        $conOpcional = $this->candidato(['hv', 'id', 'tp']);

        $this->assertEqualsCanonicalizing([$sinOpcional->id, $conOpcional->id], $this->listos());
    }

    public function test_un_documento_que_no_aplica_no_bloquea_pero_a_un_hombre_si_se_le_exige(): void
    {
        $convocatoria = $this->crearConvocatoria(2, conLibreta: true);
        $mujer = $this->candidato(['hv', 'id'], 'mujer', $convocatoria); // la libreta no le aplica
        $hombreSinLibreta = $this->candidato(['hv', 'id'], 'hombre', $convocatoria);
        $hombreCompleto = $this->candidato(['hv', 'id', 'libreta'], 'hombre', $convocatoria);

        $this->assertEqualsCanonicalizing([$mujer->id, $hombreCompleto->id], $this->listos($convocatoria));
        $this->assertNotContains($hombreSinLibreta->id, $this->listos($convocatoria));
    }

    public function test_la_validacion_de_documentos_se_hace_en_bloque_sin_consultas_por_candidato(): void
    {
        $contar = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->ranking();
            $total = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $total;
        };

        $this->candidato();
        $this->candidato(['hv']);
        $conDos = $contar();

        foreach (range(1, 6) as $i) {
            $this->candidato($i % 2 ? ['hv', 'id'] : ['hv']);
        }
        $conOcho = $contar();

        $this->assertSame($conDos, $conOcho, 'El Ranking no debe hacer consultas por candidato para validar los documentos.');
    }

    // ── Finalistas y decisiones ────────────────────────────────────────

    public function test_no_puede_confirmarse_como_finalista_una_postulacion_incompleta(): void
    {
        $completa = $this->candidato();
        $incompleta = $this->candidato(['hv']);

        $this->confirmar([$incompleta])->assertStatus(422)
            ->assertJsonPath('errors.postulacion_ids.0', 'Solo pueden ser finalistas los candidatos listos: habilitados documentalmente y con todas las actividades calificadas.');
        $this->confirmar([$completa, $incompleta])->assertStatus(422);
        $this->assertDatabaseCount('seleccion_finalistas_listas', 0);

        $this->confirmar([$completa])->assertCreated();
    }

    public function test_una_postulacion_antigua_incompleta_no_puede_recibir_una_decision_de_seleccionado(): void
    {
        $completa = $this->candidato();
        $legada = $this->candidato();
        $this->confirmar([$completa, $legada])->assertCreated();
        $version = $this->versionLista();
        $this->quitarDocumento($legada, 'id'); // pierde un obligatorio después de estar en la lista

        // La lista pasa a «requiere revisión»: nadie recibe una decisión nueva de «Seleccionado» hasta confirmar otra versión.
        $this->decidir($legada, 'seleccionado', $version)->assertStatus(409)->assertJsonPath('codigo', 'lista_requiere_revision');
        $this->decidir($completa, 'seleccionado', $version)->assertStatus(409)->assertJsonPath('codigo', 'lista_requiere_revision');
        $this->assertDatabaseCount('seleccion_decisiones', 0);

        // Y una nueva versión no puede volver a incluirla.
        $this->confirmar([$completa, $legada])->assertStatus(422);
        $this->confirmar([$completa])->assertCreated();
        $nueva = $this->versionLista();
        $this->decidir($legada, 'seleccionado', $nueva)->assertStatus(422)
            ->assertJsonPath('errors.postulacion.0', 'El candidato no pertenece a la lista de finalistas confirmada.');
        $this->decidir($completa, 'seleccionado', $nueva)->assertCreated();
    }

    public function test_si_ya_estaba_en_la_lista_aparece_como_ya_no_elegible(): void
    {
        $completa = $this->candidato();
        $legada = $this->candidato();
        $this->confirmar([$completa, $legada])->assertCreated();
        $this->quitarDocumento($legada, 'hv');

        $finalistas = $this->actingAs($this->seleccionador)
            ->getJson("/api/seleccion/convocatorias/{$this->convocatoria->id}/finalistas")->assertOk();
        $finalistas->assertJsonPath('data.actual.requiere_revision', true);
        $this->assertEquals(
            [['postulacion_id' => $legada->id, 'motivo' => 'ya_no_elegible']],
            $finalistas->json('data.actual.revisar'),
        );
    }

}
