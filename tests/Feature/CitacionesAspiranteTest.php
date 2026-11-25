<?php

// Lo que ve el aspirante de sus citas: solo las suyas (de cualquier convocatoria), con el motivo si se cancelan, sin datos internos, y
// los avisos de la campana que lo llevan a «Mis postulaciones», donde las consulta.

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Notificacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CitacionesAyudas;
use Tests\Concerns\SeleccionCalificacionAyudas;
use Tests\TestCase;

class CitacionesAspiranteTest extends TestCase
{
    use CitacionesAyudas;
    use RefreshDatabase;
    use SeleccionCalificacionAyudas;

    public function test_cada_aspirante_recibe_solo_sus_propias_citas_con_el_nombre_de_la_convocatoria(): void
    {
        [$seleccionador, $convocatoria, $postulacion, $ficha] = $this->escenario();
        $otraPersona = $this->candidatoHabilitado($convocatoria);
        $this->programar($seleccionador, $convocatoria, $postulacion, ['fecha' => '2030-01-15', 'hora' => '14:30'])->assertCreated();
        $this->programar($seleccionador, $convocatoria, $otraPersona, ['hora' => '08:00', 'lugar_o_enlace' => 'Oficina privada de la otra persona'])->assertCreated();

        $propias = $this->actingAs($postulacion->user)->getJson('/api/citaciones')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.convocatoria', $convocatoria->nombre)
            ->assertJsonPath('data.0.actividad_etiqueta', 'Entrevista')
            ->assertJsonPath('data.0.fecha', '2030-01-15')->assertJsonPath('data.0.hora', '14:30')
            ->assertJsonPath('data.0.estado', 'programada');
        $this->assertStringNotContainsString('otra persona', $propias->getContent());

        // Datos internos: ni el historial ni quién la programó.
        $propias->assertJsonMissingPath('data.0.historial')->assertJsonMissingPath('data.0.programada_por');

        $this->actingAs($otraPersona->user)->getJson('/api/citaciones')->assertJsonCount(1, 'data')->assertJsonPath('data.0.hora', '08:00');
    }

    public function test_incluye_las_citas_de_distintas_convocatorias_y_las_canceladas_con_su_motivo(): void
    {
        [$seleccionador, $convocatoria, $postulacion, $ficha] = $this->escenario();
        $otra = $this->convocatoria($ficha);
        $this->configurar($otra, true, false);
        $otraPostulacion = $this->postulacion($otra);
        $otraPostulacion->update(['user_id' => $postulacion->user_id]);
        $this->evaluacion($otraPostulacion, 'finalizada', 90, true);

        $id = $this->programar($seleccionador, $convocatoria, $postulacion)->json('data.id');
        $this->programar($seleccionador, $otra, $otraPostulacion, ['fecha' => now('America/Bogota')->addDays(3)->format('Y-m-d')])->assertCreated();
        $this->actingAs($seleccionador)->postJson($this->urlCita($convocatoria, $postulacion, "/{$id}/cancelar"), ['motivo' => 'Se suspendió la jornada por mantenimiento.'])->assertOk();

        $this->actingAs($postulacion->user)->getJson('/api/citaciones')->assertOk()
            ->assertJsonCount(2, 'data')
            // Primero la programada y luego la cancelada, con el estado y el motivo.
            ->assertJsonPath('data.0.estado', 'programada')->assertJsonPath('data.0.convocatoria', $otra->nombre)
            ->assertJsonPath('data.1.estado', 'cancelada')->assertJsonPath('data.1.motivo_cancelacion', 'Se suspendió la jornada por mantenimiento.');
    }

    public function test_el_personal_y_quien_no_es_aspirante_no_usan_esta_ruta_y_sin_sesion_es_401(): void
    {
        [$seleccionador] = $this->escenario();
        $this->getJson('/api/citaciones')->assertUnauthorized();
        // La ruta pide el permiso de postulaciones propias: el seleccionador no lo tiene.
        $this->actingAs($seleccionador)->getJson('/api/citaciones')->assertForbidden();
    }

