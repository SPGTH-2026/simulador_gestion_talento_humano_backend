<?php

namespace App\Tests\Feature;

use App\Enums\Role;
use App\Models\Convocatoria;
use App\Models\Evaluacion;
use App\Models\Ficha;
use App\Models\Parametro;
use App\Models\Postulacion;
use App\Models\ReferenciaDocumental;
use App\Models\User;
use Database\Seeders\ParametroSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SeleccionReferenciaTest extends TestCase
{
    use RefreshDatabase;

    private static int $codigoSecuencia = 0;

    private const JUSTIFICACION = 'El segundo evaluador revisó el soporte completo.';

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

    private function instructor(Ficha $ficha): User
    {
        return $this->usuario(Role::Instructor, null, ['ficha_id' => $ficha->id]);
    }

    private function parametroId(string $tema, string $codigo): int
    {
        return (int) Parametro::porTemaYCodigo($tema, $codigo)->id;
    }

    private function convocatoria(Ficha $ficha): Convocatoria
    {
        return Convocatoria::unguarded(fn () => Convocatoria::create([
            'ficha_id' => $ficha->id,
            'codigo' => sprintf('CONV-REF-%03d', ++self::$codigoSecuencia),
            'nombre' => 'Convocatoria '.$ficha->codigo,
            'creado_por' => $this->instructor($ficha)->id,
            'estado_id' => $this->parametroId('estado_convocatoria', 'publicada'),
            'tipo_convocatoria_id' => $this->parametroId('tipo_convocatoria', 'externo'),
            'tipo_empleo_id' => $this->parametroId('tipo_empleo', 'tiempo_completo'),
            'tipo_contrato_id' => $this->parametroId('tipo_contrato', 'termino_fijo'),
            'modalidad_id' => $this->parametroId('modalidad', 'presencial'),
            'vacantes' => 5,
        ]));
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

    private function url(Convocatoria $convocatoria, Postulacion $postulacion): string
    {
        return "/api/seleccion/convocatorias/{$convocatoria->id}/candidatos/{$postulacion->id}/referencia-documental";
    }

    private function designar(User $actor, Convocatoria $convocatoria, Postulacion $postulacion, Evaluacion $evaluacion, ?string $justificacion = self::JUSTIFICACION)
    {
        return $this->actingAs($actor)->postJson($this->url($convocatoria, $postulacion), [
            'evaluacion_id' => $evaluacion->id,
            'justificacion' => $justificacion,
        ]);
    }

    /** Fila del listado de candidatos para una postulación. */
    private function fila(User $actor, Convocatoria $convocatoria, Postulacion $postulacion): array
    {
        return collect($this->actingAs($actor)
            ->getJson("/api/seleccion/convocatorias/{$convocatoria->id}/candidatos")
            ->assertOk()->json('data'))->firstWhere('id', $postulacion->id);
    }

    /** Postulación con dos evaluaciones finalizadas que se contradicen (A habilita, B no). */
    private function casoContradictorio(): array
    {
        $ficha = $this->ficha('2896411');
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->convocatoria($ficha);
        $postulacion = $this->postulacion($convocatoria);
        $a = $this->evaluacion($postulacion, 'finalizada', 90, true);
        $b = $this->evaluacion($postulacion, 'finalizada', 30, false);

        return [$instructor, $convocatoria, $postulacion, $a, $b, $ficha];
    }

    // ── Acceso ─────────────────────────────────────────────────────────

    public function test_invitado_recibe_401(): void
    {
        [, $convocatoria, $postulacion, $a] = $this->casoContradictorio();

        $this->getJson($this->url($convocatoria, $postulacion))->assertUnauthorized();
        $this->postJson($this->url($convocatoria, $postulacion), ['evaluacion_id' => $a->id, 'justificacion' => self::JUSTIFICACION])
            ->assertUnauthorized();
    }

    public function test_quien_no_es_instructor_ni_superadmin_recibe_403_aunque_tenga_el_permiso(): void
    {
        [, $convocatoria, $postulacion, $a, , $ficha] = $this->casoContradictorio();

        $actores = [
            // Estos dos aprendices sí tienen seleccion:decidir, pero no son decisores.
            $this->usuario(Role::Aprendiz, 'seleccionador', ['ficha_id' => $ficha->id]),
            $this->usuario(Role::Aprendiz, 'general', ['ficha_id' => $ficha->id]),
            $this->usuario(Role::Aprendiz, 'evaluador', ['ficha_id' => $ficha->id]),
            $this->usuario(Role::Aspirante, null, ['ficha_id' => $ficha->id]),
        ];

        foreach ($actores as $actor) {
            $this->actingAs($actor)->getJson($this->url($convocatoria, $postulacion))->assertForbidden();
            $this->designar($actor, $convocatoria, $postulacion, $a)->assertForbidden();
        }
        $this->assertSame(0, ReferenciaDocumental::count());
    }

    public function test_la_autorizacion_se_resuelve_antes_que_la_existencia(): void
    {
        $ficha = $this->ficha('2896411');
        $seleccionador = $this->usuario(Role::Aprendiz, 'seleccionador', ['ficha_id' => $ficha->id]);

        $this->actingAs($seleccionador)
            ->getJson('/api/seleccion/convocatorias/999998/candidatos/999999/referencia-documental')
            ->assertForbidden();
    }

    public function test_correo_sin_verificar_recibe_403(): void
    {
        [, $convocatoria, $postulacion, , , $ficha] = $this->casoContradictorio();
        $sinVerificar = $this->usuario(Role::Instructor, null, ['ficha_id' => $ficha->id, 'email_verified_at' => null]);

        $this->actingAs($sinVerificar)->getJson($this->url($convocatoria, $postulacion))->assertForbidden();
    }

    public function test_instructor_de_otra_ficha_recibe_404_y_el_superadmin_opera_en_cualquiera(): void
    {
        [, $convocatoria, $postulacion, $a] = $this->casoContradictorio();
        $ajeno = $this->instructor($this->ficha('2631620'));
        $superadmin = $this->usuario(Role::SuperAdmin);

        $this->actingAs($ajeno)->getJson($this->url($convocatoria, $postulacion))->assertNotFound();
        $this->designar($ajeno, $convocatoria, $postulacion, $a)->assertNotFound();
        $this->assertSame(0, ReferenciaDocumental::count());

        $this->actingAs($superadmin)->getJson($this->url($convocatoria, $postulacion))->assertOk();
        $this->designar($superadmin, $convocatoria, $postulacion, $a)->assertCreated();
    }

    public function test_recursos_que_no_pertenecen_al_padre_responden_404(): void
    {
        [$instructor, $convocatoria, $postulacion, $a, , $ficha] = $this->casoContradictorio();
        $otraConvocatoria = $this->convocatoria($ficha);
        $postulacionAjena = $this->postulacion($otraConvocatoria);
        $evaluacionAjena = $this->evaluacion($postulacionAjena, 'finalizada', 70, true);

        // Postulación de otra convocatoria.
        $this->actingAs($instructor)->getJson($this->url($convocatoria, $postulacionAjena))->assertNotFound();
        $this->designar($instructor, $convocatoria, $postulacionAjena, $evaluacionAjena)->assertNotFound();
        // Evaluación de otra postulación.
        $this->designar($instructor, $convocatoria, $postulacion, $evaluacionAjena)->assertNotFound();
        // Inexistentes.
        $this->actingAs($instructor)->getJson("/api/seleccion/convocatorias/999999/candidatos/{$postulacion->id}/referencia-documental")->assertNotFound();
        $this->actingAs($instructor)->getJson("/api/seleccion/convocatorias/{$convocatoria->id}/candidatos/999999/referencia-documental")->assertNotFound();
        $this->actingAs($instructor)->postJson($this->url($convocatoria, $postulacion), ['evaluacion_id' => 999999, 'justificacion' => self::JUSTIFICACION])->assertNotFound();

        $this->assertSame(0, ReferenciaDocumental::count());
    }

    // ── Consulta ───────────────────────────────────────────────────────

    public function test_la_consulta_lista_solo_evaluaciones_elegibles_sin_datos_sensibles(): void
    {
        [$instructor, $convocatoria, $postulacion, $a, $b] = $this->casoContradictorio();
        $this->evaluacion($postulacion, 'en_proceso', 50, null);
        $this->evaluacion($postulacion, 'finalizada', null, null);

        $respuesta = $this->actingAs($instructor)->getJson($this->url($convocatoria, $postulacion))->assertOk();
        $datos = $respuesta->json('data');

        $this->assertEqualsCanonicalizing([$a->id, $b->id], collect($datos['evaluaciones'])->pluck('id')->all());
        $this->assertSame(['id', 'evaluador', 'cumplimiento', 'resultado', 'actualizada_en'], array_keys($datos['evaluaciones'][0]));
        $this->assertSame('requiere_revision', $datos['estado_documental']);
        $this->assertNull($datos['referencia']);

        foreach (['email', 'observaciones', 'documentos', 'detalles', 'perfil'] as $prohibido) {
            $this->assertStringNotContainsString($prohibido, $respuesta->getContent());
        }
    }

    // ── Designación ────────────────────────────────────────────────────

    public function test_el_listado_usa_exactamente_la_evaluacion_que_eligio_el_instructor(): void
    {
        [$instructor, $convocatoria, $postulacion, $a, $b] = $this->casoContradictorio();

        $this->assertSame('requiere_revision', $this->fila($instructor, $convocatoria, $postulacion)['estado_documental']);

        $this->designar($instructor, $convocatoria, $postulacion, $b)->assertCreated()
            ->assertJsonPath('data.estado_documental', 'no_habilitado')
            ->assertJsonPath('data.referencia.vigente', true)
            ->assertJsonPath('data.referencia.designada_por', $instructor->name);

        $fila = $this->fila($instructor, $convocatoria, $postulacion);
        $this->assertSame(['no_habilitado', 30], [$fila['estado_documental'], $fila['cumplimiento']]);
        $this->assertSame(['id', 'aspirante', 'estado_documental', 'cumplimiento'], array_keys($fila));

        // También el seleccionador, que solo consulta, ve el resultado elegido.
        $seleccionador = $this->usuario(Role::Aprendiz, 'seleccionador', ['ficha_id' => $postulacion->convocatoria->ficha_id]);
        $this->assertSame('no_habilitado', $this->fila($seleccionador, $convocatoria, $postulacion)['estado_documental']);
    }

    public function test_la_justificacion_es_obligatoria_y_acotada(): void
    {
        [$instructor, $convocatoria, $postulacion, $a] = $this->casoContradictorio();

        $this->designar($instructor, $convocatoria, $postulacion, $a, null)->assertUnprocessable()->assertJsonValidationErrors('justificacion');
        $this->designar($instructor, $convocatoria, $postulacion, $a, '   ')->assertUnprocessable()->assertJsonValidationErrors('justificacion');
        $this->designar($instructor, $convocatoria, $postulacion, $a, 'corta')->assertUnprocessable()->assertJsonValidationErrors('justificacion');
        $this->designar($instructor, $convocatoria, $postulacion, $a, str_repeat('x', 501))->assertUnprocessable()->assertJsonValidationErrors('justificacion');
        $this->actingAs($instructor)->postJson($this->url($convocatoria, $postulacion), ['justificacion' => self::JUSTIFICACION])
            ->assertUnprocessable()->assertJsonValidationErrors('evaluacion_id');

        $this->assertSame(0, ReferenciaDocumental::count());

        // Se recorta antes de guardar.
        $this->designar($instructor, $convocatoria, $postulacion, $a, '  '.self::JUSTIFICACION.'  ')->assertCreated();
        $this->assertSame(self::JUSTIFICACION, ReferenciaDocumental::first()->justificacion);
    }

    public function test_rectificar_conserva_ambas_designaciones_y_vale_la_ultima(): void
    {
        [$instructor, $convocatoria, $postulacion, $a, $b] = $this->casoContradictorio();

        $this->designar($instructor, $convocatoria, $postulacion, $a)->assertCreated();
        $this->assertSame('habilitado', $this->fila($instructor, $convocatoria, $postulacion)['estado_documental']);

        $this->designar($instructor, $convocatoria, $postulacion, $b, 'Corrijo: el soporte válido es el segundo.')->assertCreated();

        $eventos = ReferenciaDocumental::query()->orderBy('id')->get();
        $this->assertCount(2, $eventos);
        $this->assertSame([$a->id, $b->id], $eventos->pluck('evaluacion_id')->all());
        $this->assertSame([90, 30], $eventos->pluck('cumplimiento')->all());
        $this->assertSame([$instructor->id, $instructor->id], $eventos->pluck('designado_por')->all());
        $this->assertSame('no_habilitado', $this->fila($instructor, $convocatoria, $postulacion)['estado_documental']);
    }

    public function test_sin_necesidad_de_referencia_se_rechaza_la_designacion(): void
    {
        $ficha = $this->ficha('2896411');
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->convocatoria($ficha);
        $postulacion = $this->postulacion($convocatoria);
        $unica = $this->evaluacion($postulacion, 'finalizada', 80, true);

        $this->designar($instructor, $convocatoria, $postulacion, $unica)
            ->assertUnprocessable()->assertJsonValidationErrors('evaluacion_id');
        $this->assertSame(0, ReferenciaDocumental::count());
    }

    public function test_una_evaluacion_no_elegible_o_una_postulacion_no_postulada_dan_error_de_validacion(): void
    {
        [$instructor, $convocatoria, $postulacion] = $this->casoContradictorio();
        $enProceso = $this->evaluacion($postulacion, 'en_proceso', 60, null);

        $this->designar($instructor, $convocatoria, $postulacion, $enProceso)
            ->assertUnprocessable()->assertJsonValidationErrors('evaluacion_id');

        // La postulación se retira entre la consulta y la designación.
        $elegible = Evaluacion::query()->where('postulacion_id', $postulacion->id)->where('estado', 'finalizada')->first();
        $postulacion->update(['estado_id' => $this->parametroId('estado_postulacion', 'retirada')]);
        $this->designar($instructor, $convocatoria, $postulacion, $elegible)
            ->assertUnprocessable()->assertJsonValidationErrors('postulacion');

        $this->assertSame(0, ReferenciaDocumental::count());
    }

    // ── Vigencia ───────────────────────────────────────────────────────

    public function test_una_evaluacion_nueva_que_contradice_a_la_referencia_no_la_invalida(): void
    {
        [$instructor, $convocatoria, $postulacion, $a] = $this->casoContradictorio();
        $this->designar($instructor, $convocatoria, $postulacion, $a)->assertCreated();

        // Otro evaluador finaliza después, con un resultado opuesto al de la referencia (a: 90 % habilitado).
        $this->travel(10)->minutes();
        $nueva = $this->evaluacion($postulacion, 'finalizada', 10, false);

        $fila = $this->fila($instructor, $convocatoria, $postulacion);
        $this->assertSame(['habilitado', 90], [$fila['estado_documental'], $fila['cumplimiento']]);

        $datos = $this->actingAs($instructor)->getJson($this->url($convocatoria, $postulacion))->json('data');
        $this->assertTrue($datos['referencia']['vigente']);
        $this->assertSame($a->id, $datos['referencia']['evaluacion_id']);
        // La nueva evaluación aparece como candidata para una rectificación futura, sin alterar el resultado.
        $this->assertContains($nueva->id, collect($datos['evaluaciones'])->pluck('id')->all());
        $this->assertSame(1, ReferenciaDocumental::count());
    }

    public function test_si_la_referencia_se_reabre_obliga_a_revisar_aunque_quede_otra_finalizada(): void
    {
        [$instructor, $convocatoria, $postulacion, $a, $b] = $this->casoContradictorio();
        $this->designar($instructor, $convocatoria, $postulacion, $a)->assertCreated();

        // El evaluador reabre la evaluación elegida: pasa a en_proceso sin resultado.
        $a->update(['estado' => 'en_proceso', 'pasa_seleccion' => null]);

        $fila = $this->fila($instructor, $convocatoria, $postulacion);
        $this->assertSame(['requiere_revision', null], [$fila['estado_documental'], $fila['cumplimiento']]);

        $datos = $this->actingAs($instructor)->getJson($this->url($convocatoria, $postulacion))->json('data');
        $this->assertFalse($datos['referencia']['vigente']);
        $this->assertSame([$b->id], collect($datos['evaluaciones'])->pluck('id')->all());
    }

    public function test_la_invalidacion_documental_sin_timestamps_tambien_se_detecta(): void
    {
        [$instructor, $convocatoria, $postulacion, $a, $b] = $this->casoContradictorio();
        $this->designar($instructor, $convocatoria, $postulacion, $a)->assertCreated();

        // Igual que PostulacionDocumentoController::invalidarEvaluacionesPorCambioDocumental.
        $a->timestamps = false;
        $a->estado = 'en_proceso';
        $a->pasa_seleccion = null;
        $a->save();

        $this->assertSame('requiere_revision', $this->fila($instructor, $convocatoria, $postulacion)['estado_documental']);
    }

    public function test_si_la_referencia_se_edita_o_se_vuelve_a_finalizar_obliga_a_revisar(): void
    {
        [$instructor, $convocatoria, $postulacion, $a] = $this->casoContradictorio();
        $this->designar($instructor, $convocatoria, $postulacion, $a)->assertCreated();

        // Mismo resultado, pero editada después: no se asume que sigue siendo lo que se revisó.
        $this->travel(5)->minutes();
        $a->update(['observaciones' => 'Ajuste posterior']);
        $this->assertSame('requiere_revision', $this->fila($instructor, $convocatoria, $postulacion)['estado_documental']);

        // Cambia el cumplimiento y vuelve a finalizar.
        $this->designar($instructor, $convocatoria, $postulacion, $a->fresh(), 'Reconfirmo tras la edición del evaluador.')->assertCreated();
        $this->assertSame('habilitado', $this->fila($instructor, $convocatoria, $postulacion)['estado_documental']);
        $this->travel(5)->minutes();
        $a->update(['cumplimiento' => 55, 'pasa_seleccion' => false]);
        $fila = $this->fila($instructor, $convocatoria, $postulacion);
        $this->assertSame(['requiere_revision', null], [$fila['estado_documental'], $fila['cumplimiento']]);
    }

    public function test_reconfirmar_con_una_unica_elegible_y_no_volver_a_la_referencia_anterior(): void
    {
        [$instructor, $convocatoria, $postulacion, $a, $b] = $this->casoContradictorio();
        $this->designar($instructor, $convocatoria, $postulacion, $a)->assertCreated();

        // Se reabre la referencia: queda una sola elegible (b), pero no se usa por descarte.
        $a->update(['estado' => 'en_proceso', 'pasa_seleccion' => null]);
        $this->assertSame('requiere_revision', $this->fila($instructor, $convocatoria, $postulacion)['estado_documental']);

        // Reconfirmar la única elegible sí es posible, porque la referencia quedó invalidada.
        $this->designar($instructor, $convocatoria, $postulacion, $b, 'Confirmo la única evaluación vigente.')->assertCreated();
        $fila = $this->fila($instructor, $convocatoria, $postulacion);
        $this->assertSame(['no_habilitado', 30], [$fila['estado_documental'], $fila['cumplimiento']]);

        // Vigente y única: ya no hace falta (ni se permite) otra designación.
        $this->designar($instructor, $convocatoria, $postulacion, $b)->assertUnprocessable();
        $this->assertSame(2, ReferenciaDocumental::count());
    }

    public function test_si_la_referencia_vigente_se_invalida_no_se_regresa_a_una_anterior(): void
    {
        [$instructor, $convocatoria, $postulacion, $a, $b] = $this->casoContradictorio();
        $this->designar($instructor, $convocatoria, $postulacion, $a)->assertCreated();
        $this->designar($instructor, $convocatoria, $postulacion, $b)->assertCreated();

        // La anterior (a) sigue intacta, pero la vigente es b: si b cambia, no se vuelve a a.
        $b->update(['estado' => 'en_proceso', 'pasa_seleccion' => null]);

        $this->assertSame('requiere_revision', $this->fila($instructor, $convocatoria, $postulacion)['estado_documental']);
    }

    public function test_si_la_evaluacion_se_elimina_el_historial_permanece(): void
    {
        [$instructor, $convocatoria, $postulacion, $a] = $this->casoContradictorio();
        $this->designar($instructor, $convocatoria, $postulacion, $a)->assertCreated();

        $a->delete();

        $evento = ReferenciaDocumental::first();
        $this->assertNotNull($evento);
        $this->assertNull($evento->evaluacion_id);
        $this->assertSame(90, $evento->cumplimiento);
        $this->assertSame('requiere_revision', $this->fila($instructor, $convocatoria, $postulacion)['estado_documental']);
    }

    public function test_el_cero_por_ciento_es_un_dato_valido_y_null_es_ausencia(): void
    {
        $ficha = $this->ficha('2896411');
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->convocatoria($ficha);
        $conCero = $this->postulacion($convocatoria);
        $this->evaluacion($conCero, 'finalizada', 0, false);
        $sinEvaluar = $this->postulacion($convocatoria);

        $this->assertSame(['no_habilitado', 0], [
            $this->fila($instructor, $convocatoria, $conCero)['estado_documental'],
            $this->fila($instructor, $convocatoria, $conCero)['cumplimiento'],
        ]);
        $this->assertNull($this->fila($instructor, $convocatoria, $sinEvaluar)['cumplimiento']);

        // Referencia con 0 %: se conserva el 0, no se convierte en null.
        $ficha2 = $this->ficha('2631620');
        $instructor2 = $this->instructor($ficha2);
        $convocatoria2 = $this->convocatoria($ficha2);
        $postulacion = $this->postulacion($convocatoria2);
        $cero = $this->evaluacion($postulacion, 'finalizada', 0, false);
        $this->evaluacion($postulacion, 'finalizada', 80, true);
        $this->designar($instructor2, $convocatoria2, $postulacion, $cero)->assertCreated();
        $this->assertSame(0, $this->fila($instructor2, $convocatoria2, $postulacion)['cumplimiento']);
    }

    // ── Regresión y rendimiento ────────────────────────────────────────

    public function test_regresion_un_solo_evaluador_sigue_siendo_automatico(): void
    {
        $ficha = $this->ficha('2896411');
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->convocatoria($ficha);
        $pendiente = $this->postulacion($convocatoria);
        $habilitada = $this->postulacion($convocatoria);
        $this->evaluacion($habilitada, 'finalizada', 85, true);
        $noHabilitada = $this->postulacion($convocatoria);
        $this->evaluacion($noHabilitada, 'finalizada', 40, false);
        $multiple = $this->postulacion($convocatoria);
        $this->evaluacion($multiple, 'finalizada', 90, true);
        $this->evaluacion($multiple, 'finalizada', 90, true);

        $this->assertSame(['pendiente', null], array_values(array_intersect_key($this->fila($instructor, $convocatoria, $pendiente), array_flip(['estado_documental', 'cumplimiento']))));
        $this->assertSame(['habilitado', 85], array_values(array_intersect_key($this->fila($instructor, $convocatoria, $habilitada), array_flip(['estado_documental', 'cumplimiento']))));
        $this->assertSame(['no_habilitado', 40], array_values(array_intersect_key($this->fila($instructor, $convocatoria, $noHabilitada), array_flip(['estado_documental', 'cumplimiento']))));
        $this->assertSame(['requiere_revision', null], array_values(array_intersect_key($this->fila($instructor, $convocatoria, $multiple), array_flip(['estado_documental', 'cumplimiento']))));
    }

    public function test_el_numero_de_consultas_del_listado_no_crece_con_candidatos_ni_referencias(): void
    {
        $ficha = $this->ficha('2896411');
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->convocatoria($ficha);

        $crear = function (int $cantidad) use ($instructor, $convocatoria): void {
            foreach (range(1, $cantidad) as $i) {
                $postulacion = $this->postulacion($convocatoria);
                $a = $this->evaluacion($postulacion, 'finalizada', 90, true);
                $this->evaluacion($postulacion, 'finalizada', 30, false);
                $this->designar($instructor, $convocatoria, $postulacion, $a)->assertCreated();
            }
        };

        $consultas = function () use ($instructor, $convocatoria): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($instructor)->getJson("/api/seleccion/convocatorias/{$convocatoria->id}/candidatos")->assertOk();
            $total = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $total;
        };

        $crear(2);
        $conDos = $consultas();
        $crear(15);
        $conDiecisiete = $consultas();

        $this->assertSame($conDos, $conDiecisiete);
    }
}
