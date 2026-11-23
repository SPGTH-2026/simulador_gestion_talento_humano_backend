<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeleccionListadoAyudas;
use Tests\TestCase;

class SeleccionAtencionTest extends TestCase
{
    use RefreshDatabase;
    use SeleccionListadoAyudas;

    public function test_atencion_solo_trae_en_proceso_con_candidato_listo_y_sin_lista(): void
    {
        $ficha = $this->ficha();
        $actor = $this->seleccionador($ficha);

        $sinCandidatos = $this->convocatoria($ficha);
        $this->sinActividades($sinCandidatos);

        $ningunoListo = $this->convocatoria($ficha);
        $this->conEntrevista($ningunoListo);
        $this->habilitado($ningunoListo); // habilitado, pero falta la nota de la entrevista

        $sinConfigurar = $this->convocatoria($ficha);
        $this->habilitado($sinConfigurar); // configuración pendiente: nadie está listo

        $conLista = $this->convocatoria($ficha);
        $this->sinActividades($conLista);
        $this->habilitado($conLista);
        $this->confirmarLista($conLista, $actor);

        $publicada = $this->convocatoria($ficha, 'publicada');
        $this->sinActividades($publicada);
        $this->habilitado($publicada);

        $pendiente = $this->convocatoria($ficha);
        $this->sinActividades($pendiente);
        $this->habilitado($pendiente);
        $this->habilitado($pendiente);
        $this->postulacion($pendiente); // sin evaluación: candidato, pero no listo

        $respuesta = $this->listado($actor, '?atencion=1')->assertOk();

        $this->assertSame([$pendiente->id], collect($respuesta->json('data'))->pluck('id')->all());
        $respuesta->assertJsonPath('data.0.candidatos', 3)
            ->assertJsonPath('data.0.listos', 2)
            ->assertJsonPath('meta.hay_mas', false)
            ->assertJsonPath('meta.limite', 4);
    }

    public function test_atencion_encuentra_un_pendiente_que_no_esta_en_la_primera_pagina_del_listado(): void
    {
        $ficha = $this->ficha();
        $actor = $this->seleccionador($ficha);
        $vieja = $this->convocatoria($ficha);
        $this->sinActividades($vieja);
        $this->habilitado($vieja);
        foreach (range(1, 21) as $_) {
            $this->convocatoria($ficha, 'publicada');
        }

        $general = collect($this->listado($actor)->assertOk()->json('data'))->pluck('id');
        $this->assertNotContains($vieja->id, $general->all());

        $this->assertSame([$vieja->id], collect($this->listado($actor, '?atencion=1')->assertOk()->json('data'))->pluck('id')->all());
    }

    public function test_atencion_se_limita_a_cuatro_e_indica_si_hay_mas(): void
    {
        $ficha = $this->ficha();
        $actor = $this->seleccionador($ficha);
        $creadas = collect(range(1, 5))->map(function () use ($ficha) {
            $c = $this->convocatoria($ficha);
            $this->sinActividades($c);
            $this->habilitado($c);

            return $c;
        });

        $respuesta = $this->listado($actor, '?atencion=1')->assertOk();

        $this->assertCount(4, $respuesta->json('data'));
        $respuesta->assertJsonPath('meta.hay_mas', true);
        // De más reciente a más antigua: la más vieja queda fuera.
        $this->assertSame($creadas->reverse()->take(4)->pluck('id')->values()->all(), collect($respuesta->json('data'))->pluck('id')->all());
    }

    public function test_atencion_respeta_el_alcance_por_ficha(): void
    {
        $propia = $this->ficha('2896411');
        $ajena = $this->ficha('2896412');
        $c = $this->convocatoria($ajena);
        $this->sinActividades($c);
        $this->habilitado($c);

        $this->assertSame([], $this->listado($this->seleccionador($propia), '?atencion=1')->assertOk()->json('data'));
        $this->assertCount(1, $this->listado($this->seleccionador($ajena), '?atencion=1')->json('data'));
    }

}
