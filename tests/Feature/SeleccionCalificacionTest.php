<?php

namespace App\Tests\Feature;

use App\Enums\Role;
use App\Models\Convocatoria;
use App\Models\Evaluacion;
use App\Models\Ficha;
use App\Models\Parametro;
use App\Models\Postulacion;
use App\Models\SeleccionCalificacion;
use App\Models\SeleccionCalificacionCorreccion;
use App\Models\SeleccionConfiguracion;
use App\Models\User;
use Database\Seeders\ParametroSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeleccionCalificacionTest extends TestCase
{
    use RefreshDatabase;

    private static int $codigoSecuencia = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ParametroSeeder::class);
    }

    private function ficha(string $codigo): Ficha
    {
        return Ficha::create(['codigo' => $codigo, 'nombre_programa' => 'ADSO']);
    }

    private function usuario(Role $role, ?string $subrole = null, array $extra = []): User
    {
        return User::factory()->create($extra + [
            'role' => $role,
            'subrole' => $subrole,
            'active' => true,
            'email_verified_at' => now(),
        ]);
    }

    private function seleccionador(Ficha $ficha): User
    {
        return $this->usuario(Role::Aprendiz, 'seleccionador', ['ficha_id' => $ficha->id]);
    }

    private function parametroId(string $tema, string $codigo): int
    {
        return (int) Parametro::porTemaYCodigo($tema, $codigo)->id;
    }

    private function convocatoria(Ficha $ficha, string $estado = 'en_proceso'): Convocatoria
    {
        return Convocatoria::unguarded(fn () => Convocatoria::create([
            'ficha_id' => $ficha->id,
            'codigo' => sprintf('CONV-CAL-%03d', ++self::$codigoSecuencia),
            'nombre' => 'Convocatoria '.$ficha->codigo,
            'creado_por' => $this->usuario(Role::Instructor, null, ['ficha_id' => $ficha->id])->id,
            'estado_id' => $this->parametroId('estado_convocatoria', $estado),
            'tipo_convocatoria_id' => $this->parametroId('tipo_convocatoria', 'externo'),
            'tipo_empleo_id' => $this->parametroId('tipo_empleo', 'tiempo_completo'),
            'tipo_contrato_id' => $this->parametroId('tipo_contrato', 'termino_fijo'),
            'modalidad_id' => $this->parametroId('modalidad', 'presencial'),
            'vacantes' => 5,
        ]));
    }

    private function configurar(Convocatoria $convocatoria, bool $entrevista = true, bool $prueba = true, ?int $pesoE = 50, ?int $pesoP = 50): SeleccionConfiguracion
    {
        return SeleccionConfiguracion::create([
            'convocatoria_id' => $convocatoria->id,
            'entrevista_activa' => $entrevista,
            'prueba_tecnica_activa' => $prueba,
            // Igual que la configuración real: una sola actividad activa pesa 100.
            'peso_entrevista' => $entrevista ? ($prueba ? $pesoE : 100) : null,
            'peso_prueba_tecnica' => $prueba ? ($entrevista ? $pesoP : 100) : null,
        ]);
    }

    private function postulacion(Convocatoria $convocatoria, string $estado = 'postulada'): Postulacion
    {
        return Postulacion::create([
            'convocatoria_id' => $convocatoria->id,
            'user_id' => $this->usuario(Role::Aspirante, null, ['ficha_id' => $convocatoria->ficha_id])->id,
            'estado_id' => $this->parametroId('estado_postulacion', $estado),
            'fecha_postulacion' => now(),
        ]);
    }

    private function evaluacion(Postulacion $postulacion, string $estado, ?int $cumplimiento, ?bool $pasa): Evaluacion
    {
        return Evaluacion::create([
            'postulacion_id' => $postulacion->id,
            'evaluador_id' => $this->usuario(Role::Aprendiz, 'evaluador', ['ficha_id' => $postulacion->convocatoria->ficha_id])->id,
            'estado' => $estado,
            'cumplimiento' => $cumplimiento,
            'pasa_seleccion' => $pasa,
        ]);
    }

    /** Candidato habilitado documentalmente (una evaluación finalizada que pasa). */
    private function candidatoHabilitado(Convocatoria $convocatoria): Postulacion
    {
        $postulacion = $this->postulacion($convocatoria);
        $this->evaluacion($postulacion, 'finalizada', 85, true);

        return $postulacion;
    }

    private function url(Convocatoria $convocatoria, Postulacion $postulacion, string $actividad = ''): string
    {
        return "/api/seleccion/convocatorias/{$convocatoria->id}/candidatos/{$postulacion->id}/calificaciones".($actividad ? "/{$actividad}" : '');
    }

    private function calificar(User $actor, Convocatoria $convocatoria, Postulacion $postulacion, string $actividad, array $cuerpo)
    {
        return $this->actingAs($actor)->putJson($this->url($convocatoria, $postulacion, $actividad), $cuerpo + [
            'fecha_realizacion' => now('America/Bogota')->toDateString(),
        ]);
    }

    /** Escenario base: convocatoria en proceso con ambas actividades 50/50 y un candidato habilitado. */
    private function escenario(int $pesoE = 50, int $pesoP = 50): array
    {
        $ficha = $this->ficha('2896411');
        $convocatoria = $this->convocatoria($ficha);
        $this->configurar($convocatoria, true, true, $pesoE, $pesoP);

        return [$this->seleccionador($ficha), $convocatoria, $this->candidatoHabilitado($convocatoria), $ficha];
    }

    // ── Acceso ─────────────────────────────────────────────────────────

    public function test_invitado_recibe_401(): void
    {
        [, $convocatoria, $postulacion] = $this->escenario();

        $this->getJson($this->url($convocatoria, $postulacion))->assertUnauthorized();
        $this->putJson($this->url($convocatoria, $postulacion, 'entrevista'), ['nota' => 8])->assertUnauthorized();
    }

    public function test_sin_permisos_de_seleccion_recibe_403_y_no_guarda(): void
    {
        [, $convocatoria, $postulacion, $ficha] = $this->escenario();

        foreach ([
            $this->usuario(Role::Aprendiz, 'evaluador', ['ficha_id' => $ficha->id]),
            $this->usuario(Role::Aspirante, null, ['ficha_id' => $ficha->id]),
        ] as $actor) {
            $this->actingAs($actor)->getJson($this->url($convocatoria, $postulacion))->assertForbidden();
            $this->calificar($actor, $convocatoria, $postulacion, 'entrevista', ['nota' => 8])->assertForbidden();
        }
        $this->assertSame(0, SeleccionCalificacion::count());
    }

    public function test_la_autorizacion_se_resuelve_antes_que_la_existencia_y_exige_correo_verificado(): void
    {
        [, $convocatoria, $postulacion, $ficha] = $this->escenario();
        $evaluador = $this->usuario(Role::Aprendiz, 'evaluador', ['ficha_id' => $ficha->id]);
        $sinVerificar = $this->usuario(Role::Aprendiz, 'seleccionador', ['ficha_id' => $ficha->id, 'email_verified_at' => null]);

        $this->actingAs($evaluador)->getJson('/api/seleccion/convocatorias/999999/candidatos/999999/calificaciones')->assertForbidden();
        $this->actingAs($sinVerificar)->getJson($this->url($convocatoria, $postulacion))->assertForbidden();
    }

    public function test_ficha_ajena_postulacion_de_otra_convocatoria_e_inexistentes_responden_404(): void
    {
        [$seleccionador, $convocatoria, $postulacion, $ficha] = $this->escenario();
        $otraConvocatoria = $this->convocatoria($ficha);
        $postulacionAjena = $this->candidatoHabilitado($otraConvocatoria);
        $fichaAjena = $this->ficha('2631620');
        $convocatoriaAjena = $this->convocatoria($fichaAjena);
        $this->configurar($convocatoriaAjena);
        $candidatoAjeno = $this->candidatoHabilitado($convocatoriaAjena);

        // Ficha ajena.
        $this->actingAs($seleccionador)->getJson($this->url($convocatoriaAjena, $candidatoAjeno))->assertNotFound();
        $this->calificar($seleccionador, $convocatoriaAjena, $candidatoAjeno, 'entrevista', ['nota' => 8])->assertNotFound();
        // Postulación de otra convocatoria de la misma ficha.
        $this->actingAs($seleccionador)->getJson($this->url($convocatoria, $postulacionAjena))->assertNotFound();
        $this->calificar($seleccionador, $convocatoria, $postulacionAjena, 'entrevista', ['nota' => 8])->assertNotFound();
        // Inexistentes y actividad desconocida.
        $this->actingAs($seleccionador)->getJson("/api/seleccion/convocatorias/999999/candidatos/{$postulacion->id}/calificaciones")->assertNotFound();
        $this->actingAs($seleccionador)->getJson("/api/seleccion/convocatorias/{$convocatoria->id}/candidatos/999999/calificaciones")->assertNotFound();
        $this->calificar($seleccionador, $convocatoria, $postulacion, 'asado', ['nota' => 8])->assertNotFound();

        $this->assertSame(0, SeleccionCalificacion::count());
    }

    // ── Registro y valores ─────────────────────────────────────────────

    public function test_registra_cero_ocho_cincuenta_y_diez_como_notas_reales(): void
    {
        [$seleccionador, $convocatoria] = $this->escenario();

        foreach (['0' => '0.00', '8.50' => '8.50', '10' => '10.00'] as $enviada => $guardada) {
            $postulacion = $this->candidatoHabilitado($convocatoria);
            $this->calificar($seleccionador, $convocatoria, $postulacion, 'entrevista', ['nota' => (float) $enviada, 'observacion' => 'Buen desempeño'])
                ->assertCreated()
                ->assertJsonPath('data.actividades.entrevista.estado', 'registrada')
                ->assertJsonPath('data.actividades.entrevista.calificacion.nota', $guardada)
                ->assertJsonPath('data.actividades.entrevista.calificacion.registrada_por', $seleccionador->name)
                ->assertJsonPath('data.actividades.prueba_tecnica.estado', 'pendiente');

            $this->assertSame($guardada, SeleccionCalificacion::where('postulacion_id', $postulacion->id)->value('nota'));
        }
    }

    public function test_rechaza_valores_fuera_de_rango_o_con_precision_excesiva(): void
    {
        [$seleccionador, $convocatoria, $postulacion] = $this->escenario();

        foreach ([10.01, 11, -1, 100, 8.123, 8.555, 'abc', '8,5', true, [8], null, ''] as $nota) {
            $this->calificar($seleccionador, $convocatoria, $postulacion, 'entrevista', ['nota' => $nota])
                ->assertUnprocessable()->assertJsonValidationErrors('nota');
        }
        $this->calificar($seleccionador, $convocatoria, $postulacion, 'entrevista', [])->assertUnprocessable()->assertJsonValidationErrors('nota');

        // Fecha futura, mal formada u observación demasiado larga.
        $this->actingAs($seleccionador)->putJson($this->url($convocatoria, $postulacion, 'entrevista'), ['nota' => 8, 'fecha_realizacion' => now('America/Bogota')->addDays(2)->toDateString()])
            ->assertUnprocessable()->assertJsonValidationErrors('fecha_realizacion');
        $this->actingAs($seleccionador)->putJson($this->url($convocatoria, $postulacion, 'entrevista'), ['nota' => 8, 'fecha_realizacion' => '12/11/2026'])
            ->assertUnprocessable()->assertJsonValidationErrors('fecha_realizacion');
        $this->actingAs($seleccionador)->putJson($this->url($convocatoria, $postulacion, 'entrevista'), ['nota' => 8])
            ->assertUnprocessable()->assertJsonValidationErrors('fecha_realizacion');
        $this->calificar($seleccionador, $convocatoria, $postulacion, 'entrevista', ['nota' => 8, 'observacion' => str_repeat('x', 501)])
            ->assertUnprocessable()->assertJsonValidationErrors('observacion');

        $this->assertSame(0, SeleccionCalificacion::count());
    }

    // ── Condiciones para calificar ─────────────────────────────────────

    public function test_solo_se_califica_con_la_convocatoria_en_proceso_pero_las_notas_se_consultan_siempre(): void
    {
        [$seleccionador, $convocatoria, $postulacion] = $this->escenario();
        $this->calificar($seleccionador, $convocatoria, $postulacion, 'entrevista', ['nota' => 7])->assertCreated();

        foreach (['publicada', 'cerrada'] as $estado) {
            $convocatoria->forceFill(['estado_id' => $this->parametroId('estado_convocatoria', $estado)])->save();

            $this->calificar($seleccionador, $convocatoria, $postulacion, 'prueba_tecnica', ['nota' => 7])
                ->assertUnprocessable()->assertJsonValidationErrors('convocatoria');
            $this->calificar($seleccionador, $convocatoria, $postulacion, 'entrevista', ['nota' => 9, 'motivo' => 'Corrección de la nota original'])
                ->assertUnprocessable()->assertJsonValidationErrors('convocatoria');

            $this->actingAs($seleccionador)->getJson($this->url($convocatoria, $postulacion))->assertOk()
                ->assertJsonPath('data.calificable', false)
                ->assertJsonPath('data.actividades.entrevista.calificacion.nota', '7.00');
        }
        $this->assertSame(1, SeleccionCalificacion::count());
    }

    public function test_no_se_califica_una_postulacion_que_dejo_de_estar_postulada(): void
    {
        [$seleccionador, $convocatoria, $postulacion] = $this->escenario();
        $postulacion->update(['estado_id' => $this->parametroId('estado_postulacion', 'retirada')]);

        $this->calificar($seleccionador, $convocatoria, $postulacion, 'entrevista', ['nota' => 8])
            ->assertUnprocessable()->assertJsonValidationErrors('postulacion');
        $this->assertSame(0, SeleccionCalificacion::count());
    }

    public function test_actividad_desactivada_sin_configuracion_o_sin_actividades_se_rechaza(): void
    {
        $ficha = $this->ficha('2896411');
        $seleccionador = $this->seleccionador($ficha);

        // Sin configuración guardada.
        $sinConfig = $this->convocatoria($ficha);
        $p0 = $this->candidatoHabilitado($sinConfig);
        $this->calificar($seleccionador, $sinConfig, $p0, 'entrevista', ['nota' => 8])->assertUnprocessable()->assertJsonValidationErrors('actividad');

        // Solo entrevista: la prueba técnica está desactivada.
        $soloEntrevista = $this->convocatoria($ficha);
        $this->configurar($soloEntrevista, true, false);
        $p1 = $this->candidatoHabilitado($soloEntrevista);
        $this->calificar($seleccionador, $soloEntrevista, $p1, 'prueba_tecnica', ['nota' => 8])->assertUnprocessable()->assertJsonValidationErrors('actividad');
        $this->calificar($seleccionador, $soloEntrevista, $p1, 'entrevista', ['nota' => 8])->assertCreated();

        // Sin actividades explícito.
        $sinActividades = $this->convocatoria($ficha);
        $this->configurar($sinActividades, false, false);
        $p2 = $this->candidatoHabilitado($sinActividades);
        $this->calificar($seleccionador, $sinActividades, $p2, 'entrevista', ['nota' => 8])->assertUnprocessable()->assertJsonValidationErrors('actividad');

        $this->assertSame(1, SeleccionCalificacion::count());
    }

    public function test_solo_un_candidato_habilitado_puede_calificarse(): void
    {
        [$seleccionador, $convocatoria] = $this->escenario();

        $pendiente = $this->postulacion($convocatoria);
        $noHabilitado = $this->postulacion($convocatoria);
        $this->evaluacion($noHabilitado, 'finalizada', 30, false);
        $enRevision = $this->postulacion($convocatoria);
        $this->evaluacion($enRevision, 'finalizada', 90, true);
        $this->evaluacion($enRevision, 'finalizada', 20, false);

        foreach ([$pendiente, $noHabilitado, $enRevision] as $candidato) {
            $this->calificar($seleccionador, $convocatoria, $candidato, 'entrevista', ['nota' => 8])
                ->assertUnprocessable()->assertJsonValidationErrors('postulacion');
        }
        $this->assertSame(0, SeleccionCalificacion::count());
    }

    public function test_la_habilitacion_vale_si_la_decide_la_referencia_del_instructor(): void
    {
        [$seleccionador, $convocatoria] = $this->escenario();
        $instructor = $this->usuario(Role::Instructor, null, ['ficha_id' => $convocatoria->ficha_id]);
        $postulacion = $this->postulacion($convocatoria);
        $buena = $this->evaluacion($postulacion, 'finalizada', 90, true);
        $this->evaluacion($postulacion, 'finalizada', 20, false);

        $this->calificar($seleccionador, $convocatoria, $postulacion, 'entrevista', ['nota' => 8])->assertUnprocessable();

        $this->actingAs($instructor)->postJson("/api/seleccion/convocatorias/{$convocatoria->id}/candidatos/{$postulacion->id}/referencia-documental", [
            'evaluacion_id' => $buena->id,
            'justificacion' => 'La primera evaluación tiene el soporte completo.',
        ])->assertCreated();

        $this->calificar($seleccionador, $convocatoria, $postulacion, 'entrevista', ['nota' => 8])->assertCreated();
    }

    public function test_si_se_invalida_la_habilitacion_las_notas_siguen_visibles_y_no_se_registran_nuevas(): void
    {
        [$seleccionador, $convocatoria, $postulacion] = $this->escenario();
        $this->calificar($seleccionador, $convocatoria, $postulacion, 'entrevista', ['nota' => 9])->assertCreated();

        // La evaluación se reabre: el candidato deja de estar habilitado.
        Evaluacion::where('postulacion_id', $postulacion->id)->update(['estado' => 'en_proceso', 'pasa_seleccion' => null]);

        $this->actingAs($seleccionador)->getJson($this->url($convocatoria, $postulacion))->assertOk()
            ->assertJsonPath('data.estado_documental', 'pendiente')
            ->assertJsonPath('data.calificable', false)
            ->assertJsonPath('data.actividades.entrevista.calificacion.nota', '9.00');

        $this->calificar($seleccionador, $convocatoria, $postulacion, 'prueba_tecnica', ['nota' => 8])->assertUnprocessable();
        $this->calificar($seleccionador, $convocatoria, $postulacion, 'entrevista', ['nota' => 7, 'motivo' => 'Intento de corrección bloqueado'])->assertUnprocessable();
        $this->assertSame('9.00', SeleccionCalificacion::first()->nota);
    }

    // ── Reenvío y correcciones ─────────────────────────────────────────

    public function test_un_reenvio_identico_no_duplica_ni_corrige(): void
    {
        [$seleccionador, $convocatoria, $postulacion] = $this->escenario();
        $cuerpo = ['nota' => 8.5, 'fecha_realizacion' => '2026-11-10', 'observacion' => 'Puntual'];

        $this->actingAs($seleccionador)->putJson($this->url($convocatoria, $postulacion, 'entrevista'), $cuerpo)->assertCreated();
        $this->actingAs($seleccionador)->putJson($this->url($convocatoria, $postulacion, 'entrevista'), $cuerpo)->assertOk();
        // El mismo valor expresado distinto (8.50) también es idéntico.
        $this->actingAs($seleccionador)->putJson($this->url($convocatoria, $postulacion, 'entrevista'), ['nota' => '8.50'] + $cuerpo)->assertOk();

        $this->assertSame(1, SeleccionCalificacion::count());
        $this->assertSame(0, SeleccionCalificacionCorreccion::count());
    }

    public function test_corregir_exige_motivo_y_conserva_el_historial(): void
    {
        [$seleccionador, $convocatoria, $postulacion] = $this->escenario();
        $segundo = $this->seleccionador(Ficha::first());
        $this->actingAs($seleccionador)->putJson($this->url($convocatoria, $postulacion, 'entrevista'), ['nota' => 6, 'fecha_realizacion' => '2026-11-09', 'observacion' => 'Primera'])->assertCreated();

        // Sin motivo no se corrige.
        $this->actingAs($seleccionador)->putJson($this->url($convocatoria, $postulacion, 'entrevista'), ['nota' => 7.5, 'fecha_realizacion' => '2026-11-09'])
            ->assertUnprocessable()->assertJsonValidationErrors('motivo');
        $this->assertSame('6.00', SeleccionCalificacion::first()->nota);

        // Con motivo, queda el valor anterior y el nuevo.
        $this->actingAs($segundo)->putJson($this->url($convocatoria, $postulacion, 'entrevista'), [
            'nota' => 7.5, 'fecha_realizacion' => '2026-11-10', 'observacion' => 'Revisada', 'motivo' => 'Error de digitación en la nota',
        ])->assertOk()
            ->assertJsonPath('data.actividades.entrevista.calificacion.nota', '7.50')
            ->assertJsonPath('data.actividades.entrevista.calificacion.registrada_por', $seleccionador->name)
            ->assertJsonPath('data.actividades.entrevista.calificacion.correcciones.0.nota_anterior', '6.00')
            ->assertJsonPath('data.actividades.entrevista.calificacion.correcciones.0.nota_nueva', '7.50')
            ->assertJsonPath('data.actividades.entrevista.calificacion.correcciones.0.corregida_por', $segundo->name);

        // Una segunda corrección conserva ambas.
        $this->actingAs($seleccionador)->putJson($this->url($convocatoria, $postulacion, 'entrevista'), [
            'nota' => 0, 'fecha_realizacion' => '2026-11-10', 'observacion' => 'Revisada', 'motivo' => 'Se anula por irregularidad',
        ])->assertOk();

        $this->assertSame(1, SeleccionCalificacion::count());
        $correcciones = SeleccionCalificacionCorreccion::orderBy('id')->get();
        $this->assertSame(['6.00', '7.50'], $correcciones->pluck('nota_anterior')->all());
        $this->assertSame(['7.50', '0.00'], $correcciones->pluck('nota_nueva')->all());
        $this->assertSame('Primera', $correcciones[0]->observacion_anterior);
        $this->assertSame('0.00', SeleccionCalificacion::first()->nota);
    }

    // ── Resultado adicional ────────────────────────────────────────────

    public function test_distingue_pendiente_de_configurar_de_sin_actividades(): void
    {
        $ficha = $this->ficha('2896411');
        $seleccionador = $this->seleccionador($ficha);

        $sinConfig = $this->convocatoria($ficha);
        $p1 = $this->candidatoHabilitado($sinConfig);
        $this->actingAs($seleccionador)->getJson($this->url($sinConfig, $p1))->assertOk()
            ->assertJsonPath('data.resultado_adicional', ['estado' => 'pendiente_de_configurar', 'valor' => null])
            ->assertJsonPath('data.configuracion.configurada', false);

        $sinActividades = $this->convocatoria($ficha);
        $this->configurar($sinActividades, false, false);
        $p2 = $this->candidatoHabilitado($sinActividades);
        $this->actingAs($seleccionador)->getJson($this->url($sinActividades, $p2))->assertOk()
            ->assertJsonPath('data.resultado_adicional', ['estado' => 'sin_actividades', 'valor' => null])
            ->assertJsonPath('data.progreso', ['requeridas' => 0, 'registradas' => 0, 'pendientes' => [], 'completo' => false])
            ->assertJsonPath('data.actividades.entrevista.estado', 'desactivada');
    }

    public function test_el_resultado_se_calcula_con_pesos_40_60_solo_cuando_estan_todas_las_notas(): void
    {
        [$seleccionador, $convocatoria, $postulacion] = $this->escenario(40, 60);

        $this->actingAs($seleccionador)->getJson($this->url($convocatoria, $postulacion))->assertOk()
            ->assertJsonPath('data.resultado_adicional', ['estado' => 'incompleto', 'valor' => null])
            ->assertJsonPath('data.progreso.pendientes', ['entrevista', 'prueba_tecnica']);

        // Una nota real de 0 no es una actividad pendiente.
        $this->calificar($seleccionador, $convocatoria, $postulacion, 'entrevista', ['nota' => 0])->assertCreated()
            ->assertJsonPath('data.actividades.entrevista.estado', 'registrada')
            ->assertJsonPath('data.actividades.prueba_tecnica.estado', 'pendiente')
            ->assertJsonPath('data.resultado_adicional', ['estado' => 'incompleto', 'valor' => null])
            ->assertJsonPath('data.progreso', ['requeridas' => 2, 'registradas' => 1, 'pendientes' => ['prueba_tecnica'], 'completo' => false]);

        $this->calificar($seleccionador, $convocatoria, $postulacion, 'prueba_tecnica', ['nota' => 10])->assertCreated()
            ->assertJsonPath('data.resultado_adicional', ['estado' => 'completo', 'valor' => '6.00'])
            ->assertJsonPath('data.progreso.completo', true);

        // 8.50 * 40 % + 7.25 * 60 % = 7.75
        $otro = $this->candidatoHabilitado($convocatoria);
        $this->calificar($seleccionador, $convocatoria, $otro, 'entrevista', ['nota' => 8.5])->assertCreated();
        $this->calificar($seleccionador, $convocatoria, $otro, 'prueba_tecnica', ['nota' => 7.25])->assertCreated()
            ->assertJsonPath('data.resultado_adicional.valor', '7.75');
    }

    public function test_una_sola_actividad_vale_cien_y_el_redondeo_es_mitad_hacia_arriba(): void
    {
        $ficha = $this->ficha('2896411');
        $seleccionador = $this->seleccionador($ficha);

        $soloPrueba = $this->convocatoria($ficha);
        $this->configurar($soloPrueba, false, true);
        $p = $this->candidatoHabilitado($soloPrueba);
        $this->calificar($seleccionador, $soloPrueba, $p, 'prueba_tecnica', ['nota' => 9.35])->assertCreated()
            ->assertJsonPath('data.resultado_adicional', ['estado' => 'completo', 'valor' => '9.35']);

        // 0.01 * 50 % + 0.00 * 50 % = 0.005 -> 0.01 (la mitad sube).
        $ambas = $this->convocatoria($ficha);
        $this->configurar($ambas);
        $q = $this->candidatoHabilitado($ambas);
        $this->calificar($seleccionador, $ambas, $q, 'entrevista', ['nota' => 0.01])->assertCreated();
        $this->calificar($seleccionador, $ambas, $q, 'prueba_tecnica', ['nota' => 0])->assertCreated()
            ->assertJsonPath('data.resultado_adicional.valor', '0.01');
    }

    // ── Bloqueo de la configuración ────────────────────────────────────

    public function test_la_primera_nota_bloquea_cambios_de_configuracion_de_toda_la_convocatoria(): void
    {
        [$seleccionador, $convocatoria, $postulacion] = $this->escenario(40, 60);
        $url = "/api/seleccion/convocatorias/{$convocatoria->id}/configuracion";
        $otroCandidato = $this->candidatoHabilitado($convocatoria);

        $this->actingAs($seleccionador)->getJson($url)->assertOk()
            ->assertJsonPath('data.bloqueada', false)
            ->assertJsonPath('data.editable', true);
        $this->actingAs($seleccionador)->putJson($url, ['entrevista_activa' => true, 'prueba_tecnica_activa' => false])->assertOk();
        $this->actingAs($seleccionador)->putJson($url, ['entrevista_activa' => true, 'prueba_tecnica_activa' => true, 'peso_entrevista' => 40, 'peso_prueba_tecnica' => 60])->assertOk();

        // Un solo registro, de un candidato, congela a todos.
        $this->calificar($seleccionador, $convocatoria, $postulacion, 'entrevista', ['nota' => 8])->assertCreated();

        $this->actingAs($seleccionador)->getJson($url)->assertOk()
            ->assertJsonPath('data.bloqueada', true)
            ->assertJsonPath('data.editable', false)
            ->assertJsonPath('data.estado_convocatoria', 'en_proceso');
        // Cambia actividades, pesos o ambos: rechazado.
        $this->actingAs($seleccionador)->putJson($url, ['entrevista_activa' => true, 'prueba_tecnica_activa' => false])
            ->assertUnprocessable()->assertJsonValidationErrors('convocatoria');
        $this->actingAs($seleccionador)->putJson($url, ['entrevista_activa' => true, 'prueba_tecnica_activa' => true, 'peso_entrevista' => 50, 'peso_prueba_tecnica' => 50])
            ->assertUnprocessable();
        $this->actingAs($seleccionador)->putJson($url, ['entrevista_activa' => false, 'prueba_tecnica_activa' => false])->assertUnprocessable();

        // Reenviar exactamente lo guardado sigue respondiendo con la configuración existente.
        $this->actingAs($seleccionador)->putJson($url, ['entrevista_activa' => true, 'prueba_tecnica_activa' => true, 'peso_entrevista' => 40, 'peso_prueba_tecnica' => 60])
            ->assertOk()->assertJsonPath('data.bloqueada', true)->assertJsonPath('data.prueba_tecnica.peso', 60);

        $this->assertSame(1, SeleccionConfiguracion::count());
        $this->assertSame(60, SeleccionConfiguracion::first()->peso_prueba_tecnica);
        // El segundo candidato sigue calificándose con la configuración congelada.
        $this->calificar($seleccionador, $convocatoria, $otroCandidato, 'prueba_tecnica', ['nota' => 7])->assertCreated();
    }

    public function test_el_bloqueo_es_por_convocatoria(): void
    {
        [$seleccionador, $convocatoria, $postulacion, $ficha] = $this->escenario();
        $otra = $this->convocatoria($ficha);
        $this->configurar($otra);

        $this->calificar($seleccionador, $convocatoria, $postulacion, 'entrevista', ['nota' => 8])->assertCreated();

        $this->actingAs($seleccionador)->putJson("/api/seleccion/convocatorias/{$otra->id}/configuracion", ['entrevista_activa' => false, 'prueba_tecnica_activa' => true])
            ->assertOk()->assertJsonPath('data.bloqueada', false);
    }
}
