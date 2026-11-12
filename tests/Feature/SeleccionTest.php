<?php

namespace App\Tests\Feature;

use App\Enums\Role;
use App\Models\Convocatoria;
use App\Models\Evaluacion;
use App\Models\Ficha;
use App\Models\Parametro;
use App\Models\Postulacion;
use App\Models\User;
use Database\Seeders\ParametroSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SeleccionTest extends TestCase
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

    private function parametroId(string $tema, string $codigo): int
    {
        return (int) Parametro::porTemaYCodigo($tema, $codigo)->id;
    }

    private function convocatoria(Ficha $ficha, string $estado = 'publicada', int $vacantes = 5): Convocatoria
    {
        $creador = $this->usuario(Role::Instructor, null, ['ficha_id' => $ficha->id]);

        return Convocatoria::unguarded(fn () => Convocatoria::create([
            'ficha_id' => $ficha->id,
            'codigo' => sprintf('CONV-SEL-%03d', ++self::$codigoSecuencia),
            'nombre' => 'Convocatoria '.$ficha->codigo,
            'cargo' => 'Analista',
            'creado_por' => $creador->id,
            'estado_id' => $this->parametroId('estado_convocatoria', $estado),
            'tipo_convocatoria_id' => $this->parametroId('tipo_convocatoria', 'externo'),
            'tipo_empleo_id' => $this->parametroId('tipo_empleo', 'tiempo_completo'),
            'tipo_contrato_id' => $this->parametroId('tipo_contrato', 'termino_fijo'),
            'modalidad_id' => $this->parametroId('modalidad', 'presencial'),
            'vacantes' => $vacantes,
        ]));
    }

    private function postulacion(Convocatoria $convocatoria, string $estado = 'postulada', ?User $aspirante = null): Postulacion
    {
        $aspirante ??= $this->usuario(Role::Aspirante, null, ['ficha_id' => $convocatoria->ficha_id]);

        return Postulacion::create([
            'convocatoria_id' => $convocatoria->id,
            'user_id' => $aspirante->id,
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

    private function seleccionador(Ficha $ficha): User
    {
        return $this->usuario(Role::Aprendiz, 'seleccionador', ['ficha_id' => $ficha->id]);
    }

    private function candidatos(User $actor, Convocatoria $convocatoria)
    {
        return $this->actingAs($actor)->getJson("/api/seleccion/convocatorias/{$convocatoria->id}/candidatos");
    }

    private function estadoDe(User $actor, Convocatoria $convocatoria, Postulacion $postulacion): array
    {
        $fila = collect($this->candidatos($actor, $convocatoria)->assertOk()->json('data'))
            ->firstWhere('id', $postulacion->id);

        return $fila;
    }

    // ── Acceso ─────────────────────────────────────────────────────────

    public function test_invitado_recibe_401_en_ambas_rutas(): void
    {
        $convocatoria = $this->convocatoria($this->ficha('2896411'));

        $this->getJson('/api/seleccion/convocatorias')->assertUnauthorized();
        $this->getJson("/api/seleccion/convocatorias/{$convocatoria->id}/candidatos")->assertUnauthorized();
    }

    public function test_usuario_sin_permiso_recibe_403_en_ambas_rutas(): void
    {
        $ficha = $this->ficha('2896411');
        $convocatoria = $this->convocatoria($ficha);
        $evaluador = $this->usuario(Role::Aprendiz, 'evaluador', ['ficha_id' => $ficha->id]);
        $aspirante = $this->usuario(Role::Aspirante, null, ['ficha_id' => $ficha->id]);

        foreach ([$evaluador, $aspirante] as $actor) {
            $this->actingAs($actor)->getJson('/api/seleccion/convocatorias')->assertForbidden();
            $this->candidatos($actor, $convocatoria)->assertForbidden();
        }
    }

    public function test_correo_sin_verificar_recibe_403(): void
    {
        $ficha = $this->ficha('2896411');
        $sinVerificar = $this->usuario(Role::Aprendiz, 'seleccionador', [
            'ficha_id' => $ficha->id,
            'email_verified_at' => null,
        ]);

        $this->actingAs($sinVerificar)->getJson('/api/seleccion/convocatorias')->assertForbidden();
    }

    public function test_convocatoria_ajena_o_inexistente_responde_404(): void
    {
        $fichaA = $this->ficha('2896411');
        $fichaB = $this->ficha('2631620');
        $ajena = $this->convocatoria($fichaB);
        $this->postulacion($ajena);
        $seleccionador = $this->seleccionador($fichaA);

        $this->candidatos($seleccionador, $ajena)->assertNotFound();
        $this->actingAs($seleccionador)->getJson('/api/seleccion/convocatorias/999999/candidatos')->assertNotFound();
    }

    // ── Alcance de convocatorias ───────────────────────────────────────

    public function test_seleccionador_con_acceso_sin_convocatorias_ver_ve_solo_su_ficha_sin_borradores(): void
    {
        $fichaA = $this->ficha('2896411');
        $fichaB = $this->ficha('2631620');
        $seleccionador = $this->seleccionador($fichaA);
        $this->assertFalse($seleccionador->can('convocatorias:ver'));

        $publicada = $this->convocatoria($fichaA, 'publicada', 3);
        $cerrada = $this->convocatoria($fichaA, 'cerrada');
        $borrador = $this->convocatoria($fichaA, 'borrador');
        $ajena = $this->convocatoria($fichaB);

        $respuesta = $this->actingAs($seleccionador)->getJson('/api/seleccion/convocatorias')->assertOk();
        $ids = collect($respuesta->json('data'))->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$publicada->id, $cerrada->id], $ids);
        $this->assertNotContains($borrador->id, $ids);
        $this->assertNotContains($ajena->id, $ids);

        $fila = collect($respuesta->json('data'))->firstWhere('id', $publicada->id);
        $this->assertSame(3, $fila['vacantes']);
        $this->assertSame('publicada', $fila['estado']['codigo']);
        $this->assertSame(['id', 'codigo', 'nombre', 'cargo', 'estado', 'vacantes'], array_keys($fila));
        $this->assertArrayNotHasKey('ficha_id', $fila);
    }

    public function test_el_codigo_solo_lo_ven_instructor_y_superadmin(): void
    {
        $ficha = $this->ficha('2896411');
        $convocatoria = $this->convocatoria($ficha);

        $conCodigo = [
            $this->usuario(Role::Instructor, null, ['ficha_id' => $ficha->id]),
            $this->usuario(Role::SuperAdmin),
        ];
        $sinCodigo = [
            $this->seleccionador($ficha),
            $this->usuario(Role::Aprendiz, 'general', ['ficha_id' => $ficha->id]),
        ];

        foreach ($conCodigo as $actor) {
            $fila = $this->actingAs($actor)->getJson('/api/seleccion/convocatorias')->assertOk()->json('data.0');
            $this->assertSame($convocatoria->codigo, $fila['codigo']);
        }

        foreach ($sinCodigo as $actor) {
            $fila = $this->actingAs($actor)->getJson('/api/seleccion/convocatorias')->assertOk()->json('data.0');
            $this->assertArrayHasKey('codigo', $fila);
            $this->assertNull($fila['codigo']);
        }
    }

    public function test_instructor_ve_solo_las_convocatorias_de_sus_fichas(): void
    {
        $fichaA = $this->ficha('2896411');
        $fichaB = $this->ficha('2631620');
        $instructor = $this->usuario(Role::Instructor, null, ['ficha_id' => $fichaA->id]);
        $mia = $this->convocatoria($fichaA);
        $ajena = $this->convocatoria($fichaB);

        $ids = collect($this->actingAs($instructor)->getJson('/api/seleccion/convocatorias')->assertOk()->json('data'))
            ->pluck('id')->all();

        $this->assertContains($mia->id, $ids);
        $this->assertNotContains($ajena->id, $ids);
    }

    public function test_superadmin_ve_convocatorias_y_candidatos_de_todas_las_fichas(): void
    {
        $fichaA = $this->ficha('2896411');
        $fichaB = $this->ficha('2631620');
        $superadmin = $this->usuario(Role::SuperAdmin);
        $a = $this->convocatoria($fichaA);
        $b = $this->convocatoria($fichaB);
        $postulacionB = $this->postulacion($b);

        $ids = collect($this->actingAs($superadmin)->getJson('/api/seleccion/convocatorias')->assertOk()->json('data'))
            ->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$a->id, $b->id], $ids);
        $this->assertSame($postulacionB->id, $this->candidatos($superadmin, $b)->assertOk()->json('data.0.id'));
    }

    public function test_el_alcance_se_aplica_antes_de_paginar(): void
    {
        $fichaA = $this->ficha('2896411');
        $fichaB = $this->ficha('2631620');
        $seleccionador = $this->seleccionador($fichaA);

        // 25 propias y 25 ajenas: si el alcance se aplicara después de paginar, la
        // primera página (20) mezclaría ajenas y los totales no coincidirían.
        foreach (range(1, 25) as $i) {
            $this->convocatoria($fichaB);
        }
        foreach (range(1, 25) as $i) {
            $this->convocatoria($fichaA);
        }

        $pagina1 = $this->actingAs($seleccionador)->getJson('/api/seleccion/convocatorias')->assertOk();
        $this->assertCount(20, $pagina1->json('data'));
        $this->assertSame(25, $pagina1->json('meta.total'));
        $this->assertSame(2, $pagina1->json('meta.last_page'));
        $this->assertSame(['Convocatoria 2896411'], collect($pagina1->json('data'))->pluck('nombre')->unique()->values()->all());

        $pagina2 = $this->actingAs($seleccionador)->getJson('/api/seleccion/convocatorias?page=2')->assertOk();
        $this->assertCount(5, $pagina2->json('data'));
    }

    // ── Candidatos ─────────────────────────────────────────────────────

    public function test_solo_se_listan_postulaciones_postuladas_con_el_contrato_minimo(): void
    {
        $ficha = $this->ficha('2896411');
        $seleccionador = $this->seleccionador($ficha);
        $convocatoria = $this->convocatoria($ficha);
        $otraConvocatoria = $this->convocatoria($ficha);

        $enviada = $this->postulacion($convocatoria, 'postulada', $this->usuario(Role::Aspirante, null, ['ficha_id' => $ficha->id, 'name' => 'Ana Pérez']));
        $this->postulacion($convocatoria, 'borrador');
        $this->postulacion($convocatoria, 'retirada');
        $this->postulacion($otraConvocatoria, 'postulada');

        $datos = $this->candidatos($seleccionador, $convocatoria)->assertOk()->json('data');

        $this->assertCount(1, $datos);
        $this->assertSame(['id', 'aspirante', 'estado_documental', 'cumplimiento'], array_keys($datos[0]));
        $this->assertSame($enviada->id, $datos[0]['id']);
        $this->assertSame('Ana Pérez', $datos[0]['aspirante']);
    }

    public function test_estados_documentales_pendiente_habilitado_no_habilitado_y_multiple(): void
    {
        $ficha = $this->ficha('2896411');
        $seleccionador = $this->seleccionador($ficha);
        $convocatoria = $this->convocatoria($ficha);

        $sinEvaluar = $this->postulacion($convocatoria);

        $habilita = $this->postulacion($convocatoria);
        $this->evaluacion($habilita, 'finalizada', 85, true);

        $noHabilita = $this->postulacion($convocatoria);
        $this->evaluacion($noHabilita, 'finalizada', 40, false);

        $coinciden = $this->postulacion($convocatoria);
        $this->evaluacion($coinciden, 'finalizada', 90, true);
        $this->evaluacion($coinciden, 'finalizada', 80, true);

        $difieren = $this->postulacion($convocatoria);
        $this->evaluacion($difieren, 'finalizada', 90, true);
        $this->evaluacion($difieren, 'finalizada', 30, false);

        $filas = $this->candidatos($seleccionador, $convocatoria)->assertOk()->json('data');
        $porId = collect($filas)->keyBy('id');

        $this->assertSame(['pendiente', null], [$porId[$sinEvaluar->id]['estado_documental'], $porId[$sinEvaluar->id]['cumplimiento']]);
        $this->assertSame(['habilitado', 85], [$porId[$habilita->id]['estado_documental'], $porId[$habilita->id]['cumplimiento']]);
        $this->assertSame(['no_habilitado', 40], [$porId[$noHabilita->id]['estado_documental'], $porId[$noHabilita->id]['cumplimiento']]);
        // Con más de un resultado no hay porcentaje, aunque coincidan.
        $this->assertSame(['requiere_revision', null], [$porId[$coinciden->id]['estado_documental'], $porId[$coinciden->id]['cumplimiento']]);
        $this->assertSame(['requiere_revision', null], [$porId[$difieren->id]['estado_documental'], $porId[$difieren->id]['cumplimiento']]);
    }

    public function test_evaluaciones_reabiertas_en_correccion_o_sin_resultado_no_cuentan(): void
    {
        $ficha = $this->ficha('2896411');
        $seleccionador = $this->seleccionador($ficha);
        $convocatoria = $this->convocatoria($ficha);

        $reabierta = $this->postulacion($convocatoria);
        $this->evaluacion($reabierta, 'en_proceso', 70, null);

        $enCorreccion = $this->postulacion($convocatoria);
        $this->evaluacion($enCorreccion, 'correccion_solicitada', 70, null);

        $finalizadaSinResultado = $this->postulacion($convocatoria);
        $this->evaluacion($finalizadaSinResultado, 'finalizada', null, null);

        // Una finalizada válida más una reabierta: cuenta una sola.
        $unaValida = $this->postulacion($convocatoria);
        $this->evaluacion($unaValida, 'finalizada', 75, true);
        $this->evaluacion($unaValida, 'en_proceso', 10, null);

        $this->assertSame('pendiente', $this->estadoDe($seleccionador, $convocatoria, $reabierta)['estado_documental']);
        $this->assertSame('pendiente', $this->estadoDe($seleccionador, $convocatoria, $enCorreccion)['estado_documental']);
        $this->assertSame('pendiente', $this->estadoDe($seleccionador, $convocatoria, $finalizadaSinResultado)['estado_documental']);

        $fila = $this->estadoDe($seleccionador, $convocatoria, $unaValida);
        $this->assertSame('habilitado', $fila['estado_documental']);
        $this->assertSame(75, $fila['cumplimiento']);
    }

    public function test_no_se_exponen_datos_sensibles_ni_detalles(): void
    {
        $ficha = $this->ficha('2896411');
        $seleccionador = $this->seleccionador($ficha);
        $convocatoria = $this->convocatoria($ficha);
        $postulacion = $this->postulacion($convocatoria);
        $this->evaluacion($postulacion, 'finalizada', 85, true);

        $texto = $this->candidatos($seleccionador, $convocatoria)->assertOk()->getContent();

        foreach (['email', 'observaciones', 'detalles', 'documentos', 'evaluador', 'perfil', 'telefono'] as $prohibido) {
            $this->assertStringNotContainsString($prohibido, $texto);
        }
    }

    public function test_el_numero_de_consultas_no_crece_con_los_candidatos(): void
    {
        $ficha = $this->ficha('2896411');
        $seleccionador = $this->seleccionador($ficha);
        $convocatoria = $this->convocatoria($ficha);

        $consultas = function () use ($seleccionador, $convocatoria): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->candidatos($seleccionador, $convocatoria)->assertOk();
            $total = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $total;
        };

        foreach (range(1, 2) as $i) {
            $this->evaluacion($this->postulacion($convocatoria), 'finalizada', 80, true);
        }
        $conDos = $consultas();

        foreach (range(1, 15) as $i) {
            $this->evaluacion($this->postulacion($convocatoria), 'finalizada', 80, true);
        }
        $conDiecisiete = $consultas();

        $this->assertSame($conDos, $conDiecisiete);
    }

    public function test_los_candidatos_se_paginan_con_orden_estable(): void
    {
        $ficha = $this->ficha('2896411');
        $seleccionador = $this->seleccionador($ficha);
        $convocatoria = $this->convocatoria($ficha);
        $creadas = collect(range(1, 23))->map(fn () => $this->postulacion($convocatoria)->id);

        $p1 = $this->candidatos($seleccionador, $convocatoria)->assertOk();
        $p2 = $this->actingAs($seleccionador)->getJson("/api/seleccion/convocatorias/{$convocatoria->id}/candidatos?page=2")->assertOk();

        $this->assertSame(23, $p1->json('meta.total'));
        $this->assertSame($creadas->all(), array_merge(
            collect($p1->json('data'))->pluck('id')->all(),
            collect($p2->json('data'))->pluck('id')->all(),
        ));
    }
}
