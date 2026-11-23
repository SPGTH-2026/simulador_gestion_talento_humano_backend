<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\ConvocatoriaSkill;
use App\Models\Postulacion;
use App\Models\PostulacionSkill;
use App\Models\SeleccionCalificacion;
use App\Models\SeleccionConfiguracion;
use App\Models\SeleccionDecision;
use App\Models\SeleccionFinalistaLista;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SeleccionComparacionAyudas;
use Tests\TestCase;

class SeleccionComparacionContenidoTest extends TestCase
{
    use RefreshDatabase;
    use SeleccionComparacionAyudas;

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
