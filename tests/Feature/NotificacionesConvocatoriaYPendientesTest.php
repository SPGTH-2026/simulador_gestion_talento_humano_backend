<?php

namespace Tests\Feature;

use App\Enums\ConvocatoriaEstado;
use App\Enums\Role;
use App\Models\Convocatoria;
use App\Models\CorreccionPostulacion;
use App\Models\Evaluacion;
use App\Models\Ficha;
use App\Models\Notificacion;
use App\Models\Postulacion;
use App\Models\User;
use App\Services\Notificaciones\Avisos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EvaluacionAyudas;
use Tests\TestCase;

/**
 * Avisos de los estados de la convocatoria (para aspirantes) y resumen de pendientes por rol.
 */
class NotificacionesConvocatoriaYPendientesTest extends TestCase
{
    use RefreshDatabase;
    use EvaluacionAyudas;

    /** @return list<int> ids de quienes recibieron un aviso de ese tipo, ordenados */
    private function recibidos(string $tipo): array
    {
        return Notificacion::query()->where('tipo', $tipo)->pluck('user_id')->sort()->values()->all();
    }

    /** @param  list<User>  $usuarios  @return list<int> */
    private function ids(array $usuarios): array
    {
        return collect($usuarios)->map(fn (User $u) => $u->id)->sort()->values()->all();
    }

    private function mensajeDe(User $usuario, string $tipo): ?string
    {
        return Notificacion::query()->where('user_id', $usuario->id)->where('tipo', $tipo)->value('mensaje');
    }

    /** Instructor de la ficha con una convocatoria en el estado pedido. */
    private function escenario(string $estado = 'publicada', ?string $cierre = null): array
    {
        $ficha = $this->ficha('2896411');
        $instructor = $this->usuario(Role::Instructor);
        $ficha->instructores()->attach($instructor->id);
        $convocatoria = $this->convocatoria($ficha, $instructor, $estado);
        if ($cierre !== null) {
            $convocatoria->forceFill(['fecha_cierre' => $cierre])->save();
        }

        return ['ficha' => $ficha, 'instructor' => $instructor, 'convocatoria' => $convocatoria];
    }

    private function aspirante(Ficha $ficha, string $nombre = 'Aspirante'): User
    {
        $aspirante = $this->usuario(Role::Aspirante, null, ['ficha_id' => $ficha->id]);
        $aspirante->forceFill(['name' => $nombre])->save();

        return $aspirante;
    }

    private function evaluar(Postulacion $postulacion, User $evaluador, bool $pasa): void
    {
        Evaluacion::unguarded(fn () => Evaluacion::create([
            'postulacion_id' => $postulacion->id,
            'evaluador_id' => $evaluador->id,
            'estado' => 'finalizada',
            'cumplimiento' => $pasa ? 100 : 0,
            'pasa_seleccion' => $pasa,
            'puntaje_total' => $pasa ? 90 : 10,
        ]));
    }

    // ── Estados de la convocatoria ───────────────────────────────────────

    public function test_publicar_avisa_a_los_aspirantes_de_la_ficha_y_no_a_los_de_otra(): void
    {
        $e = $this->escenario('borrador');
        $dentro = $this->aspirante($e['ficha']);
        $otraFicha = $this->aspirante($this->ficha('2896412'));

        app(Avisos::class)->convocatoriaCambio($e['convocatoria'], ConvocatoriaEstado::Publicada);

        $this->assertSame($this->ids([$dentro]), $this->recibidos('convocatoria_publicada'));
        $this->assertNotContains($otraFicha->id, $this->recibidos('convocatoria_publicada'));
    }

