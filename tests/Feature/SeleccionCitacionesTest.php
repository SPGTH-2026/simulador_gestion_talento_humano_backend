<?php

// Citaciones de Selección: permisos y alcance, fechas en hora de Colombia, enlaces seguros, una cita vigente por postulación y
// actividad (doble clic y solicitudes repetidas), historial, cancelación y que no condicionan nada de las calificaciones.

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Notificacion;
use App\Models\SeleccionCitacion;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\CitacionesAyudas;
use Tests\Concerns\SeleccionCalificacionAyudas;
use Tests\TestCase;

class SeleccionCitacionesTest extends TestCase
{
    use CitacionesAyudas;
    use RefreshDatabase;
    use SeleccionCalificacionAyudas;

    // ── Permisos y alcance ─────────────────────────────────────────────

    public function test_quien_decide_programa_y_quien_no_tiene_permiso_o_alcance_no_puede(): void
    {
        [$seleccionador, $convocatoria, $postulacion, $ficha] = $this->escenario();

        $this->programar($seleccionador, $convocatoria, $postulacion)->assertCreated();

        // Sin el permiso de Selección: un evaluador y el propio aspirante reciben 403, también al consultar.
        $evaluador = $this->usuario(Role::Aprendiz, 'evaluador', ['ficha_id' => $ficha->id]);
        $this->programar($evaluador, $convocatoria, $postulacion)->assertForbidden();
        $this->actingAs($evaluador)->getJson($this->urlCita($convocatoria, $postulacion))->assertForbidden();
        $this->programar($postulacion->user, $convocatoria, $postulacion)->assertForbidden();

        // Con permiso pero de otra ficha: 404 sin revelar nada.
        $ajeno = $this->seleccionador($this->ficha('9999999'));
        $this->programar($ajeno, $convocatoria, $postulacion)->assertNotFound();
        $this->actingAs($ajeno)->getJson($this->urlCita($convocatoria, $postulacion))->assertNotFound();
        $this->assertSame(1, SeleccionCitacion::count());
    }

    // ── Fechas ─────────────────────────────────────────────────────────

    public function test_la_hora_se_escribe_y_se_muestra_en_colombia_y_se_guarda_en_utc(): void
    {
        [$seleccionador, $convocatoria, $postulacion] = $this->escenario();

        $this->programar($seleccionador, $convocatoria, $postulacion, ['fecha' => '2030-01-15', 'hora' => '14:30'])
            ->assertCreated()
            ->assertJsonPath('data.fecha', '2030-01-15')
            ->assertJsonPath('data.hora', '14:30')
            ->assertJsonPath('data.zona_horaria', 'America/Bogota')
            ->assertJsonPath('data.fecha_hora', '2030-01-15T19:30:00+00:00');

        // En la base queda en UTC (Colombia es UTC-5).
        $this->assertSame('2030-01-15 19:30:00', SeleccionCitacion::first()->getRawOriginal('fecha_hora'));
    }

    public function test_rechaza_fechas_pasadas_o_mal_formadas(): void
    {
        [$seleccionador, $convocatoria, $postulacion] = $this->escenario();

        $this->programar($seleccionador, $convocatoria, $postulacion, ['fecha' => now('America/Bogota')->subDay()->format('Y-m-d')])
            ->assertUnprocessable()->assertJsonValidationErrors('fecha');
        $this->programar($seleccionador, $convocatoria, $postulacion, ['fecha' => '15/01/2030'])->assertUnprocessable()->assertJsonValidationErrors('fecha');
        $this->programar($seleccionador, $convocatoria, $postulacion, ['hora' => '2:30 pm'])->assertUnprocessable()->assertJsonValidationErrors('hora');
        $this->programar($seleccionador, $convocatoria, $postulacion, ['actividad' => 'otra'])->assertUnprocessable()->assertJsonValidationErrors('actividad');
        $this->assertSame(0, SeleccionCitacion::count());
    }

    // ── Lugar o enlace ─────────────────────────────────────────────────

