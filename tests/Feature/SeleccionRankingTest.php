<?php

namespace App\Tests\Feature;

use App\Enums\Role;
use App\Models\Convocatoria;
use App\Models\Evaluacion;
use App\Models\Ficha;
use App\Models\Parametro;
use App\Models\Postulacion;
use App\Models\SeleccionCalificacion;
use App\Models\SeleccionConfiguracion;
use App\Models\SeleccionFinalistaLista;
use App\Models\User;
use Database\Seeders\ParametroSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SeleccionRankingTest extends TestCase
{
    use RefreshDatabase;

    private static int $codigoSecuencia = 0;

    private ?User $evaluadorCompartido = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ParametroSeeder::class);
    }

    private function ficha(string $codigo = '2896411'): Ficha
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

    private function convocatoria(Ficha $ficha, string $estado = 'en_proceso', int $vacantes = 2): Convocatoria
    {
        return Convocatoria::unguarded(fn () => Convocatoria::create([
            'ficha_id' => $ficha->id,
            'codigo' => sprintf('CONV-RNK-%03d', ++self::$codigoSecuencia),
            'nombre' => 'Convocatoria '.$ficha->codigo,
            'creado_por' => $this->usuario(Role::Instructor, null, ['ficha_id' => $ficha->id])->id,
            'estado_id' => $this->parametroId('estado_convocatoria', $estado),
            'tipo_convocatoria_id' => $this->parametroId('tipo_convocatoria', 'externo'),
            'tipo_empleo_id' => $this->parametroId('tipo_empleo', 'tiempo_completo'),
            'tipo_contrato_id' => $this->parametroId('tipo_contrato', 'termino_fijo'),
            'modalidad_id' => $this->parametroId('modalidad', 'presencial'),
            'vacantes' => $vacantes,
        ]));
    }

    private function configurar(Convocatoria $convocatoria, bool $entrevista = true, bool $prueba = true, ?int $pesoE = 50, ?int $pesoP = 50): void
    {
        SeleccionConfiguracion::create([
            'convocatoria_id' => $convocatoria->id,
            'entrevista_activa' => $entrevista,
            'prueba_tecnica_activa' => $prueba,
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

    private function evaluacion(Postulacion $postulacion, string $estado, ?int $cumplimiento, ?bool $pasa, bool $nuevoEvaluador = false): Evaluacion
    {
        $this->evaluadorCompartido ??= $this->usuario(Role::Aprendiz, 'evaluador', ['ficha_id' => $postulacion->convocatoria->ficha_id]);
        $evaluador = $nuevoEvaluador ? $this->usuario(Role::Aprendiz, 'evaluador', ['ficha_id' => $postulacion->convocatoria->ficha_id]) : $this->evaluadorCompartido;

        return Evaluacion::create([
            'postulacion_id' => $postulacion->id,
            'evaluador_id' => $evaluador->id,
            'estado' => $estado,
            'cumplimiento' => $cumplimiento,
            'pasa_seleccion' => $pasa,
        ]);
    }

    private function nota(Postulacion $postulacion, string $actividad, string $valor): SeleccionCalificacion
    {
        return SeleccionCalificacion::create([
            'convocatoria_id' => $postulacion->convocatoria_id,
            'postulacion_id' => $postulacion->id,
            'actividad' => $actividad,
            'nota' => $valor,
            'fecha_realizacion' => '2026-11-10',
        ]);
    }

    /** Candidato habilitado con las notas indicadas (entrevista, prueba técnica). */
    private function candidato(Convocatoria $convocatoria, ?string $entrevista, ?string $prueba = null): Postulacion
    {
        $postulacion = $this->postulacion($convocatoria);
        $this->evaluacion($postulacion, 'finalizada', 85, true);
        if ($entrevista !== null) {
            $this->nota($postulacion, 'entrevista', $entrevista);
        }
        if ($prueba !== null) {
            $this->nota($postulacion, 'prueba_tecnica', $prueba);
        }

        return $postulacion;
    }

    private function ranking(User $actor, Convocatoria $convocatoria, string $consulta = '')
    {
        return $this->actingAs($actor)->getJson("/api/seleccion/convocatorias/{$convocatoria->id}/ranking{$consulta}");
    }

    private function confirmar(User $actor, Convocatoria $convocatoria, string $version, array $ids, ?string $justificacion = null)
    {
        return $this->actingAs($actor)->postJson("/api/seleccion/convocatorias/{$convocatoria->id}/finalistas", [
            'version' => $version,
            'postulacion_ids' => array_map(fn ($p) => $p instanceof Postulacion ? $p->id : $p, $ids),
            'justificacion' => $justificacion,
        ]);
    }

    /** Escenario con actividades 50/50 y notas de entrevista únicas (la prueba repite la misma). */
    private function escenarioConNotas(array $notas, int $vacantes = 2, string $codigoFicha = '2896411'): array
    {
        $ficha = $this->ficha($codigoFicha);
        $convocatoria = $this->convocatoria($ficha, 'en_proceso', $vacantes);
        $this->configurar($convocatoria);
        $candidatos = array_map(fn (string $n) => $this->candidato($convocatoria, $n, $n), $notas);

        return [$this->seleccionador($ficha), $convocatoria, $candidatos];
    }

    // ── Acceso ─────────────────────────────────────────────────────────

    public function test_invitado_recibe_401_en_todas_las_rutas(): void
    {
        $convocatoria = $this->convocatoria($this->ficha());
        $base = "/api/seleccion/convocatorias/{$convocatoria->id}";

        $this->getJson("{$base}/ranking")->assertUnauthorized();
        $this->putJson("{$base}/ranking/objetivo", ['objetivo' => 3])->assertUnauthorized();
        $this->getJson("{$base}/finalistas")->assertUnauthorized();
        $this->postJson("{$base}/finalistas", [])->assertUnauthorized();
    }

    public function test_sin_permisos_recibe_403_y_la_autorizacion_va_antes_que_la_existencia(): void
    {
        $ficha = $this->ficha();
        $convocatoria = $this->convocatoria($ficha);
        $base = "/api/seleccion/convocatorias/{$convocatoria->id}";

        foreach ([$this->usuario(Role::Aprendiz, 'evaluador', ['ficha_id' => $ficha->id]), $this->usuario(Role::Aspirante, null, ['ficha_id' => $ficha->id])] as $actor) {
            $this->actingAs($actor)->getJson("{$base}/ranking")->assertForbidden();
            $this->actingAs($actor)->putJson("{$base}/ranking/objetivo", ['objetivo' => 3])->assertForbidden();
            $this->actingAs($actor)->getJson("{$base}/finalistas")->assertForbidden();
            $this->actingAs($actor)->postJson("{$base}/finalistas", ['version' => 'x', 'postulacion_ids' => [1]])->assertForbidden();
            $this->actingAs($actor)->getJson('/api/seleccion/convocatorias/999999/ranking')->assertForbidden();
        }
        $this->assertSame(0, SeleccionFinalistaLista::count());
    }

    public function test_ficha_ajena_responde_404_y_el_superadmin_ve_todas(): void
    {
        $fichaA = $this->ficha('2896411');
        $ajena = $this->convocatoria($this->ficha('2631620'));
        $this->configurar($ajena);
        $this->candidato($ajena, '8', '8');
        $seleccionador = $this->seleccionador($fichaA);
        $base = "/api/seleccion/convocatorias/{$ajena->id}";

        $this->actingAs($seleccionador)->getJson("{$base}/ranking")->assertNotFound();
        $this->actingAs($seleccionador)->putJson("{$base}/ranking/objetivo", ['objetivo' => 3])->assertNotFound();
        $this->actingAs($seleccionador)->getJson("{$base}/finalistas")->assertNotFound();
        $this->actingAs($seleccionador)->postJson("{$base}/finalistas", ['version' => 'x', 'postulacion_ids' => [1]])->assertNotFound();
        $this->actingAs($seleccionador)->getJson('/api/seleccion/convocatorias/999999/ranking')->assertNotFound();

        $this->ranking($this->usuario(Role::SuperAdmin), $ajena)->assertOk()->assertJsonPath('meta.total', 1);
    }

    // ── Elegibilidad ───────────────────────────────────────────────────

    public function test_solo_los_candidatos_listos_entran_al_ranking(): void
    {
        $ficha = $this->ficha();
        $convocatoria = $this->convocatoria($ficha);
        $this->configurar($convocatoria);
        $seleccionador = $this->seleccionador($ficha);

        $listo = $this->candidato($convocatoria, '8', '9');
        $this->candidato($convocatoria, '8', null);                                  // falta la prueba
        $this->candidato($convocatoria, null, null);                                 // sin notas
        $sinEval = $this->postulacion($convocatoria);                                // pendiente documental
        $this->nota($sinEval, 'entrevista', '9');
        $this->nota($sinEval, 'prueba_tecnica', '9');
        $noHab = $this->postulacion($convocatoria);
        $this->evaluacion($noHab, 'finalizada', 30, false);
        $this->nota($noHab, 'entrevista', '9');
        $this->nota($noHab, 'prueba_tecnica', '9');
        $revision = $this->postulacion($convocatoria);
        $this->evaluacion($revision, 'finalizada', 90, true);
        $this->evaluacion($revision, 'finalizada', 20, false, true);
        $this->nota($revision, 'entrevista', '9');
        $this->nota($revision, 'prueba_tecnica', '9');
        $retirada = $this->postulacion($convocatoria, 'retirada');
        $this->evaluacion($retirada, 'finalizada', 90, true);
        $this->nota($retirada, 'entrevista', '9');
        $this->nota($retirada, 'prueba_tecnica', '9');

        $respuesta = $this->ranking($seleccionador, $convocatoria)->assertOk();

        $this->assertSame([$listo->id], collect($respuesta->json('data'))->pluck('postulacion_id')->all());
        $respuesta->assertJsonPath('meta.listos', 1)->assertJsonPath('meta.no_listos', 5)->assertJsonPath('meta.postuladas', 6);
        $this->assertSame(
            ['postulacion_id', 'aspirante', 'posicion', 'resultado_adicional', 'cumplimiento_documental', 'sugerencia', 'finalista'],
            array_keys($respuesta->json('data.0')),
        );
        foreach (['email', 'observacion', 'documentos', 'motivo'] as $prohibido) {
            $this->assertStringNotContainsString($prohibido, $respuesta->getContent());
        }
    }

    public function test_con_la_configuracion_pendiente_nadie_esta_listo(): void
    {
        $ficha = $this->ficha();
        $convocatoria = $this->convocatoria($ficha);
        $this->candidato($convocatoria, null, null);

        $this->ranking($this->seleccionador($ficha), $convocatoria)->assertOk()
            ->assertJsonPath('meta.modo', 'pendiente_de_configurar')
            ->assertJsonPath('meta.total', 0)
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.postuladas', 1);
    }

    public function test_una_habilitacion_invalidada_saca_al_candidato_pero_conserva_sus_notas(): void
    {
        [$seleccionador, $convocatoria, [$a, $b]] = $this->escenarioConNotas(['9', '8']);

        $this->ranking($seleccionador, $convocatoria)->assertJsonPath('meta.total', 2);
        Evaluacion::where('postulacion_id', $a->id)->update(['estado' => 'en_proceso', 'pasa_seleccion' => null]);

        $this->assertSame([$b->id], collect($this->ranking($seleccionador, $convocatoria)->json('data'))->pluck('postulacion_id')->all());
        $this->assertSame(2, SeleccionCalificacion::where('postulacion_id', $a->id)->count());
    }

    // ── Orden, ponderación y posiciones ────────────────────────────────

    public function test_ordena_por_resultado_ponderado_e_incluye_la_nota_cero(): void
    {
        $ficha = $this->ficha();
        $convocatoria = $this->convocatoria($ficha);
        $this->configurar($convocatoria, true, true, 40, 60);
        $seleccionador = $this->seleccionador($ficha);

        $a = $this->candidato($convocatoria, '8.50', '7.25'); // 7.75
        $b = $this->candidato($convocatoria, '10', '5');       // 7.00
        $c = $this->candidato($convocatoria, '0', '10');       // 6.00 (la nota cero es válida)
        $d = $this->candidato($convocatoria, '0', '0');        // 0.00

        $filas = $this->ranking($seleccionador, $convocatoria)->assertOk()->json('data');

        $this->assertSame([$a->id, $b->id, $c->id, $d->id], collect($filas)->pluck('postulacion_id')->all());
        $this->assertSame(['7.75', '7.00', '6.00', '0.00'], collect($filas)->pluck('resultado_adicional')->all());
        $this->assertSame([1, 2, 3, 4], collect($filas)->pluck('posicion')->all());
        $this->assertSame(85, $filas[0]['cumplimiento_documental']);
    }

    public function test_el_ranking_da_el_mismo_resultado_que_el_detalle_de_calificaciones(): void
    {
        $ficha = $this->ficha();
        $convocatoria = $this->convocatoria($ficha);
        $this->configurar($convocatoria, true, true, 30, 70);
        $seleccionador = $this->seleccionador($ficha);
        $candidatos = [$this->candidato($convocatoria, '7.33', '8.91'), $this->candidato($convocatoria, '0.01', '0.00'), $this->candidato($convocatoria, '10', '9.99')];

        $filas = collect($this->ranking($seleccionador, $convocatoria)->json('data'))->keyBy('postulacion_id');

        foreach ($candidatos as $candidato) {
            $detalle = $this->actingAs($seleccionador)
                ->getJson("/api/seleccion/convocatorias/{$convocatoria->id}/candidatos/{$candidato->id}/calificaciones")
                ->assertOk()->json('data.resultado_adicional.valor');
            $this->assertSame($detalle, $filas[$candidato->id]['resultado_adicional']);
        }
    }

    public function test_notas_iguales_comparten_posicion_incluso_entre_paginas(): void
    {
        // 1 de 9, 4 de 8 y 20 de 7: el grupo de 7 queda partido entre la página 1 y la 2.
        [$seleccionador, $convocatoria] = $this->escenarioConNotas(array_merge(['9'], array_fill(0, 4, '8'), array_fill(0, 20, '7')));

        $pagina1 = $this->ranking($seleccionador, $convocatoria)->assertOk();
        $pagina2 = $this->ranking($seleccionador, $convocatoria, '?page=2')->assertOk();

        $this->assertCount(20, $pagina1->json('data'));
        $this->assertCount(5, $pagina2->json('data'));
        $this->assertSame(25, $pagina1->json('meta.total'));
        $this->assertSame(2, $pagina1->json('meta.last_page'));

        $todas = collect($pagina1->json('data'))->merge($pagina2->json('data'));
        $this->assertSame([1, 2, 2, 2, 2], $todas->take(5)->pluck('posicion')->all());
        $this->assertSame([6], $todas->skip(5)->pluck('posicion')->unique()->values()->all());
        $this->assertSame(6, $pagina1->json('data.19.posicion'));
        $this->assertSame(6, $pagina2->json('data.0.posicion'));
        // El orden entre empatados es estable: sin repetidos ni faltantes entre páginas.
        $this->assertCount(25, $todas->pluck('postulacion_id')->unique());
    }

    // ── Sugerencia ─────────────────────────────────────────────────────

    public function test_sugiere_el_doble_de_las_vacantes_sin_empate(): void
    {
        [$seleccionador, $convocatoria, $c] = $this->escenarioConNotas(['9', '8', '7', '6', '5'], 2);

        $respuesta = $this->ranking($seleccionador, $convocatoria)->assertOk()
            ->assertJsonPath('meta.objetivo', ['valor' => 4, 'configurado' => false, 'efectivo' => 4])
            ->assertJsonPath('meta.sugeridos', 4)
            ->assertJsonPath('meta.empate_en_corte', null);

        $this->assertSame(['sugerido', 'sugerido', 'sugerido', 'sugerido', 'no_sugerido'], collect($respuesta->json('data'))->pluck('sugerencia')->all());
    }

    public function test_un_empate_en_el_corte_deja_el_grupo_completo_pendiente(): void
    {
        // Objetivo 4: un 9 y cuatro 8. El grupo de 8 cruza el corte (posiciones 2 a 5).
        [$seleccionador, $convocatoria, $c] = $this->escenarioConNotas(['9', '8', '8', '8', '8'], 2);

        $respuesta = $this->ranking($seleccionador, $convocatoria)->assertOk();

        $this->assertSame(['sugerido', 'empate_en_corte', 'empate_en_corte', 'empate_en_corte', 'empate_en_corte'], collect($respuesta->json('data'))->pluck('sugerencia')->all());
        $respuesta->assertJsonPath('meta.sugeridos', 1)
            ->assertJsonPath('meta.empate_en_corte.posicion', 2)
            ->assertJsonPath('meta.empate_en_corte.resultado', '8.00');
        $this->assertEqualsCanonicalizing(
            [$c[1]->id, $c[2]->id, $c[3]->id, $c[4]->id],
            $respuesta->json('meta.empate_en_corte.postulaciones'),
        );
    }

    public function test_un_empate_dentro_del_corte_no_requiere_resolucion(): void
    {
        // Objetivo 4: el empate de los dos 8 queda dentro de las primeras cuatro posiciones (9, 8, 8, 7 | 6).
        [$seleccionador, $convocatoria] = $this->escenarioConNotas(['9', '8', '8', '7', '6'], 2);

        $this->ranking($seleccionador, $convocatoria)->assertOk()
            ->assertJsonPath('meta.empate_en_corte', null)
            ->assertJsonPath('meta.sugeridos', 4);
    }

    public function test_con_menos_candidatos_que_el_objetivo_se_sugieren_todos(): void
    {
        [$seleccionador, $convocatoria] = $this->escenarioConNotas(['9', '8', '7'], 5);

        $this->ranking($seleccionador, $convocatoria)->assertOk()
            ->assertJsonPath('meta.objetivo', ['valor' => 10, 'configurado' => false, 'efectivo' => 3])
            ->assertJsonPath('meta.sugeridos', 3);
    }

    public function test_el_objetivo_es_configurable_y_se_restablece(): void
    {
        [$seleccionador, $convocatoria] = $this->escenarioConNotas(['9', '8', '7', '6'], 2);
        $url = "/api/seleccion/convocatorias/{$convocatoria->id}/ranking/objetivo";

        $this->actingAs($seleccionador)->putJson($url, ['objetivo' => 2])->assertOk()
            ->assertJsonPath('data.objetivo', 2)->assertJsonPath('data.configurado', true)->assertJsonPath('data.efectivo', 2);
        $this->ranking($seleccionador, $convocatoria)->assertJsonPath('meta.sugeridos', 2)->assertJsonPath('meta.objetivo.configurado', true);

        $this->actingAs($seleccionador)->putJson($url, ['objetivo' => 0])->assertUnprocessable()->assertJsonValidationErrors('objetivo');
        $this->actingAs($seleccionador)->putJson($url, ['objetivo' => 501])->assertUnprocessable();
        $this->actingAs($seleccionador)->putJson($url, [])->assertUnprocessable();

        $this->actingAs($seleccionador)->putJson($url, ['objetivo' => null])->assertOk()
            ->assertJsonPath('data.objetivo', 4)->assertJsonPath('data.configurado', false);

        $convocatoria->forceFill(['estado_id' => $this->parametroId('estado_convocatoria', 'cerrada')])->save();
        $this->actingAs($seleccionador)->putJson($url, ['objetivo' => 3])->assertUnprocessable()->assertJsonValidationErrors('convocatoria');
    }

    // ── Sin actividades ────────────────────────────────────────────────

    public function test_sin_actividades_lista_a_los_habilitados_sin_nota_posicion_ni_sugerencia(): void
    {
        $ficha = $this->ficha();
        $convocatoria = $this->convocatoria($ficha);
        $this->configurar($convocatoria, false, false);
        $seleccionador = $this->seleccionador($ficha);
        $a = $this->candidato($convocatoria, null);
        $b = $this->candidato($convocatoria, null);
        $this->postulacion($convocatoria); // sin habilitar: queda fuera

        $respuesta = $this->ranking($seleccionador, $convocatoria)->assertOk()
            ->assertJsonPath('meta.modo', 'sin_actividades')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.sugeridos', 0)
            ->assertJsonPath('meta.empate_en_corte', null);

        foreach ($respuesta->json('data') as $fila) {
            $this->assertNull($fila['posicion']);
            $this->assertNull($fila['resultado_adicional']);
            $this->assertNull($fila['sugerencia']);
        }
        $this->assertSame([$a->id, $b->id], collect($respuesta->json('data'))->pluck('postulacion_id')->all());
    }

    public function test_sin_actividades_la_eleccion_manual_exige_justificacion(): void
    {
        $ficha = $this->ficha();
        $convocatoria = $this->convocatoria($ficha);
        $this->configurar($convocatoria, false, false);
        $seleccionador = $this->seleccionador($ficha);
        $a = $this->candidato($convocatoria, null);
        $this->candidato($convocatoria, null);
        $version = $this->ranking($seleccionador, $convocatoria)->json('meta.version');

        $this->confirmar($seleccionador, $convocatoria, $version, [$a])->assertUnprocessable()->assertJsonValidationErrors('justificacion');
        $this->confirmar($seleccionador, $convocatoria, $version, [$a], 'Elegido por el comité en la reunión del lunes')
            ->assertCreated()->assertJsonPath('data.modo', 'sin_actividades')->assertJsonPath('data.finalistas.0.posicion', null);
    }

    // ── Confirmación ───────────────────────────────────────────────────

    public function test_confirmar_la_sugerencia_no_exige_justificacion_y_guarda_quien_y_cuando(): void
    {
        [$seleccionador, $convocatoria, $c] = $this->escenarioConNotas(['9', '8', '7', '6', '5'], 2);
        $version = $this->ranking($seleccionador, $convocatoria)->json('meta.version');

        $this->confirmar($seleccionador, $convocatoria, $version, [$c[0], $c[1], $c[2], $c[3]])
            ->assertCreated()
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.confirmada_por', $seleccionador->name)
            ->assertJsonPath('data.motivo', null)
            ->assertJsonPath('data.requiere_revision', false)
            ->assertJsonPath('data.finalistas.0.posicion', 1)
            ->assertJsonPath('data.finalistas.0.resultado_adicional', '9.00');

        $this->ranking($seleccionador, $convocatoria)->assertJsonPath('meta.lista_confirmada.version', 1)
            ->assertJsonPath('meta.lista_confirmada.requiere_revision', false)
            ->assertJsonPath('data.0.finalista', true)->assertJsonPath('data.4.finalista', false);
    }

    public function test_modificar_la_sugerencia_o_resolver_un_empate_exige_justificacion(): void
    {
        [$seleccionador, $convocatoria, $c] = $this->escenarioConNotas(['9', '8', '7', '6', '5'], 2);
        $version = $this->ranking($seleccionador, $convocatoria)->json('meta.version');

        // Distinto de la sugerencia (4 sugeridos): sin justificación no.
        $this->confirmar($seleccionador, $convocatoria, $version, [$c[0], $c[1]])->assertUnprocessable()->assertJsonValidationErrors('justificacion');
        $this->confirmar($seleccionador, $convocatoria, $version, [$c[0], $c[1], 99999])->assertUnprocessable()->assertJsonValidationErrors('postulacion_ids');
        $this->confirmar($seleccionador, $convocatoria, $version, [$c[0], $c[1]], 'corta')->assertUnprocessable()->assertJsonValidationErrors('justificacion');
        $this->confirmar($seleccionador, $convocatoria, $version, [$c[0], $c[1]], 'Solo dos pasan a la siguiente fase')->assertCreated()
            ->assertJsonPath('data.motivo', 'Solo dos pasan a la siguiente fase');
        $this->confirmar($seleccionador, $convocatoria, $version, [])->assertUnprocessable()->assertJsonValidationErrors('postulacion_ids');

        // Empate en el corte: aunque se elija exactamente lo "sugerido", hay que justificar.
        [$s2, $conv2, $e] = $this->escenarioConNotas(['9', '8', '8', '8', '8'], 2, '2631620');
        $v2 = $this->ranking($s2, $conv2)->json('meta.version');
        $this->confirmar($s2, $conv2, $v2, [$e[0]])->assertUnprocessable()->assertJsonValidationErrors('justificacion');
        $this->confirmar($s2, $conv2, $v2, [$e[0], $e[1], $e[2], $e[3]], 'Desempate acordado por el comité')->assertCreated();
    }

    public function test_solo_candidatos_listos_y_con_la_convocatoria_en_proceso(): void
    {
        [$seleccionador, $convocatoria, $c] = $this->escenarioConNotas(['9', '8'], 1);
        $incompleto = $this->candidato($convocatoria, '9', null);
        $version = $this->ranking($seleccionador, $convocatoria)->json('meta.version');

        $this->confirmar($seleccionador, $convocatoria, $version, [$c[0], $incompleto], 'Incluyo a uno sin la prueba técnica')
            ->assertUnprocessable()->assertJsonValidationErrors('postulacion_ids');

        $convocatoria->forceFill(['estado_id' => $this->parametroId('estado_convocatoria', 'cerrada')])->save();
        $this->confirmar($seleccionador, $convocatoria, $version, [$c[0], $c[1]])->assertUnprocessable()->assertJsonValidationErrors('convocatoria');
        $this->assertSame(0, SeleccionFinalistaLista::count());
    }

    public function test_un_reenvio_identico_no_crea_otra_version_y_cambiar_el_conjunto_si(): void
    {
        [$seleccionador, $convocatoria, $c] = $this->escenarioConNotas(['9', '8', '7', '6'], 2);
        $version = $this->ranking($seleccionador, $convocatoria)->json('meta.version');
        $todos = [$c[0], $c[1], $c[2], $c[3]];

        $this->confirmar($seleccionador, $convocatoria, $version, $todos)->assertCreated();
        $this->confirmar($seleccionador, $convocatoria, $version, array_reverse($todos))->assertOk()->assertJsonPath('data.version', 1);
        $this->assertSame(1, SeleccionFinalistaLista::count());

        // Reemplazar: otra versión; la anterior se conserva.
        $this->confirmar($seleccionador, $convocatoria, $version, [$c[0], $c[1]], 'Reducimos la lista a dos finalistas')
            ->assertCreated()->assertJsonPath('data.version', 2)->assertJsonPath('data.motivo', 'Reducimos la lista a dos finalistas');

        $this->assertSame(2, SeleccionFinalistaLista::count());
        $historial = $this->actingAs($seleccionador)->getJson("/api/seleccion/convocatorias/{$convocatoria->id}/finalistas")->assertOk();
        $historial->assertJsonPath('data.actual.version', 2)->assertJsonPath('data.versiones.0.version', 2)
            ->assertJsonPath('data.versiones.1.version', 1)->assertJsonPath('data.versiones.1.total', 4);
        $this->assertSame(4, SeleccionFinalistaLista::where('version', 1)->first()->miembros()->count());
    }

    public function test_una_lista_con_una_postulacion_de_otra_convocatoria_se_rechaza_completa(): void
    {
        [$seleccionador, $convocatoria, $c] = $this->escenarioConNotas(['9', '8', '7', '6'], 2);
        $otra = $this->convocatoria(Ficha::first(), 'en_proceso', 2);
        $this->configurar($otra);
        $ajena = $this->candidato($otra, '9', '9');
        $version = $this->ranking($seleccionador, $convocatoria)->json('meta.version');

        // Válida + ajena (de otra convocatoria) + inexistente: ninguna queda guardada.
        foreach ([[$c[0], $ajena], [$c[0], $c[1], $c[2], $c[3], $ajena], [$c[0], 999999]] as $mezcla) {
            $respuesta = $this->confirmar($seleccionador, $convocatoria, $version, $mezcla, 'Mezcla con una postulación que no corresponde')
                ->assertUnprocessable()->assertJsonValidationErrors('postulacion_ids');
            // No revela datos de la postulación ajena.
            $this->assertStringNotContainsString((string) $ajena->user->name, $respuesta->getContent());
        }

        $this->assertSame(0, SeleccionFinalistaLista::count());
        $this->assertSame(0, DB::table('seleccion_finalistas_miembros')->count());
    }

    public function test_el_historial_de_versiones_se_pagina_y_la_lista_vigente_no_depende_de_la_pagina(): void
    {
        [$seleccionador, $convocatoria] = $this->escenarioConNotas(['9', '8'], 1);
        foreach (range(1, 25) as $version) {
            SeleccionFinalistaLista::create([
                'convocatoria_id' => $convocatoria->id,
                'version' => $version,
                'modo' => 'con_actividades',
                'objetivo' => 2,
            ]);
        }
        $url = "/api/seleccion/convocatorias/{$convocatoria->id}/finalistas";

        $pagina1 = $this->actingAs($seleccionador)->getJson($url)->assertOk();
        $pagina2 = $this->actingAs($seleccionador)->getJson("{$url}?page=2")->assertOk();

        $this->assertSame(range(25, 6), collect($pagina1->json('data.versiones'))->pluck('version')->all());
        $this->assertSame(range(5, 1), collect($pagina2->json('data.versiones'))->pluck('version')->all());
        $this->assertSame(['current_page' => 1, 'last_page' => 2, 'per_page' => 20, 'total' => 25], $pagina1->json('meta'));
        $this->assertSame(2, $pagina2->json('meta.current_page'));

        // `actual` es siempre la última confirmada (25), pida la página que se pida.
        $this->assertSame(25, $pagina1->json('data.actual.version'));
        $this->assertSame(25, $pagina2->json('data.actual.version'));
    }

    // ── Vigencia ───────────────────────────────────────────────────────

    public function test_una_nota_corregida_entre_la_consulta_y_la_confirmacion_da_conflicto(): void
    {
        [$seleccionador, $convocatoria, $c] = $this->escenarioConNotas(['9', '8', '7', '6'], 2);
        $version = $this->ranking($seleccionador, $convocatoria)->json('meta.version');

        // Otra persona corrige una nota mientras tanto (por la API real de calificaciones).
        $this->actingAs($seleccionador)->putJson("/api/seleccion/convocatorias/{$convocatoria->id}/candidatos/{$c[3]->id}/calificaciones/entrevista", [
            'nota' => 9.5, 'fecha_realizacion' => '2026-11-10', 'motivo' => 'Se recalificó tras la revisión',
        ])->assertOk();

        $this->confirmar($seleccionador, $convocatoria, $version, [$c[0], $c[1], $c[2], $c[3]])
            ->assertStatus(409)->assertJsonPath('codigo', 'ranking_desactualizado');
        $this->assertSame(0, SeleccionFinalistaLista::count());

        // Con la versión nueva sí se puede confirmar.
        $nueva = $this->ranking($seleccionador, $convocatoria)->json('meta.version');
        $this->assertNotSame($version, $nueva);
        $this->confirmar($seleccionador, $convocatoria, $nueva, [$c[0], $c[1], $c[2], $c[3]])->assertCreated();
    }

    public function test_una_referencia_reabierta_entre_la_consulta_y_la_confirmacion_da_conflicto(): void
    {
        $ficha = $this->ficha();
        $convocatoria = $this->convocatoria($ficha, 'en_proceso', 1);
        $this->configurar($convocatoria, true, false);
        $seleccionador = $this->seleccionador($ficha);
        $instructor = $this->usuario(Role::Instructor, null, ['ficha_id' => $ficha->id]);

        $postulacion = $this->postulacion($convocatoria);
        $elegida = $this->evaluacion($postulacion, 'finalizada', 90, true);
        $this->evaluacion($postulacion, 'finalizada', 20, false, true);
        $this->actingAs($instructor)->postJson("/api/seleccion/convocatorias/{$convocatoria->id}/candidatos/{$postulacion->id}/referencia-documental", [
            'evaluacion_id' => $elegida->id,
            'justificacion' => 'La primera evaluación tiene el soporte completo.',
        ])->assertCreated();
        $this->nota($postulacion, 'entrevista', '8');

        $version = $this->ranking($seleccionador, $convocatoria)->assertJsonPath('meta.total', 1)->json('meta.version');

        // La evaluación de referencia se reabre antes de confirmar.
        $elegida->update(['estado' => 'en_proceso', 'pasa_seleccion' => null]);

        $this->confirmar($seleccionador, $convocatoria, $version, [$postulacion], 'Confirmo con la información que tenía')
            ->assertStatus(409)->assertJsonPath('codigo', 'ranking_desactualizado');
        $this->assertSame(0, SeleccionFinalistaLista::count());
    }

    public function test_si_cambia_algo_despues_la_lista_se_conserva_y_se_marca_para_revision(): void
    {
        [$seleccionador, $convocatoria, $c] = $this->escenarioConNotas(['9', '8', '7', '6'], 2);
        $version = $this->ranking($seleccionador, $convocatoria)->json('meta.version');
        $this->confirmar($seleccionador, $convocatoria, $version, [$c[0], $c[1], $c[2], $c[3]])->assertCreated();
        $urlFinalistas = "/api/seleccion/convocatorias/{$convocatoria->id}/finalistas";

        // Una nota cambia y otro finalista pierde la habilitación.
        $this->actingAs($seleccionador)->putJson("/api/seleccion/convocatorias/{$convocatoria->id}/candidatos/{$c[1]->id}/calificaciones/entrevista", [
            'nota' => 2, 'fecha_realizacion' => '2026-11-10', 'motivo' => 'Se anula por una irregularidad',
        ])->assertOk();
        Evaluacion::where('postulacion_id', $c[3]->id)->update(['estado' => 'en_proceso', 'pasa_seleccion' => null]);

        $actual = $this->actingAs($seleccionador)->getJson($urlFinalistas)->assertOk()->json('data.actual');
        $this->assertTrue($actual['requiere_revision']);
        $this->assertEqualsCanonicalizing(
            [['postulacion_id' => $c[1]->id, 'motivo' => 'cambio_resultado'], ['postulacion_id' => $c[3]->id, 'motivo' => 'ya_no_elegible']],
            $actual['revisar'],
        );
        // La lista registrada no se tocó: sigue con sus cuatro finalistas y sus resultados originales.
        $this->assertCount(4, $actual['finalistas']);
        $this->assertSame('8.00', collect($actual['finalistas'])->firstWhere('postulacion_id', $c[1]->id)['resultado_adicional']);
        $this->ranking($seleccionador, $convocatoria)->assertJsonPath('meta.lista_confirmada.requiere_revision', true);

        // Reconfirmar el mismo conjunto con el cambio ya revisado crea una versión nueva.
        $nueva = $this->ranking($seleccionador, $convocatoria)->json('meta.version');
        $this->confirmar($seleccionador, $convocatoria, $nueva, [$c[0], $c[1], $c[2]], 'Se retira a quien perdió la habilitación')
            ->assertCreated()->assertJsonPath('data.version', 2)->assertJsonPath('data.requiere_revision', false);
        $this->assertSame(2, SeleccionFinalistaLista::count());
    }

    // ── Rendimiento ────────────────────────────────────────────────────

    public function test_las_consultas_del_ranking_no_crecen_con_los_candidatos(): void
    {
        $ficha = $this->ficha();
        $convocatoria = $this->convocatoria($ficha);
        $this->configurar($convocatoria);
        $seleccionador = $this->seleccionador($ficha);

        $consultas = function () use ($seleccionador, $convocatoria): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->ranking($seleccionador, $convocatoria)->assertOk();
            $total = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $total;
        };

        foreach (range(1, 3) as $i) {
            $this->candidato($convocatoria, (string) $i, (string) $i);
        }
        $conTres = $consultas();
        foreach (range(4, 25) as $i) {
            $this->candidato($convocatoria, (string) ($i % 10), '5');
        }
        $conVeinticinco = $consultas();

        $this->assertSame($conTres, $conVeinticinco);
    }
}