    public function test_recepcion_cerrada_dice_a_cada_aspirante_si_su_postulacion_pasa_a_evaluacion(): void
    {
        $e = $this->escenario('publicada');
        $postulado = $this->aspirante($e['ficha']);
        $sinPostular = $this->aspirante($e['ficha']);
        $this->postulacion($e['convocatoria'], $postulado);

        $this->actingAs($e['instructor'])->patchJson("/api/convocatorias/{$e['convocatoria']->id}/estado", ['estado' => 'en_proceso'])
            ->assertOk();

        $this->assertSame($this->ids([$postulado, $sinPostular]), $this->recibidos('convocatoria_recepcion_cerrada'));
        $this->assertStringContainsString('pasa a evaluación', $this->mensajeDe($postulado, 'convocatoria_recepcion_cerrada'));
        $this->assertStringContainsString('Ya no se reciben', $this->mensajeDe($sinPostular, 'convocatoria_recepcion_cerrada'));
    }

    public function test_cerrar_la_convocatoria_avisa_solo_a_quien_se_postulo(): void
    {
        $e = $this->escenario('en_proceso');
        $postulado = $this->aspirante($e['ficha']);
        $this->aspirante($e['ficha']);
        $this->postulacion($e['convocatoria'], $postulado);

        $this->actingAs($e['instructor'])->patchJson("/api/convocatorias/{$e['convocatoria']->id}/estado", ['estado' => 'cerrada'])
            ->assertOk();

        $this->assertSame($this->ids([$postulado]), $this->recibidos('convocatoria_cerrada'));
    }

    public function test_el_cierre_automatico_por_fecha_avisa_que_la_recepcion_cerro(): void
    {
        $e = $this->escenario('publicada', now('America/Bogota')->subDay()->toDateString());
        $postulado = $this->aspirante($e['ficha']);
        $this->postulacion($e['convocatoria'], $postulado);

        $this->artisan('convocatorias:cerrar-vencidas')->assertSuccessful();

        $this->assertSame($this->ids([$postulado]), $this->recibidos('convocatoria_recepcion_cerrada'));
        $this->assertSame('en_proceso', $e['convocatoria']->fresh()->estado->codigo);
    }

    // ── Pendientes por rol (resumen diario) ──────────────────────────────

    public function test_resumen_cuenta_lo_pendiente_de_cada_evaluador_seleccionador_e_instructor(): void
    {
        $e = $this->escenario('publicada');
        $f = $e['ficha'];
        $evaluador1 = $this->usuario(Role::Aprendiz, 'evaluador', ['ficha_id' => $f->id]);
        $evaluador2 = $this->usuario(Role::Aprendiz, 'evaluador', ['ficha_id' => $f->id]);
        $seleccionador = $this->usuario(Role::Aprendiz, 'seleccionador', ['ficha_id' => $f->id]);
        $general = $this->usuario(Role::Aprendiz, 'general', ['ficha_id' => $f->id]);

        $p1 = $this->postulacion($e['convocatoria'], $this->aspirante($f));
        $this->postulacion($e['convocatoria'], $this->aspirante($f));
        // El evaluador 1 ya finalizó a A1 y la aprobó: A1 queda pendiente de selección, no de evaluación para él.
        $this->evaluar($p1, $evaluador1, pasa: true);

        $this->artisan('notificaciones:resumen-diario')->assertSuccessful();

        $this->assertStringContainsString('1 aspirante pendiente', (string) $this->mensajeDe($evaluador1, 'pendientes_evaluar'));
        $this->assertStringContainsString('2 aspirantes pendientes', (string) $this->mensajeDe($evaluador2, 'pendientes_evaluar'));
        $this->assertSame($this->ids([$evaluador1, $evaluador2, $e['instructor']]), $this->recibidos('pendientes_evaluar'));
        $this->assertSame($this->ids([$seleccionador, $e['instructor']]), $this->recibidos('pendientes_seleccion'));
        $this->assertSame([], $this->recibidos('convocatorias_por_cerrar'));
        $this->assertNotContains($general->id, $this->recibidos('pendientes_evaluar'));
        $this->assertNotContains($general->id, $this->recibidos('pendientes_seleccion'));
    }

