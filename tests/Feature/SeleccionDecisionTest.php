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
use App\Models\SeleccionDecision;
use App\Models\SeleccionDecisionEvento;
use App\Models\User;
use Database\Seeders\ParametroSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SeleccionDecisionTest extends TestCase
{
    use RefreshDatabase;

    private static int $codigoSecuencia = 0;

    private ?User $evaluadorCompartido = null;

    private const MOTIVO = 'Decisión tomada por el comité de selección.';

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

    private function convocatoria(Ficha $ficha, int $vacantes = 2, string $estado = 'en_proceso'): Convocatoria
    {
        return Convocatoria::unguarded(fn () => Convocatoria::create([
            'ficha_id' => $ficha->id,
            'codigo' => sprintf('CONV-DEC-%03d', ++self::$codigoSecuencia),
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

    private function postulacion(Convocatoria $convocatoria): Postulacion
    {
        return Postulacion::create([
            'convocatoria_id' => $convocatoria->id,
            'user_id' => $this->usuario(Role::Aspirante, null, ['ficha_id' => $convocatoria->ficha_id])->id,
            'estado_id' => $this->parametroId('estado_postulacion', 'postulada'),
            'fecha_postulacion' => now(),
        ]);
    }

    /** Candidato habilitado con ambas actividades calificadas. */
    private function candidato(Convocatoria $convocatoria, string $nota = '8'): Postulacion
    {
        $this->evaluadorCompartido ??= $this->usuario(Role::Aprendiz, 'evaluador', ['ficha_id' => $convocatoria->ficha_id]);
        $postulacion = $this->postulacion($convocatoria);
        Evaluacion::create([
            'postulacion_id' => $postulacion->id,
            'evaluador_id' => $this->evaluadorCompartido->id,
            'estado' => 'finalizada',
            'cumplimiento' => 85,
            'pasa_seleccion' => true,
        ]);
        foreach (['entrevista', 'prueba_tecnica'] as $actividad) {
            SeleccionCalificacion::create([
                'convocatoria_id' => $convocatoria->id,
                'postulacion_id' => $postulacion->id,
                'actividad' => $actividad,
                'nota' => $nota,
                'fecha_realizacion' => '2026-11-10',
            ]);
        }

        return $postulacion;
    }

    /** Convocatoria en proceso con actividades 50/50, N candidatos y la lista de finalistas confirmada. */
    private function escenario(int $vacantes, int $candidatos): array
    {
        $ficha = $this->ficha();
        $convocatoria = $this->convocatoria($ficha, $vacantes);
        SeleccionConfiguracion::create(['convocatoria_id' => $convocatoria->id, 'entrevista_activa' => true, 'prueba_tecnica_activa' => true, 'peso_entrevista' => 50, 'peso_prueba_tecnica' => 50]);
        $seleccionador = $this->seleccionador($ficha);
        $personas = array_map(fn (int $i) => $this->candidato($convocatoria, (string) (10 - $i)), range(0, $candidatos - 1));
        $version = $this->confirmarLista($seleccionador, $convocatoria, $personas);

        return [$seleccionador, $convocatoria, $personas, $version];
    }

    /** Confirma la lista de finalistas por la API y devuelve su versión. */
    private function confirmarLista(User $actor, Convocatoria $convocatoria, array $personas): int
    {
        $this->actingAs($actor)->putJson("/api/seleccion/convocatorias/{$convocatoria->id}/ranking/objetivo", ['objetivo' => 100])->assertOk();
        $version = $this->actingAs($actor)->getJson("/api/seleccion/convocatorias/{$convocatoria->id}/ranking")->json('meta.version');

        return $this->actingAs($actor)->postJson("/api/seleccion/convocatorias/{$convocatoria->id}/finalistas", [
            'version' => $version,
            'postulacion_ids' => array_map(fn (Postulacion $p) => $p->id, $personas),
            'justificacion' => 'Lista confirmada para las pruebas de decisión',
        ])->assertSuccessful()->json('data.version');
    }

    private function decidir(User $actor, Convocatoria $convocatoria, Postulacion $postulacion, string $resultado, ?int $version, ?string $justificacion = self::MOTIVO)
    {
        return $this->actingAs($actor)->putJson("/api/seleccion/convocatorias/{$convocatoria->id}/decisiones/{$postulacion->id}", array_filter([
            'resultado' => $resultado,
            'lista_version' => $version,
            'justificacion' => $justificacion,
        ], fn ($v) => $v !== null));
    }

    private function decisiones(User $actor, Convocatoria $convocatoria, string $consulta = '')
    {
        return $this->actingAs($actor)->getJson("/api/seleccion/convocatorias/{$convocatoria->id}/decisiones{$consulta}");
    }

    // ── Acceso ─────────────────────────────────────────────────────────

    public function test_invitado_recibe_401_en_las_tres_rutas(): void
    {
        [, $convocatoria, [$p]] = $this->escenario(1, 1);
        $base = "/api/seleccion/convocatorias/{$convocatoria->id}/decisiones";
        $this->app['auth']->forgetGuards(); // el escenario autenticó a un usuario: aquí se actúa como invitado

        $this->getJson($base)->assertUnauthorized();
        $this->putJson("{$base}/{$p->id}", [])->assertUnauthorized();
        $this->getJson("{$base}/{$p->id}/historial")->assertUnauthorized();
    }

    public function test_sin_permisos_recibe_403_y_no_guarda(): void
    {
        [, $convocatoria, [$p], $version] = $this->escenario(1, 1);
        $ficha = Ficha::first();
        $base = "/api/seleccion/convocatorias/{$convocatoria->id}/decisiones";

        foreach ([$this->usuario(Role::Aprendiz, 'evaluador', ['ficha_id' => $ficha->id]), $this->usuario(Role::Aspirante, null, ['ficha_id' => $ficha->id])] as $actor) {
            $this->actingAs($actor)->getJson($base)->assertForbidden();
            $this->decidir($actor, $convocatoria, $p, 'seleccionado', $version)->assertForbidden();
            $this->actingAs($actor)->getJson("{$base}/{$p->id}/historial")->assertForbidden();
            // La autorización se resuelve antes que la existencia.
            $this->actingAs($actor)->putJson('/api/seleccion/convocatorias/999999/decisiones/999999', [])->assertForbidden();
        }
        $this->assertSame(0, SeleccionDecision::count());
    }

    public function test_ficha_ajena_postulacion_de_otra_convocatoria_e_inexistentes_responden_404(): void
    {
        [$seleccionador, $convocatoria, [$p], $version] = $this->escenario(1, 1);
        $otraFicha = $this->ficha('2631620');
        $ajena = $this->convocatoria($otraFicha);
        $pAjena = $this->candidato($ajena);
        $otraMismaFicha = $this->convocatoria(Ficha::first());
        $pOtra = $this->candidato($otraMismaFicha);
        $base = "/api/seleccion/convocatorias/{$ajena->id}/decisiones";

        $this->decisiones($seleccionador, $ajena)->assertNotFound();
        $this->decidir($seleccionador, $ajena, $pAjena, 'seleccionado', 1)->assertNotFound();
        $this->actingAs($seleccionador)->getJson("{$base}/{$pAjena->id}/historial")->assertNotFound();
        // Postulación de otra convocatoria de la misma ficha, e inexistentes.
        $this->decidir($seleccionador, $convocatoria, $pOtra, 'seleccionado', $version)->assertNotFound();
        $this->actingAs($seleccionador)->getJson("/api/seleccion/convocatorias/{$convocatoria->id}/decisiones/{$pOtra->id}/historial")->assertNotFound();
        $this->actingAs($seleccionador)->putJson("/api/seleccion/convocatorias/{$convocatoria->id}/decisiones/999999", [])->assertNotFound();
        $this->actingAs($seleccionador)->getJson('/api/seleccion/convocatorias/999999/decisiones')->assertNotFound();

        $this->assertSame(0, SeleccionDecision::count());
    }

    // ── Condiciones para decidir ───────────────────────────────────────

    public function test_un_finalista_sin_decision_aparece_como_pendiente_y_con_los_cupos(): void
    {
        [$seleccionador, $convocatoria, [$a, $b], $version] = $this->escenario(2, 2);

        $respuesta = $this->decisiones($seleccionador, $convocatoria)->assertOk();

        $respuesta->assertJsonPath('meta.cupos', ['vacantes_totales' => 2, 'cupos_ocupados' => 0, 'cupos_disponibles' => 2])
            ->assertJsonPath('meta.pendientes', 2)
            ->assertJsonPath('meta.lista.version', $version)
            ->assertJsonPath('meta.lista.requiere_revision', false)
            ->assertJsonPath('data.0.postulacion_id', $a->id)
            ->assertJsonPath('data.0.estado', 'pendiente')
            ->assertJsonPath('data.0.decision', null)
            ->assertJsonPath('data.0.en_lista', true);
    }

    public function test_exige_justificacion_resultado_valido_y_version_de_la_lista(): void
    {
        [$seleccionador, $convocatoria, [$a], $version] = $this->escenario(1, 1);

        $this->decidir($seleccionador, $convocatoria, $a, 'seleccionado', $version, null)->assertUnprocessable()->assertJsonValidationErrors('justificacion');
        $this->decidir($seleccionador, $convocatoria, $a, 'seleccionado', $version, 'corta')->assertUnprocessable()->assertJsonValidationErrors('justificacion');
        $this->decidir($seleccionador, $convocatoria, $a, 'talvez', $version)->assertUnprocessable()->assertJsonValidationErrors('resultado');
        $this->decidir($seleccionador, $convocatoria, $a, 'seleccionado', null)->assertUnprocessable()->assertJsonValidationErrors('lista_version');
        $this->decidir($seleccionador, $convocatoria, $a, 'pendiente', $version)->assertUnprocessable();

        $this->assertSame(0, SeleccionDecision::count());
    }

    public function test_solo_con_la_convocatoria_en_proceso(): void
    {
        [$seleccionador, $convocatoria, [$a], $version] = $this->escenario(1, 1);
        $convocatoria->forceFill(['estado_id' => $this->parametroId('estado_convocatoria', 'cerrada')])->save();

        $this->decidir($seleccionador, $convocatoria, $a, 'seleccionado', $version)->assertUnprocessable()->assertJsonValidationErrors('convocatoria');
        $this->assertSame(0, SeleccionDecision::count());
        // Consultar sigue siendo posible.
        $this->decisiones($seleccionador, $convocatoria)->assertOk()->assertJsonPath('meta.estado_convocatoria', 'cerrada');
    }

    public function test_sin_lista_confirmada_da_conflicto(): void
    {
        $ficha = $this->ficha();
        $convocatoria = $this->convocatoria($ficha, 1);
        SeleccionConfiguracion::create(['convocatoria_id' => $convocatoria->id, 'entrevista_activa' => true, 'prueba_tecnica_activa' => true, 'peso_entrevista' => 50, 'peso_prueba_tecnica' => 50]);
        $seleccionador = $this->seleccionador($ficha);
        $a = $this->candidato($convocatoria);

        $this->decidir($seleccionador, $convocatoria, $a, 'seleccionado', 1)->assertStatus(409)->assertJsonPath('codigo', 'lista_no_confirmada');
        $this->decisiones($seleccionador, $convocatoria)->assertOk()->assertJsonPath('meta.lista', null)->assertJsonPath('data', []);
        $this->assertSame(0, SeleccionDecision::count());
    }

    public function test_una_lista_reemplazada_da_conflicto_con_la_version_actual(): void
    {
        [$seleccionador, $convocatoria, [$a, $b], $version] = $this->escenario(2, 2);
        $nueva = $this->confirmarLista($seleccionador, $convocatoria, [$a]);
        $this->assertSame($version + 1, $nueva);

        $this->decidir($seleccionador, $convocatoria, $a, 'seleccionado', $version)
            ->assertStatus(409)->assertJsonPath('codigo', 'lista_reemplazada')->assertJsonPath('lista_version_actual', $nueva);
        $this->decidir($seleccionador, $convocatoria, $a, 'seleccionado', $nueva)->assertCreated();
    }

    public function test_un_candidato_fuera_de_la_lista_no_puede_decidirse(): void
    {
        [$seleccionador, $convocatoria, [$a], $version] = $this->escenario(2, 1);
        // Listo (habilitado y con todas sus notas) pero que no entró a la lista de finalistas confirmada.
        $sinLista = $this->candidato($convocatoria, '7');

        $this->decidir($seleccionador, $convocatoria, $sinLista, 'seleccionado', $version)->assertUnprocessable()->assertJsonValidationErrors('postulacion');
        $this->assertSame(0, SeleccionDecision::count());
        // Los finalistas de la lista sí pueden decidirse.
        $this->decidir($seleccionador, $convocatoria, $a, 'seleccionado', $version)->assertCreated();
    }

    public function test_la_perdida_de_habilitacion_de_un_finalista_exige_revisar_antes_de_decidir(): void
    {
        [$seleccionador, $convocatoria, [$a, $b], $version] = $this->escenario(2, 2);
        Evaluacion::where('postulacion_id', $b->id)->update(['estado' => 'en_proceso', 'pasa_seleccion' => null]);

        $this->decidir($seleccionador, $convocatoria, $a, 'seleccionado', $version)
            ->assertStatus(409)->assertJsonPath('codigo', 'lista_requiere_revision')
            ->assertJsonPath('revisar.0.postulacion_id', $b->id)->assertJsonPath('revisar.0.motivo', 'ya_no_elegible');
        $this->decisiones($seleccionador, $convocatoria)->assertJsonPath('meta.lista.requiere_revision', true);
        $this->assertSame(0, SeleccionDecision::count());
    }

    // ── Cupos ──────────────────────────────────────────────────────────

    public function test_se_selecciona_hasta_el_tope_y_se_rechaza_el_excedente(): void
    {
        [$seleccionador, $convocatoria, [$a, $b, $c], $version] = $this->escenario(2, 3);

        $this->decidir($seleccionador, $convocatoria, $a, 'seleccionado', $version)->assertCreated()
            ->assertJsonPath('data.cupos', ['vacantes_totales' => 2, 'cupos_ocupados' => 1, 'cupos_disponibles' => 1])
            ->assertJsonPath('data.estado', 'seleccionado')
            ->assertJsonPath('data.decision.decidida_por', $seleccionador->name)
            ->assertJsonPath('data.decision.lista_version', $version);
        $this->decidir($seleccionador, $convocatoria, $b, 'seleccionado', $version)->assertCreated()
            ->assertJsonPath('data.cupos.cupos_disponibles', 0);

        // El tercer cupo no existe: conflicto con el saldo actualizado.
        $this->decidir($seleccionador, $convocatoria, $c, 'seleccionado', $version)
            ->assertStatus(409)->assertJsonPath('codigo', 'sin_cupos')
            ->assertJsonPath('cupos', ['vacantes_totales' => 2, 'cupos_ocupados' => 2, 'cupos_disponibles' => 0]);
        // No seleccionar no consume cupo y sí se puede aunque no queden.
        $this->decidir($seleccionador, $convocatoria, $c, 'no_seleccionado', $version)->assertCreated()
            ->assertJsonPath('data.cupos.cupos_ocupados', 2);

        $this->assertSame(2, (int) Convocatoria::find($convocatoria->id)->vacantes); // el total original no cambia
        $this->assertSame(3, SeleccionDecision::count());
    }

    public function test_rectificar_a_no_seleccionado_libera_el_cupo(): void
    {
        [$seleccionador, $convocatoria, [$a, $b], $version] = $this->escenario(1, 2);
        $this->decidir($seleccionador, $convocatoria, $a, 'seleccionado', $version)->assertCreated();
        $this->decidir($seleccionador, $convocatoria, $b, 'seleccionado', $version)->assertStatus(409)->assertJsonPath('codigo', 'sin_cupos');

        $this->decidir($seleccionador, $convocatoria, $a, 'no_seleccionado', $version, 'Rectifico: no cumplió el perfil final')->assertOk()
            ->assertJsonPath('data.estado', 'no_seleccionado')->assertJsonPath('data.cupos.cupos_disponibles', 1);
        $this->decidir($seleccionador, $convocatoria, $b, 'seleccionado', $version)->assertCreated()
            ->assertJsonPath('data.cupos', ['vacantes_totales' => 1, 'cupos_ocupados' => 1, 'cupos_disponibles' => 0]);

        // Volver a seleccionar a quien se liberó consume cupo otra vez, y ya no queda.
        $this->decidir($seleccionador, $convocatoria, $a, 'seleccionado', $version, 'Reconsiderado por el comité')->assertStatus(409)->assertJsonPath('codigo', 'sin_cupos');
    }

    // ── Historial y reenvío ────────────────────────────────────────────

    public function test_un_reenvio_identico_devuelve_lo_existente_sin_duplicar_eventos(): void
    {
        [$seleccionador, $convocatoria, [$a], $version] = $this->escenario(1, 1);
        $this->decidir($seleccionador, $convocatoria, $a, 'seleccionado', $version)->assertCreated();

        $this->decidir($seleccionador, $convocatoria, $a, 'seleccionado', $version)->assertOk()
            ->assertJsonPath('data.estado', 'seleccionado')->assertJsonPath('data.cupos.cupos_ocupados', 1);
        // Idéntico aunque la versión enviada ya no coincida.
        $this->decidir($seleccionador, $convocatoria, $a, 'seleccionado', $version + 5)->assertOk();

        $this->assertSame(1, SeleccionDecision::count());
        $this->assertSame(1, SeleccionDecisionEvento::count());
    }

    public function test_el_historial_conserva_anterior_nuevo_actor_motivo_y_version(): void
    {
        [$seleccionador, $convocatoria, [$a], $version] = $this->escenario(1, 1);
        $otro = $this->usuario(Role::Instructor, null, ['ficha_id' => $convocatoria->ficha_id]);

        $this->decidir($seleccionador, $convocatoria, $a, 'seleccionado', $version, 'Primera decisión del comité')->assertCreated();
        $this->decidir($otro, $convocatoria, $a, 'no_seleccionado', $version, 'Se rectifica por incumplir un requisito')->assertOk();
        // Mismo resultado con otro motivo: queda como evento, sin tocar el cupo.
        $this->decidir($otro, $convocatoria, $a, 'no_seleccionado', $version, 'Se amplía el motivo de la rectificación')->assertOk();

        $this->assertSame(1, SeleccionDecision::count());
        $historial = $this->actingAs($seleccionador)->getJson("/api/seleccion/convocatorias/{$convocatoria->id}/decisiones/{$a->id}/historial")->assertOk();
        $historial->assertJsonPath('meta.total', 3)
            ->assertJsonPath('data.0.resultado_nuevo', 'no_seleccionado')
            ->assertJsonPath('data.0.resultado_anterior', 'no_seleccionado')
            ->assertJsonPath('data.1.resultado_anterior', 'seleccionado')
            ->assertJsonPath('data.1.resultado_nuevo', 'no_seleccionado')
            ->assertJsonPath('data.1.registrado_por', $otro->name)
            ->assertJsonPath('data.1.motivo', 'Se rectifica por incumplir un requisito')
            ->assertJsonPath('data.1.lista_version', $version)
            ->assertJsonPath('data.2.resultado_anterior', null)
            ->assertJsonPath('data.2.resultado_nuevo', 'seleccionado');
        $this->assertSame(['resultado_anterior', 'resultado_nuevo', 'motivo', 'lista_version', 'registrado_por', 'registrado_en'], array_keys($historial->json('data.0')));
    }

    public function test_el_historial_se_pagina(): void
    {
        [$seleccionador, $convocatoria, [$a], $version] = $this->escenario(1, 1);
        $this->decidir($seleccionador, $convocatoria, $a, 'seleccionado', $version)->assertCreated();
        $decision = SeleccionDecision::first();
        foreach (range(1, 24) as $i) {
            SeleccionDecisionEvento::create(['decision_id' => $decision->id, 'resultado_anterior' => 'seleccionado', 'resultado_nuevo' => 'seleccionado', 'motivo' => "Evento {$i}", 'lista_version' => $version]);
        }
        $url = "/api/seleccion/convocatorias/{$convocatoria->id}/decisiones/{$a->id}/historial";

        $p1 = $this->actingAs($seleccionador)->getJson($url)->assertOk();
        $p2 = $this->actingAs($seleccionador)->getJson("{$url}?page=2")->assertOk();

        $this->assertCount(20, $p1->json('data'));
        $this->assertCount(5, $p2->json('data'));
        $this->assertSame(['current_page' => 1, 'last_page' => 2, 'per_page' => 20, 'total' => 25], $p1->json('meta'));
    }

    // ── Decisiones que pierden vigencia ────────────────────────────────

    public function test_una_decision_que_pierde_vigencia_sigue_ocupando_cupo_y_se_puede_liberar(): void
    {
        [$seleccionador, $convocatoria, [$a, $b], $version] = $this->escenario(1, 2);
        $this->decidir($seleccionador, $convocatoria, $a, 'seleccionado', $version)->assertCreated();

        // El finalista seleccionado pierde la habilitación documental.
        Evaluacion::where('postulacion_id', $a->id)->update(['estado' => 'en_proceso', 'pasa_seleccion' => null]);

        $respuesta = $this->decisiones($seleccionador, $convocatoria)->assertOk();
        $respuesta->assertJsonPath('meta.cupos.cupos_ocupados', 1)->assertJsonPath('meta.lista.requiere_revision', true);
        $fila = collect($respuesta->json('data'))->firstWhere('postulacion_id', $a->id);
        $this->assertSame('seleccionado', $fila['estado']);
        $this->assertTrue($fila['requiere_revision']);
        $this->assertSame('ya_no_elegible', $fila['motivo_revision']);

        // No se pueden tomar decisiones nuevas mientras la lista requiera revisión, pero liberar sí.
        $this->decidir($seleccionador, $convocatoria, $b, 'seleccionado', $version)->assertStatus(409)->assertJsonPath('codigo', 'lista_requiere_revision');
        $this->decidir($seleccionador, $convocatoria, $a, 'no_seleccionado', $version, 'Pierde la habilitación documental; se libera el cupo')
            ->assertOk()->assertJsonPath('data.cupos', ['vacantes_totales' => 1, 'cupos_ocupados' => 0, 'cupos_disponibles' => 1]);
        $this->assertSame(2, SeleccionDecisionEvento::count());
    }

    public function test_se_puede_liberar_aunque_el_candidato_ya_no_este_en_la_lista_vigente(): void
    {
        [$seleccionador, $convocatoria, [$a, $b], $version] = $this->escenario(1, 2);
        $this->decidir($seleccionador, $convocatoria, $a, 'seleccionado', $version)->assertCreated();

        // Se confirma una lista nueva sin el seleccionado.
        $nueva = $this->confirmarLista($seleccionador, $convocatoria, [$b]);

        $listado = $this->decisiones($seleccionador, $convocatoria)->assertOk();
        $fila = collect($listado->json('data'))->firstWhere('postulacion_id', $a->id);
        $this->assertFalse($fila['en_lista']);
        $this->assertSame('fuera_de_lista', $fila['motivo_revision']);
        $this->assertSame('seleccionado', $fila['estado']);
        $listado->assertJsonPath('meta.cupos.cupos_ocupados', 1);

        // Sin enviar la versión (ni siquiera la vieja) se puede liberar; nunca volver a seleccionarlo.
        $this->decidir($seleccionador, $convocatoria, $a, 'no_seleccionado', null, 'Ya no figura entre los finalistas; se libera el cupo')
            ->assertOk()->assertJsonPath('data.cupos.cupos_disponibles', 1);
        $this->decidir($seleccionador, $convocatoria, $a, 'seleccionado', $nueva)->assertUnprocessable()->assertJsonValidationErrors('postulacion');
        // La liberación quedó trazada con la versión de lista vigente en ese momento.
        $this->assertSame($nueva, SeleccionDecisionEvento::orderByDesc('id')->first()->lista_version);
    }

    public function test_la_liberacion_registra_la_version_del_servidor_y_conserva_la_seleccion_original(): void
    {
        [$seleccionador, $convocatoria, [$a, $b], $v1] = $this->escenario(1, 2);
        $this->decidir($seleccionador, $convocatoria, $a, 'seleccionado', $v1)->assertCreated();

        // La versión 2 ya no incluye al seleccionado.
        $v2 = $this->confirmarLista($seleccionador, $convocatoria, [$b]);
        $this->assertSame($v1 + 1, $v2);

        // Se rectifica enviando la versión antigua (1): el servidor usa la vigente (2).
        $this->decidir($seleccionador, $convocatoria, $a, 'no_seleccionado', $v1, 'Ya no figura entre los finalistas; se libera')
            ->assertOk()->assertJsonPath('data.cupos.cupos_disponibles', 1)->assertJsonPath('data.decision.lista_version', $v2);

        $eventos = SeleccionDecisionEvento::orderBy('id')->get();
        $this->assertCount(2, $eventos);
        $this->assertSame([null, 'seleccionado'], [$eventos[0]->resultado_anterior, $eventos[0]->resultado_nuevo]);
        $this->assertSame($v1, $eventos[0]->lista_version);          // la selección original sigue sustentada por la versión 1
        $this->assertSame(['seleccionado', 'no_seleccionado'], [$eventos[1]->resultado_anterior, $eventos[1]->resultado_nuevo]);
        $this->assertSame($v2, $eventos[1]->lista_version);          // la rectificación, por la vigente (2)

        // Con una versión inexistente ocurre lo mismo: se registra la vigente del servidor.
        [$s2, $conv2, [$c], $w1] = $this->escenarioEnOtraFicha();
        $this->decidir($s2, $conv2, $c, 'seleccionado', $w1)->assertCreated();
        $this->decidir($s2, $conv2, $c, 'no_seleccionado', 999, 'Rectifico con una versión inexistente')->assertOk();
        $this->assertSame($w1, SeleccionDecisionEvento::where('decision_id', SeleccionDecision::where('postulacion_id', $c->id)->value('id'))->orderByDesc('id')->first()->lista_version);
    }

    public function test_editar_el_motivo_de_un_no_seleccionado_sigue_las_validaciones_normales(): void
    {
        [$seleccionador, $convocatoria, [$a, $b], $v1] = $this->escenario(1, 2);
        $this->decidir($seleccionador, $convocatoria, $a, 'no_seleccionado', $v1, 'No cumplió el perfil requerido')->assertCreated();

        // Sin la versión de la lista no es una liberación: se exige, igual que cualquier decisión.
        $this->decidir($seleccionador, $convocatoria, $a, 'no_seleccionado', null, 'Motivo ampliado de la decisión')
            ->assertUnprocessable()->assertJsonValidationErrors('lista_version');
        // Con una versión que ya no es la vigente: conflicto.
        $v2 = $this->confirmarLista($seleccionador, $convocatoria, [$a]);
        $this->assertSame($v1 + 1, $v2);
        $this->decidir($seleccionador, $convocatoria, $a, 'no_seleccionado', $v1, 'Motivo ampliado de la decisión')
            ->assertStatus(409)->assertJsonPath('codigo', 'lista_reemplazada');
        // Con la versión vigente y el candidato en la lista: se edita y queda en el historial.
        $this->decidir($seleccionador, $convocatoria, $a, 'no_seleccionado', $v2, 'Motivo ampliado de la decisión')->assertOk();
        $this->assertSame(2, SeleccionDecisionEvento::count());

        // Si la lista requiere revisión, tampoco se puede editar el motivo.
        Evaluacion::where('postulacion_id', $a->id)->update(['estado' => 'en_proceso', 'pasa_seleccion' => null]);
        $this->decidir($seleccionador, $convocatoria, $a, 'no_seleccionado', $v2, 'Otro motivo distinto del anterior')
            ->assertStatus(409)->assertJsonPath('codigo', 'lista_requiere_revision');
        $this->assertSame(2, SeleccionDecisionEvento::count());
    }

    /** Escenario independiente en otra ficha, para no mezclar datos dentro de una misma prueba. */
    private function escenarioEnOtraFicha(): array
    {
        $ficha = $this->ficha('2631620');
        $convocatoria = $this->convocatoria($ficha, 1);
        SeleccionConfiguracion::create(['convocatoria_id' => $convocatoria->id, 'entrevista_activa' => true, 'prueba_tecnica_activa' => true, 'peso_entrevista' => 50, 'peso_prueba_tecnica' => 50]);
        $seleccionador = $this->seleccionador($ficha);
        $personas = [$this->candidato($convocatoria)];
        $version = $this->confirmarLista($seleccionador, $convocatoria, $personas);

        return [$seleccionador, $convocatoria, $personas, $version];
    }

    public function test_no_se_libera_con_la_convocatoria_cerrada(): void
    {
        [$seleccionador, $convocatoria, [$a], $version] = $this->escenario(1, 1);
        $this->decidir($seleccionador, $convocatoria, $a, 'seleccionado', $version)->assertCreated();
        $convocatoria->forceFill(['estado_id' => $this->parametroId('estado_convocatoria', 'cerrada')])->save();

        $this->decidir($seleccionador, $convocatoria, $a, 'no_seleccionado', $version, 'Intento de rectificar en una cerrada')->assertUnprocessable();
        $this->assertSame('seleccionado', SeleccionDecision::first()->resultado);
    }

    // ── Rendimiento ────────────────────────────────────────────────────

    public function test_la_consulta_paginada_no_crece_con_los_candidatos(): void
    {
        [$seleccionador, $convocatoria, $personas, $version] = $this->escenario(40, 3);
        $this->decidir($seleccionador, $convocatoria, $personas[0], 'seleccionado', $version)->assertCreated();

        $consultas = function () use ($seleccionador, $convocatoria): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->decisiones($seleccionador, $convocatoria)->assertOk();
            $total = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $total;
        };

        $conTres = $consultas();
        $mas = array_map(fn (int $i) => $this->candidato($convocatoria, '5'), range(1, 22));
        $version = $this->confirmarLista($seleccionador, $convocatoria, array_merge($personas, $mas));
        foreach (array_slice($mas, 0, 5) as $p) {
            $this->decidir($seleccionador, $convocatoria, $p, 'no_seleccionado', $version)->assertCreated();
        }
        $conVeinticinco = $consultas();

        $this->assertSame($conTres, $conVeinticinco);
        $this->decisiones($seleccionador, $convocatoria)->assertJsonPath('meta.total', 25)->assertJsonPath('meta.last_page', 2);
    }
}