    public function test_un_enlace_solo_admite_http_o_https_y_el_texto_no_se_interpreta_como_html(): void
    {
        [$seleccionador, $convocatoria, $postulacion] = $this->escenario();

        $this->programar($seleccionador, $convocatoria, $postulacion, ['lugar_o_enlace' => 'https://meet.example.com/abc-def'])
            ->assertCreated()->assertJsonPath('data.es_enlace', true);
        $this->assertTrue(SeleccionCitacion::first()->esEnlace());

        $segunda = $this->candidatoHabilitado($convocatoria);
        foreach (['javascript:alert(1)', 'data:text/html;base64,AAAA', 'ftp://servidor/archivo', 'vbscript:x', 'https://a.com/ b', "Sala\nA", '<script>alert(1)</script>', '', str_repeat('a', 256)] as $malo) {
            $this->programar($seleccionador, $convocatoria, $segunda, ['lugar_o_enlace' => $malo])->assertUnprocessable()->assertJsonValidationErrors('lugar_o_enlace');
        }
        $this->programar($seleccionador, $convocatoria, $segunda, ['lugar_o_enlace' => 'Sala 2: edificio B'])
            ->assertCreated()->assertJsonPath('data.es_enlace', false);
        $this->assertSame(2, SeleccionCitacion::count());
    }

    // ── Una cita vigente por postulación y actividad ───────────────────

    public function test_doble_clic_o_reenvio_identico_no_duplica_ni_avisa_dos_veces(): void
    {
        Mail::fake();
        [$seleccionador, $convocatoria, $postulacion] = $this->escenario();
        $cuerpo = $this->cuerpo();

        $primera = $this->actingAs($seleccionador)->postJson($this->urlCita($convocatoria, $postulacion), $cuerpo)->assertCreated();
        $segunda = $this->actingAs($seleccionador)->postJson($this->urlCita($convocatoria, $postulacion), $cuerpo)->assertOk();

        $this->assertSame($primera->json('data.id'), $segunda->json('data.id'));
        $this->assertSame(1, SeleccionCitacion::count());
        $this->assertSame(1, Notificacion::where('user_id', $postulacion->user_id)->where('tipo', 'citacion')->count());
        $this->assertSame(1, SeleccionCitacion::first()->eventos()->count());
        Mail::assertNothingSent();
    }

    public function test_una_segunda_cita_distinta_para_la_misma_actividad_se_rechaza_pero_otra_actividad_es_independiente(): void
    {
        [$seleccionador, $convocatoria, $postulacion] = $this->escenario();
        $this->programar($seleccionador, $convocatoria, $postulacion)->assertCreated();

        $this->programar($seleccionador, $convocatoria, $postulacion, ['hora' => '16:00'])
            ->assertUnprocessable()->assertJsonValidationErrors('actividad');
        $this->programar($seleccionador, $convocatoria, $postulacion, ['actividad' => 'prueba_tecnica', 'hora' => '16:00'])->assertCreated();
        $this->assertSame(2, SeleccionCitacion::count());
    }

    public function test_la_restriccion_unica_de_la_base_impide_dos_vigentes_aunque_fallen_las_comprobaciones(): void
    {
        [$seleccionador, $convocatoria, $postulacion] = $this->escenario();
        $this->programar($seleccionador, $convocatoria, $postulacion)->assertCreated();
        $base = SeleccionCitacion::first()->only(['convocatoria_id', 'postulacion_id', 'actividad', 'fecha_hora', 'lugar_o_enlace']);

        $this->expectException(UniqueConstraintViolationException::class);
        SeleccionCitacion::create($base + ['estado' => 'programada', 'vigente' => true]);
    }

    public function test_la_misma_persona_tiene_citas_independientes_en_distintas_convocatorias(): void
    {
        [$seleccionador, $convocatoria, $postulacion, $ficha] = $this->escenario();
        $otra = $this->convocatoria($ficha);
        $this->configurar($otra, true, false);
        $otraPostulacion = $this->postulacion($otra);
        $otraPostulacion->update(['user_id' => $postulacion->user_id]);
        $this->evaluacion($otraPostulacion, 'finalizada', 90, true);

        $this->programar($seleccionador, $convocatoria, $postulacion)->assertCreated();
        $this->programar($seleccionador, $otra, $otraPostulacion)->assertCreated();
        $this->assertSame(2, SeleccionCitacion::where('actividad', 'entrevista')->where('vigente', true)->count());
    }

