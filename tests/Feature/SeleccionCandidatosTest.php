<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Convocatoria;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SeleccionAyudas;
use Tests\TestCase;

class SeleccionCandidatosTest extends TestCase
{
    use RefreshDatabase;
    use SeleccionAyudas;

    public function test_el_alcance_se_aplica_antes_de_paginar(): void
    {
        $fichaA = $this->ficha('2896411');
        $fichaB = $this->ficha('2631620');
        $seleccionador = $this->seleccionador($fichaA);

        // 25 propias y 25 ajenas: si el alcance se aplicara después de paginar, la
        // primera página (20) mezclaría ajenas y los totales no coincidirían.
        foreach (range(1, 25) as $i) {
            $this->convocatoria($fichaB);
        }
        foreach (range(1, 25) as $i) {
            $this->convocatoria($fichaA);
        }

        $pagina1 = $this->actingAs($seleccionador)->getJson('/api/seleccion/convocatorias')->assertOk();
        $this->assertCount(20, $pagina1->json('data'));
        $this->assertSame(25, $pagina1->json('meta.total'));
        $this->assertSame(2, $pagina1->json('meta.last_page'));
        $this->assertSame(['Convocatoria 2896411'], collect($pagina1->json('data'))->pluck('nombre')->unique()->values()->all());

        $pagina2 = $this->actingAs($seleccionador)->getJson('/api/seleccion/convocatorias?page=2')->assertOk();
        $this->assertCount(5, $pagina2->json('data'));
    }

    // ── Candidatos ─────────────────────────────────────────────────────

    public function test_solo_se_listan_postulaciones_postuladas_con_el_contrato_minimo(): void
    {
        $ficha = $this->ficha('2896411');
        $seleccionador = $this->seleccionador($ficha);
        $convocatoria = $this->convocatoria($ficha);
        $otraConvocatoria = $this->convocatoria($ficha);

        $enviada = $this->postulacion($convocatoria, 'postulada', $this->usuario(Role::Aspirante, null, ['ficha_id' => $ficha->id, 'name' => 'Ana Pérez']));
        $this->postulacion($convocatoria, 'borrador');
        $this->postulacion($convocatoria, 'retirada');
        $this->postulacion($otraConvocatoria, 'postulada');

        $datos = $this->candidatos($seleccionador, $convocatoria)->assertOk()->json('data');

        $this->assertCount(1, $datos);
        // Contrato aditivo: los campos de siempre, en su orden, y después listo y bloqueos.
        $this->assertSame(['id', 'aspirante', 'estado_documental', 'cumplimiento', 'listo', 'bloqueos'], array_keys($datos[0]));
        $this->assertSame($enviada->id, $datos[0]['id']);
        $this->assertSame('Ana Pérez', $datos[0]['aspirante']);
    }

    public function test_estados_documentales_pendiente_habilitado_no_habilitado_y_multiple(): void
    {
        $ficha = $this->ficha('2896411');
        $seleccionador = $this->seleccionador($ficha);
        $convocatoria = $this->convocatoria($ficha);

        $sinEvaluar = $this->postulacion($convocatoria);

        $habilita = $this->postulacion($convocatoria);
        $this->evaluacion($habilita, 'finalizada', 85, true);

        $noHabilita = $this->postulacion($convocatoria);
        $this->evaluacion($noHabilita, 'finalizada', 40, false);

        $coinciden = $this->postulacion($convocatoria);
        $this->evaluacion($coinciden, 'finalizada', 90, true);
        $this->evaluacion($coinciden, 'finalizada', 80, true);

        $difieren = $this->postulacion($convocatoria);
        $this->evaluacion($difieren, 'finalizada', 90, true);
        $this->evaluacion($difieren, 'finalizada', 30, false);

        $filas = $this->candidatos($seleccionador, $convocatoria)->assertOk()->json('data');
        $porId = collect($filas)->keyBy('id');

        $this->assertSame(['pendiente', null], [$porId[$sinEvaluar->id]['estado_documental'], $porId[$sinEvaluar->id]['cumplimiento']]);
        $this->assertSame(['habilitado', 85], [$porId[$habilita->id]['estado_documental'], $porId[$habilita->id]['cumplimiento']]);
        $this->assertSame(['no_habilitado', 40], [$porId[$noHabilita->id]['estado_documental'], $porId[$noHabilita->id]['cumplimiento']]);
        // Con más de un resultado no hay porcentaje, aunque coincidan.
        $this->assertSame(['requiere_revision', null], [$porId[$coinciden->id]['estado_documental'], $porId[$coinciden->id]['cumplimiento']]);
        $this->assertSame(['requiere_revision', null], [$porId[$difieren->id]['estado_documental'], $porId[$difieren->id]['cumplimiento']]);
    }

