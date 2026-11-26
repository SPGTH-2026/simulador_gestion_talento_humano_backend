<?php

namespace Tests\Feature;

use App\Models\Postulacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\PostulacionDocumentoAyudas;
use Tests\TestCase;

/**
 * Una postulación retirada cuya convocatoria ya cerró no se puede reabrir: la persona la «descarta» y deja de verla en su lista.
 * No se borra nada.
 */
class PostulacionDescartarTest extends TestCase
{
    use PostulacionDocumentoAyudas;
    use RefreshDatabase;

    /** @return array{0: \App\Models\User, 1: Postulacion} */
    private function escenario(string $estado, bool $convocatoriaCerrada, string $codigoFicha = '3173334'): array
    {
        $ficha = $this->ficha($codigoFicha);
        $aspirante = $this->aspirante($ficha);
        $hoy = now('America/Bogota');
        $convocatoria = $this->crearConvocatoria($ficha, $this->instructor($ficha), [
            'estado_id' => $this->parametroId('estado_convocatoria', 'publicada'),
            'fecha_apertura' => $hoy->copy()->subDays(20)->toDateString(),
            'fecha_cierre' => $convocatoriaCerrada ? $hoy->copy()->subDay()->toDateString() : $hoy->copy()->addDays(5)->toDateString(),
        ]);
        $postulacion = $this->crearPostulacion($convocatoria, $aspirante, ['estado_id' => $this->parametroId('estado_postulacion', $estado)]);

        return [$aspirante, $postulacion];
    }

    private function idsDeMiLista($aspirante): array
    {
        return array_column($this->actingAs($aspirante)->getJson('/api/postulaciones')->assertOk()->json('data'), 'id');
    }

    public function test_una_retirada_con_la_convocatoria_cerrada_se_descarta_y_deja_de_aparecer_en_la_lista(): void
    {
        [$aspirante, $postulacion] = $this->escenario('retirada', convocatoriaCerrada: true);
        $this->assertContains($postulacion->id, $this->idsDeMiLista($aspirante));

        $this->actingAs($aspirante)->postJson("/api/postulaciones/{$postulacion->id}/descartar")->assertNoContent();

        $this->assertNotContains($postulacion->id, $this->idsDeMiLista($aspirante));
        $this->assertNotNull($postulacion->fresh()->descartada_at);
    }

    public function test_descartar_no_borra_nada(): void
    {
        [$aspirante, $postulacion] = $this->escenario('retirada', convocatoriaCerrada: true);
        $postulacion->documentos()->create([
            'nombre_original' => 'hoja.pdf', 'ruta' => 'postulaciones/x/hoja.pdf', 'disco' => 'local',
            'mime' => 'application/pdf', 'tamano_bytes' => 10, 'parametro_id' => $this->parametroId('tipo_documento', 'hoja_de_vida'),
        ]);

        $this->actingAs($aspirante)->postJson("/api/postulaciones/{$postulacion->id}/descartar")->assertNoContent();

        $this->assertDatabaseHas('postulaciones', ['id' => $postulacion->id]);
        $this->assertDatabaseCount('postulacion_documentos', 1);
    }

    public function test_repetir_la_peticion_es_inofensivo(): void
    {
        [$aspirante, $postulacion] = $this->escenario('retirada', convocatoriaCerrada: true);

        $this->actingAs($aspirante)->postJson("/api/postulaciones/{$postulacion->id}/descartar")->assertNoContent();
        $primera = $postulacion->fresh()->descartada_at;
        $this->actingAs($aspirante)->postJson("/api/postulaciones/{$postulacion->id}/descartar")->assertNoContent();

        $this->assertEquals($primera, $postulacion->fresh()->descartada_at);
    }

    public function test_con_la_convocatoria_abierta_aun_puede_reabrirla_y_no_se_descarta(): void
    {
        [$aspirante, $postulacion] = $this->escenario('retirada', convocatoriaCerrada: false);

        $this->actingAs($aspirante)->postJson("/api/postulaciones/{$postulacion->id}/descartar")
            ->assertStatus(422)
            ->assertJsonValidationErrors('postulacion');

        $this->assertNull($postulacion->fresh()->descartada_at);
        $this->assertContains($postulacion->id, $this->idsDeMiLista($aspirante));
    }

    public function test_solo_se_descarta_una_postulacion_retirada(): void
    {
        foreach (['borrador' => '3173334', 'postulada' => '2631620'] as $estado => $ficha) {
            // Una ficha por vuelta: el código de la ficha es único.
            [$aspirante, $postulacion] = $this->escenario($estado, convocatoriaCerrada: true, codigoFicha: $ficha);

            $this->actingAs($aspirante)->postJson("/api/postulaciones/{$postulacion->id}/descartar")
                ->assertStatus(422)
                ->assertJsonValidationErrors('postulacion');

            $this->assertNull($postulacion->fresh()->descartada_at);
        }
    }

    public function test_una_postulacion_ajena_responde_404(): void
    {
        [, $postulacion] = $this->escenario('retirada', convocatoriaCerrada: true);
        $otra = $this->aspirante($postulacion->convocatoria->ficha);

        $this->actingAs($otra)->postJson("/api/postulaciones/{$postulacion->id}/descartar")->assertNotFound();
        $this->assertNull($postulacion->fresh()->descartada_at);
    }

    public function test_las_demas_postulaciones_de_la_lista_no_se_ven_afectadas(): void
    {
        [$aspirante, $descartada] = $this->escenario('retirada', convocatoriaCerrada: true);
        $otraConvocatoria = $this->conDocumentos($this->convocatoriaAbierta($descartada->convocatoria->ficha, $this->instructor($descartada->convocatoria->ficha)), ['hoja_de_vida' => false]);
        $vigente = $this->crearPostulacion($otraConvocatoria, $aspirante);

        $this->actingAs($aspirante)->postJson("/api/postulaciones/{$descartada->id}/descartar")->assertNoContent();

        $this->assertSame([$vigente->id], $this->idsDeMiLista($aspirante));
    }
}
