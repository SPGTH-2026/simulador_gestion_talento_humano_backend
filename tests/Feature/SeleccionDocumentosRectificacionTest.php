<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeleccionDocumentosCompletosAyudas;
use Tests\TestCase;

class SeleccionDocumentosRectificacionTest extends TestCase
{
    use RefreshDatabase;
    use SeleccionDocumentosCompletosAyudas;

    public function test_se_conservan_la_decision_su_historial_y_la_rectificacion_que_libera_el_cupo(): void
    {
        $completa = $this->candidato();
        $legada = $this->candidato();
        $this->confirmar([$completa, $legada])->assertCreated();
        $version = $this->versionLista();

        // Decisión registrada cuando la postulación todavía estaba completa; después queda incompleta.
        $this->decidir($legada, 'seleccionado', $version)->assertCreated();
        $this->quitarDocumento($legada, 'id');

        $indice = $this->actingAs($this->seleccionador)
            ->getJson("/api/seleccion/convocatorias/{$this->convocatoria->id}/decisiones")->assertOk();
        $fila = collect($indice->json('data'))->firstWhere('postulacion_id', $legada->id);
        $this->assertSame('seleccionado', $fila['estado']);
        $this->assertSame('ya_no_elegible', $fila['motivo_revision']); // el Instructor la ve para revisarla
        $this->assertTrue($fila['requiere_revision']);
        $this->assertSame(1, $indice->json('meta.cupos.cupos_ocupados')); // sigue ocupando su cupo
        $this->assertDatabaseCount('seleccion_decisiones', 1);
        $this->assertDatabaseCount('seleccion_decision_eventos', 1);

        // La rectificación que libera el cupo sigue permitida, y deja su evento en el historial.
        $this->decidir($legada, 'no_seleccionado', $version, 'Se libera el cupo: la postulación quedó incompleta.')
            ->assertOk()->assertJsonPath('data.cupos.cupos_ocupados', 0)->assertJsonPath('data.cupos.cupos_disponibles', 2);
        $this->assertDatabaseCount('seleccion_decisiones', 1);
        $this->assertDatabaseCount('seleccion_decision_eventos', 2);

        $historial = $this->actingAs($this->seleccionador)
            ->getJson("/api/seleccion/convocatorias/{$this->convocatoria->id}/decisiones/{$legada->id}/historial")->assertOk();
        $this->assertSame(['no_seleccionado', 'seleccionado'], array_column($historial->json('data'), 'resultado_nuevo'));
    }

    public function test_al_completar_de_nuevo_el_obligatorio_vuelve_a_ser_lista(): void
    {
        $legada = $this->candidato(['hv']);
        $this->assertSame([], $this->listos());

        // Reponer el archivo (es lo que haría el aspirante tras la corrección) la devuelve al Ranking.
        $legada->documentos()->create([
            'nombre_original' => 'id.pdf', 'ruta' => "postulaciones/test/{$legada->id}-id.pdf", 'disco' => 'local',
            'mime' => 'application/pdf', 'tamano_bytes' => 1024, 'parametro_id' => $this->doc['id'],
        ]);
        $this->assertSame([$legada->id], $this->listos());
    }

}
