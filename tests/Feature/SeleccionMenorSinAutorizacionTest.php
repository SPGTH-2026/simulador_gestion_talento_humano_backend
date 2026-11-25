<?php

namespace Tests\Feature;

use App\Models\Parametro;
use App\Models\Postulacion;
use App\Models\PostulacionDocumento;
use App\Models\SeleccionDecision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\DatosPersonalesAyudas;
use Tests\Concerns\SeleccionDecisionAyudas;
use Tests\TestCase;

/**
 * Un menor de edad sin la autorización de su representante no es elegible: no entra al ranking y, si ya estaba en la lista
 * de finalistas, esa lista pasa a requerir revisión y nadie puede decidir sobre ella hasta que se resuelva. El mecanismo
 * es el de siempre (la lista exige que todos sus finalistas sigan «listos»); aquí se comprueba que cubre este requisito.
 */
class SeleccionMenorSinAutorizacionTest extends TestCase
{
    use DatosPersonalesAyudas;
    use RefreshDatabase;
    use SeleccionDecisionAyudas;

    private function hacerMenor(Postulacion $postulacion): void
    {
        $this->conDatosPersonales($postulacion, ['fecha_nacimiento' => now()->subYears(17)->toDateString()]);
    }

    private function subirAutorizacion(Postulacion $postulacion): void
    {
        PostulacionDocumento::create([
            'postulacion_id' => $postulacion->id,
            'parametro_id' => Parametro::porTemaYCodigo('tipo_documento', 'autorizacion_representante')->id,
            'nombre_original' => 'autorizacion.pdf',
            'ruta' => 'prueba/autorizacion.pdf',
            'disco' => 'local',
            'mime' => 'application/pdf',
            'tamano_bytes' => 100,
        ]);
    }

    public function test_un_finalista_menor_sin_autorizacion_bloquea_la_decision_hasta_que_la_suba(): void
    {
        [$seleccionador, $convocatoria, [$a, $menor], $version] = $this->escenario(2, 2);
        $this->hacerMenor($menor);

        $this->decidir($seleccionador, $convocatoria, $a, 'seleccionado', $version)
            ->assertStatus(409)
            ->assertJsonPath('codigo', 'lista_requiere_revision')
            ->assertJsonPath('revisar.0.postulacion_id', $menor->id)
            ->assertJsonPath('revisar.0.motivo', 'ya_no_elegible');
        $this->assertSame(0, SeleccionDecision::count());

        $this->subirAutorizacion($menor);

        $this->decidir($seleccionador, $convocatoria, $menor, 'seleccionado', $version)->assertCreated();
    }

    public function test_el_menor_sin_autorizacion_no_aparece_en_el_ranking(): void
    {
        [$seleccionador, $convocatoria, [$a, $menor]] = $this->escenario(2, 2);
        $this->hacerMenor($menor);

        $enRanking = fn () => array_column(
            $this->actingAs($seleccionador)->getJson("/api/seleccion/convocatorias/{$convocatoria->id}/ranking")->assertOk()->json('data'),
            'postulacion_id',
        );

        $this->assertSame([$a->id], $enRanking());

        $this->subirAutorizacion($menor);

        $this->assertEqualsCanonicalizing([$a->id, $menor->id], $enRanking());
    }

    public function test_un_mayor_de_edad_no_necesita_autorizacion_para_ser_decidido(): void
    {
        [$seleccionador, $convocatoria, [$a], $version] = $this->escenario(1, 1);
        $this->conDatosPersonales($a);

        $this->decidir($seleccionador, $convocatoria, $a, 'seleccionado', $version)->assertCreated();
    }
}
