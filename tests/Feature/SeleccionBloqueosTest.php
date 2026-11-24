<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Convocatoria;
use App\Models\Evaluacion;
use App\Models\Ficha;
use App\Models\Postulacion;
use App\Models\ReferenciaDocumental;
use App\Models\SeleccionCalificacion;
use App\Models\SeleccionConfiguracion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SeleccionDocumentosCompletosAyudas;
use Tests\TestCase;

/**
 * «Por qué este candidato está o no listo»: el listado de candidatos trae `listo` y `bloqueos` calculados con LA MISMA regla
 * del Ranking (evaluarCandidatos). Aquí se prueba cada bloqueo, sus combinaciones, que Ranking y candidatos coincidan, los
 * permisos, el alcance por ficha, que no viaje nada sensible y que las consultas no crezcan por candidato.
 */
class SeleccionBloqueosTest extends TestCase
{
    use RefreshDatabase;
    use SeleccionDocumentosCompletosAyudas;

    private function instructorDeLaFicha(): User
    {
        return $this->usuario(Role::Instructor);
    }

    /** @return array<int, array> filas de candidatos, indexadas por id de postulación */
    private function filas(?User $actor = null, ?Convocatoria $convocatoria = null): array
    {
        $convocatoria ??= $this->convocatoria;
        $datos = $this->actingAs(($actor ?? $this->seleccionador)->fresh())
            ->getJson("/api/seleccion/convocatorias/{$convocatoria->id}/candidatos")->assertOk()->json('data');

        return collect($datos)->keyBy('id')->all();
    }

    private function codigos(array $fila): array
    {
        return array_column($fila['bloqueos'], 'codigo');
    }

    private function conEntrevista(): void
    {
        SeleccionConfiguracion::query()->where('convocatoria_id', $this->convocatoria->id)
            ->update(['entrevista_activa' => true, 'peso_entrevista' => 100]);
    }

    private function nota(Postulacion $p, string $actividad = 'entrevista', string $nota = '8.00'): void
    {
        SeleccionCalificacion::create([
            'convocatoria_id' => $p->convocatoria_id, 'postulacion_id' => $p->id, 'actividad' => $actividad,
            'nota' => $nota, 'fecha_realizacion' => now()->toDateString(), 'registrado_por' => $this->seleccionador->id,
        ]);
    }

    public function test_un_candidato_completo_no_tiene_bloqueos_y_esta_listo(): void
    {
        $p = $this->candidato();

        $fila = $this->filas()[$p->id];
        $this->assertTrue($fila['listo']);
        $this->assertSame([], $fila['bloqueos']);
        // Los campos de siempre siguen donde estaban (contrato aditivo).
        $this->assertSame(['id', 'aspirante', 'estado_documental', 'cumplimiento'], array_slice(array_keys($fila), 0, 4));
    }

    public function test_documentos_obligatorios_faltantes_se_dicen_por_nombre_sin_archivos(): void
    {
        $p = $this->candidato(['hv']); // falta el documento de identidad

        $fila = $this->filas()[$p->id];
        $this->assertFalse($fila['listo']);
        $this->assertSame(['documentos_obligatorios'], $this->codigos($fila));
        $faltantes = $fila['bloqueos'][0]['faltantes'];
        $this->assertCount(1, $faltantes);
        $this->assertSame('documento_identidad', $faltantes[0]['codigo']);
        $this->assertSame(['codigo', 'nombre'], array_keys($faltantes[0]));
    }

    public function test_habilitacion_pendiente_y_no_habilitado(): void
    {
        $sinEvaluar = $this->candidato();
        Evaluacion::query()->where('postulacion_id', $sinEvaluar->id)->delete();
        $noHabilita = $this->candidato();
        Evaluacion::query()->where('postulacion_id', $noHabilita->id)->update(['pasa_seleccion' => false, 'cumplimiento' => 40]);

        $filas = $this->filas();
        $this->assertSame(['habilitacion_pendiente'], $this->codigos($filas[$sinEvaluar->id]));
        $this->assertSame(['no_habilitado'], $this->codigos($filas[$noHabilita->id]));
    }

    public function test_combinacion_de_bloqueos_documentos_y_habilitacion(): void
    {
        $p = $this->candidato(['hv']);
        Evaluacion::query()->where('postulacion_id', $p->id)->update(['pasa_seleccion' => false, 'cumplimiento' => 40]);

        $this->assertSame(['documentos_obligatorios', 'no_habilitado'], $this->codigos($this->filas()[$p->id]));
    }

    public function test_referencia_requerida_e_invalidada_solo_se_distinguen_para_quien_ve_ese_detalle(): void
    {
        $p = $this->candidato();
        $segunda = Evaluacion::create([
            'postulacion_id' => $p->id, 'evaluador_id' => $this->usuario(Role::Aprendiz, 'evaluador')->id,
            'estado' => 'finalizada', 'cumplimiento' => 90, 'pasa_seleccion' => true,
        ]);
        $instructor = $this->instructorDeLaFicha();

        // Varias evaluaciones sin referencia designada.
        $this->assertSame(['referencia_requerida'], $this->codigos($this->filas($instructor)[$p->id]));
        $this->assertSame(['revision_documental'], $this->codigos($this->filas()[$p->id]), 'el seleccionador recibe la explicación general');

        // Con referencia designada y vigente, el candidato queda listo.
        $ref = ReferenciaDocumental::unguarded(fn () => ReferenciaDocumental::create([
            'postulacion_id' => $p->id, 'evaluacion_id' => $segunda->id, 'evaluador_id' => $segunda->evaluador_id,
            'designado_por' => $instructor->id, 'justificacion' => 'El segundo evaluador revisó el soporte completo.',
            'cumplimiento' => 90, 'pasa_seleccion' => true, 'evaluacion_revision' => $segunda->refresh()->revision, 'evaluacion_actualizada_en' => now(),
        ]));
        $this->assertTrue($this->filas()[$p->id]['listo']);

        // Si la evaluación elegida cambia, la referencia queda invalidada.
        $segunda->update(['cumplimiento' => 70]);
        $this->assertSame(['referencia_invalidada'], $this->codigos($this->filas($instructor)[$p->id]));
        $this->assertSame(['revision_documental'], $this->codigos($this->filas()[$p->id]));
        $this->assertNotNull($ref);
    }