    // ── Reprogramar, cancelar e historial ──────────────────────────────

    public function test_reprogramar_cambia_la_cita_deja_historial_y_avisa_una_sola_vez(): void
    {
        [$seleccionador, $convocatoria, $postulacion] = $this->escenario();
        $id = $this->programar($seleccionador, $convocatoria, $postulacion, ['fecha' => '2030-01-15', 'hora' => '14:30'])->json('data.id');
        $url = $this->urlCita($convocatoria, $postulacion, "/{$id}");
        $nueva = $this->cuerpo(['fecha' => '2030-01-16', 'hora' => '09:00', 'lugar_o_enlace' => 'https://meet.example.com/otra']);

        $this->actingAs($seleccionador)->putJson($url, $nueva)->assertOk()
            ->assertJsonPath('data.fecha', '2030-01-16')->assertJsonPath('data.hora', '09:00')->assertJsonPath('data.es_enlace', true)
            ->assertJsonPath('data.historial.1.tipo', 'reprogramada')
            ->assertJsonPath('data.historial.1.detalle.anterior.fecha_hora', '2030-01-15T19:30:00+00:00')
            ->assertJsonPath('data.historial.1.detalle.nuevo.fecha_hora', '2030-01-16T14:00:00+00:00')
            ->assertJsonPath('data.historial.1.por', $seleccionador->name);
        // Reenviar lo mismo no agrega historial ni otro aviso.
        $this->actingAs($seleccionador)->putJson($url, $nueva)->assertOk();

        $this->assertSame(2, SeleccionCitacion::first()->eventos()->count());
        $this->assertSame(['Cita programada', 'Cita reprogramada'], Notificacion::orderBy('id')->pluck('titulo')->all());
    }

    public function test_cancelar_pide_motivo_conserva_lo_anterior_y_permite_programar_de_nuevo(): void
    {
        [$seleccionador, $convocatoria, $postulacion] = $this->escenario();
        $id = $this->programar($seleccionador, $convocatoria, $postulacion)->json('data.id');
        $cancelar = $this->urlCita($convocatoria, $postulacion, "/{$id}/cancelar");

        $this->actingAs($seleccionador)->postJson($cancelar, ['motivo' => 'corto'])->assertUnprocessable()->assertJsonValidationErrors('motivo');
        $this->actingAs($seleccionador)->postJson($cancelar, ['motivo' => 'La persona evaluadora no puede asistir.'])->assertOk()
            ->assertJsonPath('data.estado', 'cancelada')->assertJsonPath('data.motivo_cancelacion', 'La persona evaluadora no puede asistir.');
        // Repetir la misma cancelación es inofensivo; otra distinta, no.
        $this->actingAs($seleccionador)->postJson($cancelar, ['motivo' => 'La persona evaluadora no puede asistir.'])->assertOk();
        $this->actingAs($seleccionador)->postJson($cancelar, ['motivo' => 'Otro motivo diferente al anterior'])->assertUnprocessable();
        // Una cita cancelada no se reprograma: se programa una nueva.
        $this->actingAs($seleccionador)->putJson($this->urlCita($convocatoria, $postulacion, "/{$id}"), $this->cuerpo())->assertUnprocessable();

        $nueva = $this->programar($seleccionador, $convocatoria, $postulacion, ['hora' => '10:00'])->assertCreated();
        $this->assertNotSame($id, $nueva->json('data.id'));
        $this->assertSame(2, SeleccionCitacion::count());
        $this->assertNull(SeleccionCitacion::find($id)->vigente);

        $this->actingAs($seleccionador)->getJson($this->urlCita($convocatoria, $postulacion))->assertOk()
            ->assertJsonCount(2, 'data.citaciones')
            ->assertJsonPath('data.citaciones.1.estado', 'cancelada')
            ->assertJsonPath('data.citaciones.1.historial.1.tipo', 'cancelada');
        $this->assertSame(['Cita programada', 'Cita cancelada', 'Cita programada'], Notificacion::orderBy('id')->pluck('titulo')->all());
    }

