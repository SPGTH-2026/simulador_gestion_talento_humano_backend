<?php

namespace App\Tests\Feature;

use App\Enums\Role;
use App\Models\Convocatoria;
use App\Models\Evaluacion;
use App\Models\Ficha;
use App\Models\Parametro;
use App\Models\Postulacion;
use App\Models\PerfilAspirante;
use App\Models\SeleccionConfiguracion;
use App\Models\User;
use Database\Seeders\ParametroSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Una postulación a la que le falta un obligatorio aplicable no es candidata lista, aunque su evaluación diga «Cumple»
 * (caso legado: evaluaciones anteriores a RF-DOC-006, cuando se podía eliminar un obligatorio). El bloqueo vive en el
 * cálculo de «listo» del Ranking, así que cubre el Ranking, la confirmación de finalistas y las decisiones nuevas, y se
 * calcula en bloque. Las decisiones registradas, su historial y la rectificación que libera un cupo se conservan.
 */
class SeleccionDocumentosCompletosTest extends TestCase
{
    use RefreshDatabase;

    private static int $codigoSecuencia = 0;

    private Ficha $ficha;

    private User $seleccionador;

    private Convocatoria $convocatoria;

    /** @var array{hv: int, id: int, tp: int, libreta: int} */
    private array $doc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ParametroSeeder::class);

        $this->ficha = Ficha::create(['codigo' => '2896411', 'nombre_programa' => 'ADSO']);
        $this->seleccionador = $this->usuario(Role::Aprendiz, 'seleccionador');
        $this->doc = [
            'hv' => $this->parametroId('tipo_documento', 'hoja_de_vida'),
            'id' => $this->parametroId('tipo_documento', 'documento_identidad'),
            'tp' => $this->parametroId('tipo_documento', 'tarjeta_profesional'),
            'libreta' => $this->parametroId('tipo_documento', 'libreta_militar'),
        ];
        $this->convocatoria = $this->crearConvocatoria(2);
    }

    private function usuario(Role $role, ?string $subrole = null): User
    {
        return User::factory()->create([
            'role' => $role, 'subrole' => $subrole, 'active' => true, 'email_verified_at' => now(),
            'ficha_id' => $this->ficha->id ?? null,
        ]);
    }

    private function parametroId(string $tema, string $codigo): int
    {
        return (int) Parametro::porTemaYCodigo($tema, $codigo)->id;
    }

    /** En proceso, solo documental, hoja de vida y documento de identidad obligatorios, tarjeta profesional opcional. */
    private function crearConvocatoria(int $vacantes, bool $conLibreta = false): Convocatoria
    {
        $convocatoria = Convocatoria::unguarded(fn () => Convocatoria::create([
            'ficha_id' => $this->ficha->id,
            'codigo' => sprintf('CONV-DOC-%03d', ++self::$codigoSecuencia),
            'nombre' => 'Convocatoria de prueba',
            'creado_por' => $this->usuario(Role::Instructor)->id,
            'estado_id' => $this->parametroId('estado_convocatoria', 'en_proceso'),
            'tipo_convocatoria_id' => $this->parametroId('tipo_convocatoria', 'externo'),
            'tipo_empleo_id' => $this->parametroId('tipo_empleo', 'tiempo_completo'),
            'tipo_contrato_id' => $this->parametroId('tipo_contrato', 'termino_fijo'),
            'modalidad_id' => $this->parametroId('modalidad', 'presencial'),
            'vacantes' => $vacantes,
        ]));
        $convocatoria->documentos()->attach($this->doc['hv'], ['obligatorio' => true, 'ponderacion' => 60]);
        $convocatoria->documentos()->attach($this->doc['id'], ['obligatorio' => true, 'ponderacion' => $conLibreta ? 20 : 40]);
        $convocatoria->documentos()->attach($this->doc['tp'], ['obligatorio' => false, 'ponderacion' => null]);
        if ($conLibreta) {
            $convocatoria->documentos()->attach($this->doc['libreta'], ['obligatorio' => true, 'ponderacion' => 20]);
        }
        SeleccionConfiguracion::create(['convocatoria_id' => $convocatoria->id, 'entrevista_activa' => false, 'prueba_tecnica_activa' => false]);

        return $convocatoria;
    }

    /**
     * Postulada con una evaluación finalizada que la habilita. $subidos: claves de $this->doc que SÍ tiene cargadas.
     *
     * @param  array<int,string>  $subidos
     */
    private function candidato(array $subidos = ['hv', 'id'], ?string $sexo = null, ?Convocatoria $convocatoria = null): Postulacion
    {
        $convocatoria ??= $this->convocatoria;
        $aspirante = $this->usuario(Role::Aspirante);
        if ($sexo !== null) {
            PerfilAspirante::create(['user_id' => $aspirante->id, 'sexo_id' => $this->parametroId('sexo', $sexo)]);
        }
        $postulacion = Postulacion::create([
            'convocatoria_id' => $convocatoria->id,
            'user_id' => $aspirante->id,
            'estado_id' => $this->parametroId('estado_postulacion', 'postulada'),
            'fecha_postulacion' => now(),
        ]);
        foreach ($subidos as $clave) {
            $postulacion->documentos()->create([
                'nombre_original' => "{$clave}.pdf", 'ruta' => "postulaciones/test/{$postulacion->id}-{$clave}.pdf",
                'disco' => 'local', 'mime' => 'application/pdf', 'tamano_bytes' => 1024, 'parametro_id' => $this->doc[$clave],
            ]);
        }
        Evaluacion::create([
            'postulacion_id' => $postulacion->id,
            'evaluador_id' => $this->usuario(Role::Aprendiz, 'evaluador')->id,
            'estado' => 'finalizada', 'cumplimiento' => 100, 'pasa_seleccion' => true,
        ]);

        return $postulacion;
    }

    /** Estado legado: se le quita un obligatorio a una postulación ya evaluada (antes de RF-DOC-006 se podía). */
    private function quitarDocumento(Postulacion $postulacion, string $clave): void
    {
        $postulacion->documentos()->where('parametro_id', $this->doc[$clave])->delete();
    }

    private function ranking(?Convocatoria $convocatoria = null)
    {
        $convocatoria ??= $this->convocatoria;

        return $this->actingAs($this->seleccionador)->getJson("/api/seleccion/convocatorias/{$convocatoria->id}/ranking")->assertOk();
    }

    /** @return array<int,int> ids de las postulaciones listas */
    private function listos(?Convocatoria $convocatoria = null): array
    {
        return array_map(fn ($f) => $f['postulacion_id'], $this->ranking($convocatoria)->json('data'));
    }

    private function confirmar(array $postulaciones, ?Convocatoria $convocatoria = null)
    {
        $convocatoria ??= $this->convocatoria;

        return $this->actingAs($this->seleccionador)->postJson("/api/seleccion/convocatorias/{$convocatoria->id}/finalistas", [
            'version' => $this->ranking($convocatoria)->json('meta.version'),
            'postulacion_ids' => array_map(fn (Postulacion $p) => $p->id, $postulaciones),
            'justificacion' => 'Elección manual de la convocatoria solo documental.',
        ]);
    }

    private function versionLista(): int
    {
        return (int) $this->actingAs($this->seleccionador)
            ->getJson("/api/seleccion/convocatorias/{$this->convocatoria->id}/finalistas")->assertOk()->json('data.actual.version');
    }

    private function decidir(Postulacion $postulacion, string $resultado, int $version, string $motivo = 'Decisión de prueba del seleccionador.')
    {
        return $this->actingAs($this->seleccionador)->putJson(
            "/api/seleccion/convocatorias/{$this->convocatoria->id}/decisiones/{$postulacion->id}",
            ['resultado' => $resultado, 'justificacion' => $motivo, 'lista_version' => $version],
        );
    }

    // ── Ranking ────────────────────────────────────────────────────────

    public function test_una_postulacion_incompleta_con_evaluacion_finalizada_no_aparece_como_lista(): void
    {
        $completa = $this->candidato();
        $incompleta = $this->candidato(['hv']); // le falta el documento de identidad, pero su evaluación dice «Cumple»

        $respuesta = $this->ranking();
        $this->assertSame([$completa->id], $this->listos());
        $respuesta->assertJsonPath('meta.listos', 1)->assertJsonPath('meta.no_listos', 1);
        $this->assertNotContains($incompleta->id, $this->listos());
    }

    public function test_un_opcional_ausente_no_bloquea(): void
    {
        $sinOpcional = $this->candidato(['hv', 'id']); // sin tarjeta profesional (opcional)
        $conOpcional = $this->candidato(['hv', 'id', 'tp']);

        $this->assertEqualsCanonicalizing([$sinOpcional->id, $conOpcional->id], $this->listos());
    }

    public function test_un_documento_que_no_aplica_no_bloquea_pero_a_un_hombre_si_se_le_exige(): void
    {
        $convocatoria = $this->crearConvocatoria(2, conLibreta: true);
        $mujer = $this->candidato(['hv', 'id'], 'mujer', $convocatoria); // la libreta no le aplica
        $hombreSinLibreta = $this->candidato(['hv', 'id'], 'hombre', $convocatoria);
        $hombreCompleto = $this->candidato(['hv', 'id', 'libreta'], 'hombre', $convocatoria);

        $this->assertEqualsCanonicalizing([$mujer->id, $hombreCompleto->id], $this->listos($convocatoria));
        $this->assertNotContains($hombreSinLibreta->id, $this->listos($convocatoria));
    }

    public function test_la_validacion_de_documentos_se_hace_en_bloque_sin_consultas_por_candidato(): void
    {
        $contar = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->ranking();
            $total = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $total;
        };

        $this->candidato();
        $this->candidato(['hv']);
        $conDos = $contar();

        foreach (range(1, 6) as $i) {
            $this->candidato($i % 2 ? ['hv', 'id'] : ['hv']);
        }
        $conOcho = $contar();

        $this->assertSame($conDos, $conOcho, 'El Ranking no debe hacer consultas por candidato para validar los documentos.');
    }

    // ── Finalistas y decisiones ────────────────────────────────────────

    public function test_no_puede_confirmarse_como_finalista_una_postulacion_incompleta(): void
    {
        $completa = $this->candidato();
        $incompleta = $this->candidato(['hv']);

        $this->confirmar([$incompleta])->assertStatus(422)
            ->assertJsonPath('errors.postulacion_ids.0', 'Solo pueden ser finalistas los candidatos listos: habilitados documentalmente y con todas las actividades calificadas.');
        $this->confirmar([$completa, $incompleta])->assertStatus(422);
        $this->assertDatabaseCount('seleccion_finalistas_listas', 0);

        $this->confirmar([$completa])->assertCreated();
    }

    public function test_una_postulacion_antigua_incompleta_no_puede_recibir_una_decision_de_seleccionado(): void
    {
        $completa = $this->candidato();
        $legada = $this->candidato();
        $this->confirmar([$completa, $legada])->assertCreated();
        $version = $this->versionLista();
        $this->quitarDocumento($legada, 'id'); // pierde un obligatorio después de estar en la lista

        // La lista pasa a «requiere revisión»: nadie recibe una decisión nueva de «Seleccionado» hasta confirmar otra versión.
        $this->decidir($legada, 'seleccionado', $version)->assertStatus(409)->assertJsonPath('codigo', 'lista_requiere_revision');
        $this->decidir($completa, 'seleccionado', $version)->assertStatus(409)->assertJsonPath('codigo', 'lista_requiere_revision');
        $this->assertDatabaseCount('seleccion_decisiones', 0);

        // Y una nueva versión no puede volver a incluirla.
        $this->confirmar([$completa, $legada])->assertStatus(422);
        $this->confirmar([$completa])->assertCreated();
        $nueva = $this->versionLista();
        $this->decidir($legada, 'seleccionado', $nueva)->assertStatus(422)
            ->assertJsonPath('errors.postulacion.0', 'El candidato no pertenece a la lista de finalistas confirmada.');
        $this->decidir($completa, 'seleccionado', $nueva)->assertCreated();
    }

    public function test_si_ya_estaba_en_la_lista_aparece_como_ya_no_elegible(): void
    {
        $completa = $this->candidato();
        $legada = $this->candidato();
        $this->confirmar([$completa, $legada])->assertCreated();
        $this->quitarDocumento($legada, 'hv');

        $finalistas = $this->actingAs($this->seleccionador)
            ->getJson("/api/seleccion/convocatorias/{$this->convocatoria->id}/finalistas")->assertOk();
        $finalistas->assertJsonPath('data.actual.requiere_revision', true);
        $this->assertEquals(
            [['postulacion_id' => $legada->id, 'motivo' => 'ya_no_elegible']],
            $finalistas->json('data.actual.revisar'),
        );
    }

    public function test_se_conservan_la_decision_su_historial_y_la_rectificacion_que_libera_el_cupo(): void
    {
        $completa = $this->candidato();
        $legada = $this->candidato();
        $this->confirmar([$completa, $legada])->assertCreated();
        $version = $this->versionLista();

        // Decisión registrada cuando la postulación todavía estaba completa; después queda incompleta.
        $this->decidir($legada, 'seleccionado', $version)->assertCreated();
        $this->quitarDocumento($legada, 'id');

        $indice = $this->actingAs($this->seleccionador)
            ->getJson("/api/seleccion/convocatorias/{$this->convocatoria->id}/decisiones")->assertOk();
        $fila = collect($indice->json('data'))->firstWhere('postulacion_id', $legada->id);
        $this->assertSame('seleccionado', $fila['estado']);
        $this->assertSame('ya_no_elegible', $fila['motivo_revision']); // el Instructor la ve para revisarla
        $this->assertTrue($fila['requiere_revision']);
        $this->assertSame(1, $indice->json('meta.cupos.cupos_ocupados')); // sigue ocupando su cupo
        $this->assertDatabaseCount('seleccion_decisiones', 1);
        $this->assertDatabaseCount('seleccion_decision_eventos', 1);

        // La rectificación que libera el cupo sigue permitida, y deja su evento en el historial.
        $this->decidir($legada, 'no_seleccionado', $version, 'Se libera el cupo: la postulación quedó incompleta.')
            ->assertOk()->assertJsonPath('data.cupos.cupos_ocupados', 0)->assertJsonPath('data.cupos.cupos_disponibles', 2);
        $this->assertDatabaseCount('seleccion_decisiones', 1);
        $this->assertDatabaseCount('seleccion_decision_eventos', 2);

        $historial = $this->actingAs($this->seleccionador)
            ->getJson("/api/seleccion/convocatorias/{$this->convocatoria->id}/decisiones/{$legada->id}/historial")->assertOk();
        $this->assertSame(['no_seleccionado', 'seleccionado'], array_column($historial->json('data'), 'resultado_nuevo'));
    }

    public function test_al_completar_de_nuevo_el_obligatorio_vuelve_a_ser_lista(): void
    {
        $legada = $this->candidato(['hv']);
        $this->assertSame([], $this->listos());

        // Reponer el archivo (es lo que haría el aspirante tras la corrección) la devuelve al Ranking.
        $legada->documentos()->create([
            'nombre_original' => 'id.pdf', 'ruta' => "postulaciones/test/{$legada->id}-id.pdf", 'disco' => 'local',
            'mime' => 'application/pdf', 'tamano_bytes' => 1024, 'parametro_id' => $this->doc['id'],
        ]);
        $this->assertSame([$legada->id], $this->listos());
    }
}
