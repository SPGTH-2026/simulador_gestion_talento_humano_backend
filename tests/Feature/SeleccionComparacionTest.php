<?php

namespace App\Tests\Feature;

use App\Enums\Role;
use App\Models\Convocatoria;
use App\Models\ConvocatoriaSkill;
use App\Models\Evaluacion;
use App\Models\Ficha;
use App\Models\Parametro;
use App\Models\Postulacion;
use App\Models\PostulacionSkill;
use App\Models\ReferenciaDocumental;
use App\Models\SeleccionCalificacion;
use App\Models\SeleccionConfiguracion;
use App\Models\SeleccionDecision;
use App\Models\SeleccionFinalistaLista;
use App\Models\User;
use Database\Seeders\ParametroSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** CU-SEL-017: comparar de dos a cinco candidatos de una convocatoria (solo lectura). */
class SeleccionComparacionTest extends TestCase
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

    private function convocatoria(Ficha $ficha, int $vacantes = 2): Convocatoria
    {
        return Convocatoria::unguarded(fn () => Convocatoria::create([
            'ficha_id' => $ficha->id,
            'codigo' => sprintf('CONV-CMP-%03d', ++self::$codigoSecuencia),
            'nombre' => 'Convocatoria '.$ficha->codigo,
            'creado_por' => $this->usuario(Role::Instructor, null, ['ficha_id' => $ficha->id])->id,
            'estado_id' => $this->parametroId('estado_convocatoria', 'en_proceso'),
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

    /** Candidato habilitado (cumplimiento 85) y, si se pide, con ambas actividades calificadas. */
    private function candidato(Convocatoria $convocatoria, ?string $nota = '8'): Postulacion
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
        if ($nota !== null) {
            foreach (['entrevista', 'prueba_tecnica'] as $actividad) {
                SeleccionCalificacion::create([
                    'convocatoria_id' => $convocatoria->id,
                    'postulacion_id' => $postulacion->id,
                    'actividad' => $actividad,
                    'nota' => $nota,
                    'fecha_realizacion' => '2026-11-10',
                    'observacion' => 'Observación de '.$actividad,
                ]);
            }
        }

        return $postulacion;
    }

    /** Referencia documental de la postulación, con un evaluador y un designador propios. @return array{0: User, 1: User} */
    private function referencia(Postulacion $postulacion, int $fichaId): array
    {
        $evaluador = $this->usuario(Role::Aprendiz, 'evaluador', ['ficha_id' => $fichaId]);
        $designador = $this->usuario(Role::Instructor, null, ['ficha_id' => $fichaId]);
        $evaluacion = Evaluacion::where('postulacion_id', $postulacion->id)->firstOrFail();
        ReferenciaDocumental::create([
            'postulacion_id' => $postulacion->id,
            'evaluacion_id' => $evaluacion->id,
            'evaluador_id' => $evaluador->id,
            'designado_por' => $designador->id,
            'justificacion' => 'Referencia designada para las pruebas',
            'cumplimiento' => 85,
            'pasa_seleccion' => true,
            'evaluacion_actualizada_en' => $evaluacion->updated_at,
        ]);

        return [$evaluador, $designador];
    }

    /** Convocatoria con actividades 50/50, N candidatos con notas 10, 9, 8… y su lista de finalistas confirmada. */
    private function escenario(int $candidatos, bool $confirmar = true): array
    {
        $ficha = $this->ficha();
        $convocatoria = $this->convocatoria($ficha);
        SeleccionConfiguracion::create(['convocatoria_id' => $convocatoria->id, 'entrevista_activa' => true, 'prueba_tecnica_activa' => true, 'peso_entrevista' => 50, 'peso_prueba_tecnica' => 50]);
        $seleccionador = $this->seleccionador($ficha);
        $personas = array_map(fn (int $i) => $this->candidato($convocatoria, (string) (10 - $i)), range(0, $candidatos - 1));
        if ($confirmar) {
            $this->confirmarLista($seleccionador, $convocatoria, $personas);
        }

        return [$seleccionador, $convocatoria, $personas, $ficha];
    }

    private function confirmarLista(User $actor, Convocatoria $convocatoria, array $personas): int
    {
        $this->actingAs($actor)->putJson("/api/seleccion/convocatorias/{$convocatoria->id}/ranking/objetivo", ['objetivo' => 100])->assertOk();
        $version = $this->actingAs($actor)->getJson("/api/seleccion/convocatorias/{$convocatoria->id}/ranking")->json('meta.version');

        return $this->actingAs($actor)->postJson("/api/seleccion/convocatorias/{$convocatoria->id}/finalistas", [
            'version' => $version,
            'postulacion_ids' => array_map(fn (Postulacion $p) => $p->id, $personas),
            'justificacion' => 'Lista confirmada para las pruebas de comparación',
        ])->assertSuccessful()->json('data.version');
    }

    private function comparar(User $actor, Convocatoria $convocatoria, array $postulaciones)
    {
        $consulta = http_build_query(['postulaciones' => array_map(fn ($p) => $p instanceof Postulacion ? $p->id : $p, $postulaciones)]);

        return $this->actingAs($actor)->getJson("/api/seleccion/convocatorias/{$convocatoria->id}/comparacion?{$consulta}");
    }

    // ── Acceso y alcance ───────────────────────────────────────────────

    public function test_un_invitado_recibe_401(): void
    {
        [, $convocatoria, [$a, $b]] = $this->escenario(2);
        $this->app['auth']->forgetGuards();

        $this->getJson("/api/seleccion/convocatorias/{$convocatoria->id}/comparacion?postulaciones[]={$a->id}&postulaciones[]={$b->id}")->assertUnauthorized();
    }

    public function test_sin_permiso_de_seleccion_recibe_403(): void
    {
        [, $convocatoria, [$a, $b], $ficha] = $this->escenario(2);
        $evaluador = $this->usuario(Role::Aprendiz, 'evaluador', ['ficha_id' => $ficha->id]);
        $aspirante = $this->usuario(Role::Aspirante, null, ['ficha_id' => $ficha->id]);

        $this->comparar($evaluador, $convocatoria, [$a, $b])->assertForbidden();
        $this->comparar($aspirante, $convocatoria, [$a, $b])->assertForbidden();
    }

    public function test_una_convocatoria_de_otra_ficha_responde_404(): void
    {
        [, $convocatoria, [$a, $b]] = $this->escenario(2);
        $otro = $this->seleccionador($this->ficha('2631620'));

        $this->comparar($otro, $convocatoria, [$a, $b])->assertNotFound();
    }

    public function test_una_postulacion_de_otra_convocatoria_o_inexistente_responde_404_aunque_sea_de_la_misma_ficha(): void
    {
        [$seleccionador, $convocatoria, [$a, $b], $ficha] = $this->escenario(2);
        $otraConvocatoria = $this->convocatoria($ficha);
        $ajena = $this->candidato($otraConvocatoria);

        $this->comparar($seleccionador, $convocatoria, [$a, $ajena])->assertNotFound();
        $this->comparar($seleccionador, $convocatoria, [$a, 999999])->assertNotFound();
        $this->comparar($seleccionador, $convocatoria, [$a, $b])->assertOk(); // y las propias sí
    }

    public function test_exige_de_dos_a_cinco_postulaciones_distintas(): void
    {
        [$seleccionador, $convocatoria, $personas] = $this->escenario(6);
        $url = "/api/seleccion/convocatorias/{$convocatoria->id}/comparacion";

        $this->comparar($seleccionador, $convocatoria, [$personas[0]])->assertUnprocessable()->assertJsonValidationErrors('postulaciones');
        $this->comparar($seleccionador, $convocatoria, $personas)->assertUnprocessable()->assertJsonValidationErrors('postulaciones');
        $this->comparar($seleccionador, $convocatoria, [$personas[0], $personas[0]])->assertUnprocessable();
        $this->actingAs($seleccionador)->getJson($url)->assertUnprocessable()->assertJsonValidationErrors('postulaciones');
        $this->actingAs($seleccionador)->getJson("{$url}?postulaciones={$personas[0]->id}")->assertUnprocessable();
        $this->comparar($seleccionador, $convocatoria, array_slice($personas, 0, 5))->assertOk()->assertJsonCount(5, 'data');
    }

    // ── Contenido ──────────────────────────────────────────────────────

    public function test_compara_con_los_mismos_criterios_en_el_orden_pedido_y_sin_modificar_nada(): void
    {
        [$seleccionador, $convocatoria, [$a, $b, $c]] = $this->escenario(3);
        $decisiones = SeleccionDecision::count();
        $listas = SeleccionFinalistaLista::count();

        $respuesta = $this->comparar($seleccionador, $convocatoria, [$c, $a])->assertOk();

        $this->assertSame([$c->id, $a->id], array_column($respuesta->json('data'), 'postulacion_id'));
        foreach ($respuesta->json('data') as $fila) {
            $this->assertSame(
                ['postulacion_id', 'aspirante', 'fecha_postulacion', 'confirmada', 'revision', 'actual', 'documental', 'evaluacion', 'actividades', 'skills'],
                array_keys($fila),
            );
            $this->assertSame(['entrevista', 'prueba_tecnica'], array_keys($fila['actividades']));
            $this->assertNotNull($fila['fecha_postulacion']);
        }
        $primera = $respuesta->json('data.1'); // a: 10
        $this->assertSame(1, $primera['actual']['posicion']);
        $this->assertSame('10.00', $primera['actual']['resultado']);
        $this->assertSame(['estado' => 'habilitado', 'cumplimiento' => 85], $primera['documental']);
        $this->assertSame('10.00', $primera['actividades']['entrevista']['nota']);
        $this->assertSame('Observación de entrevista', $primera['actividades']['entrevista']['observacion']);
        $respuesta->assertJsonPath('meta.modo', 'con_actividades')
            ->assertJsonPath('meta.actividades.entrevista.peso', 50)
            ->assertJsonPath('meta.actividades.prueba_tecnica.activa', true)
            ->assertJsonPath('meta.lista.requiere_revision', false);

        $this->assertSame($decisiones, SeleccionDecision::count());
        $this->assertSame($listas, SeleccionFinalistaLista::count());
    }

    public function test_lo_confirmado_se_conserva_y_lo_actual_cambia_con_la_lista_en_revision(): void
    {
        [$seleccionador, $convocatoria, [$a, $b]] = $this->escenario(2);
        $version = SeleccionFinalistaLista::where('convocatoria_id', $convocatoria->id)->max('version');

        // Después de confirmar baja una nota: a pasa de 10 a 6 y b (9) lo supera.
        SeleccionCalificacion::where('postulacion_id', $a->id)->update(['nota' => '6']);

        $respuesta = $this->comparar($seleccionador, $convocatoria, [$a, $b])->assertOk();
        $fila = $respuesta->json('data.0');
        $this->assertSame(['lista_version' => $version, 'posicion' => 1, 'resultado' => '10.00'], $fila['confirmada']);
        $this->assertSame(2, $fila['actual']['posicion']);
        $this->assertSame('6.00', $fila['actual']['resultado']);
        $this->assertSame('cambio_resultado', $fila['revision']);
        $this->assertNull($respuesta->json('data.1.revision'));
        $respuesta->assertJsonPath('meta.lista.requiere_revision', true)->assertJsonPath('meta.lista.version', $version);
    }

    public function test_un_candidato_fuera_de_la_lista_vigente_no_trae_lo_confirmado(): void
    {
        [$seleccionador, $convocatoria, [$a, $b]] = $this->escenario(2);
        $this->confirmarLista($seleccionador, $convocatoria, [$b]); // la nueva versión ya no incluye a $a

        $respuesta = $this->comparar($seleccionador, $convocatoria, [$a, $b])->assertOk();
        $this->assertNull($respuesta->json('data.0.confirmada'));
        $this->assertNotNull($respuesta->json('data.1.confirmada'));
        $this->assertNotNull($respuesta->json('data.0.actual'));
    }

    public function test_solo_documental_no_trae_puesto_ni_resultado(): void
    {
        $ficha = $this->ficha();
        $convocatoria = $this->convocatoria($ficha);
        SeleccionConfiguracion::create(['convocatoria_id' => $convocatoria->id, 'entrevista_activa' => false, 'prueba_tecnica_activa' => false]);
        $seleccionador = $this->seleccionador($ficha);
        $personas = [$this->candidato($convocatoria, null), $this->candidato($convocatoria, null)];

        $respuesta = $this->comparar($seleccionador, $convocatoria, $personas)->assertOk();
        $respuesta->assertJsonPath('meta.modo', 'sin_actividades')
            ->assertJsonPath('meta.actividades.entrevista.activa', false)
            ->assertJsonPath('meta.lista', null);
        foreach ($respuesta->json('data') as $fila) {
            $this->assertNull($fila['actual']['posicion']);
            $this->assertNull($fila['actual']['resultado']);
            $this->assertTrue($fila['actual']['listo']);
            $this->assertSame(85, $fila['documental']['cumplimiento']);
            $this->assertSame('desactivada', $fila['actividades']['entrevista']['estado']);
        }
    }

    public function test_el_evaluador_y_la_referencia_solo_se_envian_a_instructor_y_superadministrador(): void
    {
        [$seleccionador, $convocatoria, [$a, $b], $ficha] = $this->escenario(2);

        $comun = $this->comparar($seleccionador, $convocatoria, [$a, $b])->assertOk();
        $comun->assertJsonPath('meta.evaluador_visible', false);
        $this->assertNull($comun->json('data.0.evaluacion'));
        $this->assertStringNotContainsString($this->evaluadorCompartido->name, $comun->getContent());

        $instructor = $this->usuario(Role::Instructor, null, ['ficha_id' => $ficha->id]);
        $admin = $this->usuario(Role::SuperAdmin);
        foreach ([$instructor, $admin] as $actor) {
            $r = $this->comparar($actor, $convocatoria, [$a, $b])->assertOk();
            $r->assertJsonPath('meta.evaluador_visible', true)
                ->assertJsonPath('data.0.evaluacion.evaluaciones.0.evaluador', $this->evaluadorCompartido->name)
                ->assertJsonPath('data.0.evaluacion.evaluaciones.0.cumplimiento', 85)
                ->assertJsonPath('data.0.evaluacion.referencia', null);
        }
    }

    public function test_con_referencias_documentales_el_instructor_ve_los_nombres_y_el_seleccionador_no(): void
    {
        [$seleccionador, $convocatoria, [$a, $b], $ficha] = $this->escenario(2);
        [$evaluadorA, $designadorA] = $this->referencia($a, $ficha->id);
        [$evaluadorB, $designadorB] = $this->referencia($b, $ficha->id);

        $comun = $this->comparar($seleccionador, $convocatoria, [$a, $b])->assertOk();
        $comun->assertJsonPath('meta.evaluador_visible', false);
        $this->assertNull($comun->json('data.0.evaluacion'));
        foreach ([$evaluadorA, $designadorA, $evaluadorB, $designadorB] as $persona) {
            $this->assertStringNotContainsString($persona->name, $comun->getContent());
        }

        $instructor = $this->usuario(Role::Instructor, null, ['ficha_id' => $ficha->id]);
        $r = $this->comparar($instructor, $convocatoria, [$a, $b])->assertOk();
        $r->assertJsonPath('data.0.evaluacion.referencia.evaluador', $evaluadorA->name)
            ->assertJsonPath('data.0.evaluacion.referencia.designada_por', $designadorA->name)
            ->assertJsonPath('data.1.evaluacion.referencia.evaluador', $evaluadorB->name)
            ->assertJsonPath('data.1.evaluacion.referencia.designada_por', $designadorB->name);
    }

    public function test_las_skills_de_la_convocatoria_con_su_estado_y_el_total_de_adicionales_sin_exponer_el_archivo(): void
    {
        [$seleccionador, $convocatoria, [$a, $b]] = $this->escenario(2);
        $php = ConvocatoriaSkill::create(['convocatoria_id' => $convocatoria->id, 'nombre' => 'PHP']);
        $sql = ConvocatoriaSkill::create(['convocatoria_id' => $convocatoria->id, 'nombre' => 'SQL']);
        // a: PHP con certificado cargado, SQL solo declarada y dos adicionales propias. b: nada.
        PostulacionSkill::create(['postulacion_id' => $a->id, 'convocatoria_skill_id' => $php->id, 'nombre' => 'PHP', 'certificado_nombre_original' => 'secreto-confidencial.pdf', 'certificado_ruta' => 'privado/ruta/oculta.pdf', 'certificado_disco' => 'local']);
        PostulacionSkill::create(['postulacion_id' => $a->id, 'convocatoria_skill_id' => $sql->id, 'nombre' => 'SQL']);
        PostulacionSkill::create(['postulacion_id' => $a->id, 'nombre' => 'Excel avanzado']);
        PostulacionSkill::create(['postulacion_id' => $a->id, 'nombre' => 'Inglés B2', 'certificado_ruta' => 'otra/ruta.pdf']);

        $respuesta = $this->comparar($seleccionador, $convocatoria, [$a, $b])->assertOk();
        $respuesta->assertJsonPath('meta.skills_requeridas', 2);
        $this->assertSame(
            [['nombre' => 'PHP', 'declarada' => true, 'certificado_cargado' => true], ['nombre' => 'SQL', 'declarada' => true, 'certificado_cargado' => false]],
            $respuesta->json('data.0.skills.requeridas'),
        );
        $this->assertSame(2, $respuesta->json('data.0.skills.adicionales'));
        $this->assertSame(
            [['nombre' => 'PHP', 'declarada' => false, 'certificado_cargado' => false], ['nombre' => 'SQL', 'declarada' => false, 'certificado_cargado' => false]],
            $respuesta->json('data.1.skills.requeridas'),
        );
        $this->assertSame(0, $respuesta->json('data.1.skills.adicionales'));
        // Ni el nombre ni la ruta del archivo viajan.
        $this->assertStringNotContainsString('secreto-confidencial', $respuesta->getContent());
        $this->assertStringNotContainsString('ruta', $respuesta->getContent());
    }

    public function test_un_candidato_que_dejo_de_estar_postulado_se_compara_sin_situacion_actual(): void
    {
        [$seleccionador, $convocatoria, [$a, $b]] = $this->escenario(2);
        Postulacion::whereKey($a->id)->update(['estado_id' => $this->parametroId('estado_postulacion', 'retirada')]);

        $respuesta = $this->comparar($seleccionador, $convocatoria, [$a, $b])->assertOk();
        $this->assertNull($respuesta->json('data.0.actual'));
        $this->assertSame('ya_no_elegible', $respuesta->json('data.0.revision'));
        $this->assertNotNull($respuesta->json('data.0.confirmada'));
        $this->assertNotNull($respuesta->json('data.1.actual'));
    }

    public function test_la_consulta_no_crece_con_el_numero_de_candidatos(): void
    {
        [$seleccionador, $convocatoria, $personas] = $this->escenario(5);
        $instructor = $this->usuario(Role::Instructor, null, ['ficha_id' => $convocatoria->ficha_id]);
        ConvocatoriaSkill::create(['convocatoria_id' => $convocatoria->id, 'nombre' => 'PHP']);
        foreach ($personas as $p) {
            PostulacionSkill::create(['postulacion_id' => $p->id, 'nombre' => 'Propia '.$p->id]);
            // Cada candidato con su propia referencia documental, evaluador y designador distintos: si evaluadoresDe
            // cargara las relaciones de una en una, las consultas del Instructor crecerían con los candidatos.
            $this->referencia($p, $convocatoria->ficha_id);
        }

        $consultas = function (User $actor, array $ids) use ($convocatoria): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->comparar($actor, $convocatoria, $ids)->assertOk();
            $total = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $total;
        };

        $this->assertSame($consultas($seleccionador, array_slice($personas, 0, 2)), $consultas($seleccionador, $personas));
        $this->assertSame($consultas($instructor, array_slice($personas, 0, 2)), $consultas($instructor, $personas));
    }
}