    // ── Condiciones y modos de Selección ───────────────────────────────

    public function test_sin_actividades_no_se_ofrecen_ni_se_aceptan_citaciones(): void
    {
        $ficha = $this->ficha('2896411');
        $seleccionador = $this->seleccionador($ficha);

        // Sin configuración, y con la configuración «sin actividades».
        $sinConfig = $this->convocatoria($ficha);
        $sinActividades = $this->convocatoria($ficha);
        $this->configurar($sinActividades, false, false);
        foreach ([$sinConfig, $sinActividades] as $convocatoria) {
            $postulacion = $this->candidatoHabilitado($convocatoria);
            $this->actingAs($seleccionador)->getJson($this->urlCita($convocatoria, $postulacion))->assertOk()
                ->assertJsonPath('data.actividades', [])->assertJsonPath('data.razon', 'Esta convocatoria no tiene actividades presenciales activas.');
            $this->programar($seleccionador, $convocatoria, $postulacion)->assertUnprocessable()->assertJsonValidationErrors('actividad');
        }
        $this->assertSame(0, SeleccionCitacion::count());
    }

    public function test_solo_se_ofrecen_las_actividades_activas(): void
    {
        $ficha = $this->ficha('2896411');
        $convocatoria = $this->convocatoria($ficha);
        $this->configurar($convocatoria, false, true);
        $seleccionador = $this->seleccionador($ficha);
        $postulacion = $this->candidatoHabilitado($convocatoria);

        $this->actingAs($seleccionador)->getJson($this->urlCita($convocatoria, $postulacion))->assertOk()
            ->assertJsonPath('data.actividades.0.id', 'prueba_tecnica')->assertJsonCount(1, 'data.actividades')->assertJsonPath('data.razon', null);
        $this->programar($seleccionador, $convocatoria, $postulacion, ['actividad' => 'entrevista'])->assertUnprocessable()->assertJsonValidationErrors('actividad');
        $this->programar($seleccionador, $convocatoria, $postulacion, ['actividad' => 'prueba_tecnica'])->assertCreated();
    }

    public function test_exige_convocatoria_en_proceso_postulacion_postulada_y_candidato_habilitado(): void
    {
        [$seleccionador, $convocatoria, $postulacion, $ficha] = $this->escenario();
        $sinEvaluar = $this->postulacion($convocatoria);
        $this->programar($seleccionador, $convocatoria, $sinEvaluar)->assertUnprocessable()->assertJsonValidationErrors('postulacion');

        $postulacion->update(['estado_id' => $this->parametroId('estado_postulacion', 'retirada')]);
        $this->programar($seleccionador, $convocatoria, $postulacion)->assertUnprocessable()->assertJsonValidationErrors('postulacion');

        $publicada = $this->convocatoria($ficha, 'publicada');
        $this->configurar($publicada);
        $this->programar($seleccionador, $publicada, $this->candidatoHabilitado($publicada))->assertUnprocessable()->assertJsonValidationErrors('convocatoria');
        $this->assertSame(0, SeleccionCitacion::count());
    }

    public function test_una_cita_no_es_requisito_para_calificar_ni_cambia_el_resultado(): void
    {
        [$seleccionador, $convocatoria, $postulacion] = $this->escenario();

        // Sin ninguna cita se puede calificar.
        $this->calificar($seleccionador, $convocatoria, $postulacion, 'entrevista', ['nota' => 8])->assertCreated();
        $antes = $this->actingAs($seleccionador)->getJson($this->url($convocatoria, $postulacion))->json('data');

        // Programar o cancelar citas después no altera las notas ni el resultado.
        $id = $this->programar($seleccionador, $convocatoria, $postulacion, ['actividad' => 'prueba_tecnica'])->json('data.id');
        $this->actingAs($seleccionador)->postJson($this->urlCita($convocatoria, $postulacion, "/{$id}/cancelar"), ['motivo' => 'Se reprogramará más adelante.'])->assertOk();
        $despues = $this->actingAs($seleccionador)->getJson($this->url($convocatoria, $postulacion))->json('data');
        $this->assertEquals($antes, $despues);
    }
}