    public function test_aprobado_con_decision_de_seleccion_deja_de_estar_pendiente(): void
    {
        $e = $this->escenario('publicada');
        $seleccionador = $this->usuario(Role::Aprendiz, 'seleccionador', ['ficha_id' => $e['ficha']->id]);
        $p1 = $this->postulacion($e['convocatoria'], $this->aspirante($e['ficha']));
        $this->evaluar($p1, $this->usuario(Role::Aprendiz, 'evaluador', ['ficha_id' => $e['ficha']->id]), pasa: true);
        \App\Models\SeleccionDecision::query()->create([
            'convocatoria_id' => $e['convocatoria']->id,
            'postulacion_id' => $p1->id,
            'resultado' => 'seleccionado',
            'motivo' => 'Cumple el perfil',
            'lista_version' => 1,
            'decidido_por' => $seleccionador->id,
        ]);

        $this->artisan('notificaciones:resumen-diario')->assertSuccessful();

        $this->assertSame([], $this->recibidos('pendientes_seleccion'));
    }

    public function test_gestor_recibe_las_convocatorias_en_proceso_por_cerrar(): void
    {
        $e = $this->escenario('en_proceso');
        $gestor = $this->usuario(Role::Aprendiz, 'gestor_convocatorias', ['ficha_id' => $e['ficha']->id]);

        $this->artisan('notificaciones:resumen-diario')->assertSuccessful();

        $this->assertSame($this->ids([$gestor, $e['instructor']]), $this->recibidos('convocatorias_por_cerrar'));
        $this->assertStringContainsString('1 convocatoria en proceso de la ficha', (string) $this->mensajeDe($gestor, 'convocatorias_por_cerrar'));
        $this->assertStringContainsString('espera cierre', (string) $this->mensajeDe($gestor, 'convocatorias_por_cerrar'));
    }

    public function test_resumen_del_aspirante_borrador_correccion_por_vencer_y_cierre_proximo(): void
    {
        $manana = now('America/Bogota')->addDay()->toDateString();
        $e = $this->escenario('publicada', $manana);
        $f = $e['ficha'];

        $sinPostular = $this->aspirante($f);
        $conBorrador = $this->aspirante($f);
        $conCorreccion = $this->aspirante($f);

        $borrador = $this->postulacion($e['convocatoria'], $conBorrador);
        $borrador->update(['estado_id' => $this->parametroId('estado_postulacion', 'borrador')]);

        $postulacionCorreccion = $this->postulacion($e['convocatoria'], $conCorreccion);
        CorreccionPostulacion::query()->create([
            'postulacion_id' => $postulacionCorreccion->id,
            'tipo' => 'ordinaria',
            'numero' => 1,
            'documentos_marcados' => [],
            'solicitada_at' => now(),
            'fecha_limite' => now()->addHours(10),
            'abierta' => 1,
            'origen' => 'nueva',
        ]);

        $this->artisan('notificaciones:resumen-diario')->assertSuccessful();

        $this->assertSame($this->ids([$sinPostular]), $this->recibidos('cierre_proximo'));
        $this->assertSame($this->ids([$conBorrador]), $this->recibidos('borrador_pendiente'));
        $this->assertSame($this->ids([$conCorreccion]), $this->recibidos('correccion_por_vencer'));
    }

    public function test_el_resumen_no_repite_los_avisos_del_mismo_dia(): void
    {
        $e = $this->escenario('publicada');
        $evaluador = $this->usuario(Role::Aprendiz, 'evaluador', ['ficha_id' => $e['ficha']->id]);
        $this->postulacion($e['convocatoria'], $this->aspirante($e['ficha']));

        $this->artisan('notificaciones:resumen-diario')->assertSuccessful();
        $this->artisan('notificaciones:resumen-diario')->assertSuccessful();

        $this->assertSame(1, Notificacion::query()->where('user_id', $evaluador->id)->where('tipo', 'pendientes_evaluar')->count());
    }

    public function test_sin_pendientes_no_se_envia_nada(): void
    {
        $this->escenario('publicada');

        $this->artisan('notificaciones:resumen-diario')->assertSuccessful();

        $this->assertDatabaseCount('notificaciones', 0);
    }
}