    public function test_los_avisos_son_del_aspirante_enlazan_a_mis_postulaciones_y_no_hay_correos(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        [$seleccionador, $convocatoria, $postulacion] = $this->escenario();
        $otra = $this->candidatoHabilitado($convocatoria);
        $id = $this->programar($seleccionador, $convocatoria, $postulacion, ['fecha' => '2030-01-15', 'hora' => '14:30'])->json('data.id');
        $this->actingAs($seleccionador)->putJson($this->urlCita($convocatoria, $postulacion, "/{$id}"), $this->cuerpo(['fecha' => '2030-01-16', 'hora' => '09:00']))->assertOk();
        $this->actingAs($seleccionador)->postJson($this->urlCita($convocatoria, $postulacion, "/{$id}/cancelar"), ['motivo' => 'La entrevista se hará más adelante.'])->assertOk();

        $avisos = Notificacion::where('user_id', $postulacion->user_id)->where('tipo', 'citacion')->orderBy('id')->get();
        $this->assertSame(['Cita programada', 'Cita reprogramada', 'Cita cancelada'], $avisos->pluck('titulo')->all());
        // Cada aviso lleva a la cita concreta (la misma, aunque se reprograme o se cancele).
        $this->assertSame(["/mis-postulaciones?cita={$id}"], $avisos->pluck('url')->unique()->values()->all());
        $this->assertStringContainsString('15/01/2030 a las 14:30 (hora de Colombia)', $avisos[0]->mensaje);
        $this->assertStringContainsString('16/01/2030 a las 09:00 (hora de Colombia)', $avisos[1]->mensaje);
        $this->assertStringContainsString('La entrevista se hará más adelante.', $avisos[2]->mensaje);
        // No se avisa a otras personas, ni al personal, ni por correo.
        $this->assertSame(0, Notificacion::where('user_id', $otra->user_id)->count());
        $this->assertSame(0, Notificacion::where('user_id', $seleccionador->id)->count());
        \Illuminate\Support\Facades\Mail::assertNothingSent();

        // La campana del aspirante lo muestra con ese enlace.
        $this->actingAs($postulacion->user)->getJson('/api/notificaciones')->assertOk()
            ->assertJsonFragment(['titulo' => 'Cita cancelada', 'url' => "/mis-postulaciones?cita={$id}"]);
    }

    public function test_el_contador_y_la_lectura_siguen_el_comportamiento_de_la_campana_y_no_se_cruzan_entre_aspirantes(): void
    {
        [$seleccionador, $convocatoria, $postulacion] = $this->escenario();
        $otra = $this->candidatoHabilitado($convocatoria);
        $id = $this->programar($seleccionador, $convocatoria, $postulacion, ['fecha' => '2030-01-15', 'hora' => '14:30'])->json('data.id');
        $this->actingAs($seleccionador)->putJson($this->urlCita($convocatoria, $postulacion, "/{$id}"), $this->cuerpo(['fecha' => '2030-01-16', 'hora' => '09:00']))->assertOk();
        // Reenviar lo mismo no repite el aviso.
        $this->actingAs($seleccionador)->putJson($this->urlCita($convocatoria, $postulacion, "/{$id}"), $this->cuerpo(['fecha' => '2030-01-16', 'hora' => '09:00']))->assertOk();

        $campana = $this->actingAs($postulacion->user)->getJson('/api/notificaciones')->assertOk();
        $campana->assertJsonPath('meta.no_leidas', 2)->assertJsonCount(2, 'data');
        $ids = collect($campana->json('data'))->pluck('id');

        // Leer uno baja el contador en uno; repetirlo no cambia nada.
        $this->actingAs($postulacion->user)->postJson("/api/notificaciones/{$ids[0]}/leer")->assertOk()->assertJsonPath('data.leida', true);
        $this->actingAs($postulacion->user)->postJson("/api/notificaciones/{$ids[0]}/leer")->assertOk();
        $this->actingAs($postulacion->user)->getJson('/api/notificaciones')->assertJsonPath('meta.no_leidas', 1);

        // Otro aspirante: no ve esos avisos, su contador es 0 y no puede leerlos (404, sin revelar que existen).
        $this->actingAs($otra->user)->getJson('/api/notificaciones')->assertOk()->assertJsonPath('meta.no_leidas', 0)->assertJsonCount(0, 'data');
        $this->actingAs($otra->user)->postJson("/api/notificaciones/{$ids[1]}/leer")->assertNotFound();
        $this->actingAs($postulacion->user)->getJson('/api/notificaciones')->assertJsonPath('meta.no_leidas', 1);
        // Tampoco ve la cita por la ruta del aspirante ni por la del personal.
        $this->actingAs($otra->user)->getJson('/api/citaciones')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($otra->user)->getJson($this->urlCita($convocatoria, $postulacion))->assertForbidden();
    }
}