    public function test_notas_pendientes_indican_la_actividad_y_desaparecen_al_calificar(): void
    {
        $this->conEntrevista();
        $p = $this->candidato();

        $fila = $this->filas()[$p->id];
        $this->assertSame(['notas_pendientes'], $this->codigos($fila));
        $this->assertSame(['entrevista'], $fila['bloqueos'][0]['actividades']);

        $this->nota($p);
        $this->assertTrue($this->filas()[$p->id]['listo']);
    }

    public function test_configuracion_pendiente_bloquea_a_todos(): void
    {
        $p = $this->candidato();
        SeleccionConfiguracion::query()->where('convocatoria_id', $this->convocatoria->id)->delete();

        $this->assertSame(['configuracion_pendiente'], $this->codigos($this->filas()[$p->id]));
    }

    /** @return array<int, Postulacion> */
    private function mezcla(): array
    {
        $listo = $this->candidato();
        $sinDoc = $this->candidato(['hv']);
        $sinEvaluar = $this->candidato();
        Evaluacion::query()->where('postulacion_id', $sinEvaluar->id)->delete();
        $noHabilita = $this->candidato();
        Evaluacion::query()->where('postulacion_id', $noHabilita->id)->update(['pasa_seleccion' => false]);

        return [$listo, $sinDoc, $sinEvaluar, $noHabilita];
    }

    public function test_candidatos_y_ranking_dan_el_mismo_resultado_sin_actividades(): void
    {
        $this->mezcla();

        $this->assertCoincideConRanking();
    }

    public function test_candidatos_y_ranking_dan_el_mismo_resultado_con_entrevista(): void
    {
        $this->conEntrevista();
        [$listo, $sinDoc, $sinEvaluar] = $this->mezcla();
        $sinNota = $this->candidato();
        foreach ([$listo, $sinDoc, $sinEvaluar] as $p) {
            $this->nota($p);
        }

        $this->assertCoincideConRanking();
        $this->assertSame(['notas_pendientes'], $this->codigos($this->filas()[$sinNota->id]));
    }

    private function assertCoincideConRanking(): void
    {
        $filas = $this->filas();
        $ranking = $this->ranking();
        $listosRanking = $this->listos();

        $this->assertNotEmpty($filas);
        foreach ($filas as $id => $fila) {
            $this->assertSame(in_array($id, $listosRanking, true), $fila['listo'], "Ranking y candidatos discrepan en {$id}");
            $this->assertSame($fila['listo'], $fila['bloqueos'] === [], 'listo es exactamente «sin bloqueos»');
        }
        $this->assertSame(count($listosRanking), count(array_filter($filas, fn ($f) => $f['listo'])));
        $this->assertSame(count($filas) - count($listosRanking), $ranking->json('meta.no_listos'));
    }

    public function test_sin_permiso_de_seleccion_no_hay_acceso(): void
    {
        $this->candidato();
        $evaluador = $this->usuario(Role::Aprendiz, 'evaluador');

        $this->actingAs($evaluador->fresh())
            ->getJson("/api/seleccion/convocatorias/{$this->convocatoria->id}/candidatos")->assertForbidden();
    }

    public function test_el_alcance_por_ficha_se_respeta_y_no_filtra_bloqueos_ajenos(): void
    {
        $this->candidato(['hv']);
        $otraFicha = Ficha::create(['codigo' => '2631620', 'nombre_programa' => 'Otra']);
        $ajeno = User::factory()->create([
            'role' => Role::Aprendiz, 'subrole' => 'seleccionador', 'active' => true,
            'email_verified_at' => now(), 'ficha_id' => $otraFicha->id,
        ]);

        $this->actingAs($ajeno)
            ->getJson("/api/seleccion/convocatorias/{$this->convocatoria->id}/candidatos")->assertNotFound();
    }

    public function test_no_viaja_nada_sensible(): void
    {
        $this->candidato(['hv']);
        $this->candidato();

        $json = $this->actingAs($this->seleccionador->fresh())
            ->getJson("/api/seleccion/convocatorias/{$this->convocatoria->id}/candidatos")->assertOk()->getContent();

        foreach (['ruta', '.pdf', 'disco', 'mime', 'observaciones', 'evaluador', 'email', 'justificacion'] as $prohibido) {
            $this->assertStringNotContainsString($prohibido, $json);
        }
    }

    public function test_las_consultas_no_crecen_por_candidato(): void
    {
        $contar = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->filas($this->instructorDeLaFicha());
            $total = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $total;
        };

        $this->conEntrevista();
        $this->candidato();
        $this->candidato(['hv']);
        $conDos = $contar();

        foreach (range(1, 6) as $i) {
            $p = $this->candidato($i % 2 ? ['hv', 'id'] : ['hv']);
            if ($i % 3 === 0) {
                $this->nota($p);
            }
        }
        $conOcho = $contar();

        $this->assertSame($conDos, $conOcho, 'El listado de candidatos no debe hacer consultas por candidato.');
    }
}