    public function test_evaluaciones_reabiertas_en_correccion_o_sin_resultado_no_cuentan(): void
    {
        $ficha = $this->ficha('2896411');
        $seleccionador = $this->seleccionador($ficha);
        $convocatoria = $this->convocatoria($ficha);

        $reabierta = $this->postulacion($convocatoria);
        $this->evaluacion($reabierta, 'en_proceso', 70, null);

        $enCorreccion = $this->postulacion($convocatoria);
        $this->evaluacion($enCorreccion, 'correccion_solicitada', 70, null);

        $finalizadaSinResultado = $this->postulacion($convocatoria);
        $this->evaluacion($finalizadaSinResultado, 'finalizada', null, null);

        // Una finalizada válida más una reabierta: cuenta una sola.
        $unaValida = $this->postulacion($convocatoria);
        $this->evaluacion($unaValida, 'finalizada', 75, true);
        $this->evaluacion($unaValida, 'en_proceso', 10, null);

        $this->assertSame('pendiente', $this->estadoDe($seleccionador, $convocatoria, $reabierta)['estado_documental']);
        $this->assertSame('pendiente', $this->estadoDe($seleccionador, $convocatoria, $enCorreccion)['estado_documental']);
        $this->assertSame('pendiente', $this->estadoDe($seleccionador, $convocatoria, $finalizadaSinResultado)['estado_documental']);

        $fila = $this->estadoDe($seleccionador, $convocatoria, $unaValida);
        $this->assertSame('habilitado', $fila['estado_documental']);
        $this->assertSame(75, $fila['cumplimiento']);
    }

    public function test_no_se_exponen_datos_sensibles_ni_detalles(): void
    {
        $ficha = $this->ficha('2896411');
        $seleccionador = $this->seleccionador($ficha);
        $convocatoria = $this->convocatoria($ficha);
        $postulacion = $this->postulacion($convocatoria);
        $this->evaluacion($postulacion, 'finalizada', 85, true);

        $texto = $this->candidatos($seleccionador, $convocatoria)->assertOk()->getContent();

        foreach (['email', 'observaciones', 'detalles', 'documentos', 'evaluador', 'perfil', 'telefono'] as $prohibido) {
            $this->assertStringNotContainsString($prohibido, $texto);
        }
    }

    public function test_el_numero_de_consultas_no_crece_con_los_candidatos(): void
    {
        $ficha = $this->ficha('2896411');
        $seleccionador = $this->seleccionador($ficha);
        $convocatoria = $this->convocatoria($ficha);

        $consultas = function () use ($seleccionador, $convocatoria): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->candidatos($seleccionador, $convocatoria)->assertOk();
            $total = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $total;
        };

        foreach (range(1, 2) as $i) {
            $this->evaluacion($this->postulacion($convocatoria), 'finalizada', 80, true);
        }
        $conDos = $consultas();

        foreach (range(1, 15) as $i) {
            $this->evaluacion($this->postulacion($convocatoria), 'finalizada', 80, true);
        }
        $conDiecisiete = $consultas();

        $this->assertSame($conDos, $conDiecisiete);
    }

    public function test_los_candidatos_se_paginan_con_orden_estable(): void
    {
        $ficha = $this->ficha('2896411');
        $seleccionador = $this->seleccionador($ficha);
        $convocatoria = $this->convocatoria($ficha);
        $creadas = collect(range(1, 23))->map(fn () => $this->postulacion($convocatoria)->id);

        $p1 = $this->candidatos($seleccionador, $convocatoria)->assertOk();
        $p2 = $this->actingAs($seleccionador)->getJson("/api/seleccion/convocatorias/{$convocatoria->id}/candidatos?page=2")->assertOk();

        $this->assertSame(23, $p1->json('meta.total'));
        $this->assertSame($creadas->all(), array_merge(
            collect($p1->json('data'))->pluck('id')->all(),
            collect($p2->json('data'))->pluck('id')->all(),
        ));
    }

}
