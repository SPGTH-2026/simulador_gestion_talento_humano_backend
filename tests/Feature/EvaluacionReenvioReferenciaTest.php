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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * La referencia documental es vigente mientras la evaluación elegida conserve su cumplimiento, resultado y fecha de
 * actualización (CU-SEL BR-05). Reenviar una evaluación idéntica no es un cambio; cualquier cambio real sí lo es, aunque
 * el cumplimiento y el puntaje total queden iguales y aunque ocurra dentro del mismo segundo que la designación.
 * El tiempo se congela para que «el mismo segundo» sea determinista.
 */
class EvaluacionReenvioReferenciaTest extends TestCase
{
    use RefreshDatabase;

    private static int $codigoSecuencia = 0;

    private Ficha $ficha;

    private User $instructor;

    private User $evaluadorA;

    private User $evaluadorB;

    private Convocatoria $convocatoria;

    private Postulacion $postulacion;

    /** @var array{hv: int, id: int} */
    private array $doc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ParametroSeeder::class);
        // Postulación y documentos existen desde antes (09:00); la prueba empieza a las 10:00.
        Carbon::setTestNow('2026-11-15 09:00:00');
        Storage::fake('local');

        $this->ficha = Ficha::create(['codigo' => '2896411', 'nombre_programa' => 'ADSO']);
        $this->instructor = $this->usuario(Role::Instructor);
        $this->evaluadorA = $this->usuario(Role::Aprendiz, 'evaluador');
        $this->evaluadorB = $this->usuario(Role::Aprendiz, 'evaluador');

        $this->convocatoria = Convocatoria::unguarded(fn () => Convocatoria::create([
            'ficha_id' => $this->ficha->id,
            'codigo' => sprintf('CONV-RRF-%03d', ++self::$codigoSecuencia),
            'nombre' => 'Convocatoria de prueba',
            'creado_por' => $this->instructor->id,
            'estado_id' => $this->parametroId('estado_convocatoria', 'publicada'),
            'tipo_convocatoria_id' => $this->parametroId('tipo_convocatoria', 'externo'),
            'tipo_empleo_id' => $this->parametroId('tipo_empleo', 'tiempo_completo'),
            'tipo_contrato_id' => $this->parametroId('tipo_contrato', 'termino_fijo'),
            'modalidad_id' => $this->parametroId('modalidad', 'presencial'),
            'vacantes' => 5,
            'fecha_apertura' => '2026-11-01',
            'fecha_cierre' => '2026-12-30',
        ]));
        $this->doc =['hv' => $this->parametroId('tipo_documento', 'hoja_de_vida'), 'id' => $this->parametroId('tipo_documento', 'documento_identidad')];
        $this->convocatoria->documentos()->attach($this->doc['hv'], ['obligatorio' => true, 'ponderacion' => 50]);
        $this->convocatoria->documentos()->attach($this->doc['id'], ['obligatorio' => true, 'ponderacion' => 50]);

        $this->postulacion = Postulacion::create([
            'convocatoria_id' => $this->convocatoria->id,
            'user_id' => $this->usuario(Role::Aspirante)->id,
            'estado_id' => $this->parametroId('estado_postulacion', 'postulada'),
            'fecha_postulacion' => now(),
        ]);
        foreach ($this->doc as $clave => $parametro) {
            $this->postulacion->documentos()->create([
                'nombre_original' => "{$clave}.pdf", 'ruta' => "postulaciones/test/{$this->postulacion->id}-{$clave}.pdf",
                'disco' => 'local', 'mime' => 'application/pdf', 'tamano_bytes' => 1024, 'parametro_id' => $parametro,
            ]);
        }
        Carbon::setTestNow('2026-11-15 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
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

    /** @param array<string,mixed> $cambios */
    private function evaluar(User $actor, array $cambios = [])
    {
        return $this->actingAs($actor)->putJson("/api/evaluaciones/{$this->postulacion->id}", array_replace([
            'estado' => 'finalizada',
            'observaciones' => 'Revisión documental completa.',
            'criterios' => [
                ['criterio' => 'Formación', 'puntaje' => 80, 'ponderacion' => 1],
                ['criterio' => 'Experiencia', 'puntaje' => 60, 'ponderacion' => 1],
            ],
            'perfil' => ['doc-'.$this->doc['hv'] => true, 'doc-'.$this->doc['id'] => true],
        ], $cambios));
    }

    private function urlReferencia(): string
    {
        return "/api/seleccion/convocatorias/{$this->convocatoria->id}/candidatos/{$this->postulacion->id}/referencia-documental";
    }

    /** @return array<string,mixed> */
    private function referencia(): array
    {
        return $this->actingAs($this->instructor)->getJson($this->urlReferencia())->assertOk()->json('data');
    }

    /** Dos evaluaciones finalizadas (A y B) y la de A designada como referencia, todo en el mismo instante. */
    private function conReferenciaVigente(array $cambiosDeA = []): Evaluacion
    {
        $this->evaluar($this->evaluadorA, $cambiosDeA)->assertOk();
        $this->evaluar($this->evaluadorB)->assertOk();
        $this->assertSame('requiere_revision', $this->referencia()['estado_documental']);

        $a = Evaluacion::where('evaluador_id', $this->evaluadorA->id)->sole();
        $this->designar($a)->assertCreated();
        $this->assertTrue($this->referencia()['referencia']['vigente']);

        return $a;
    }

    private function designar(Evaluacion $evaluacion)
    {
        return $this->actingAs($this->instructor)->postJson($this->urlReferencia(), [
            'evaluacion_id' => $evaluacion->id,
            'justificacion' => 'Se toma la evaluación del primer evaluador.',
        ]);
    }

    private function assertReferenciaVigente(): void
    {
        $r = $this->referencia();
        $this->assertTrue($r['referencia']['vigente'], 'La referencia debía seguir vigente.');
        $this->assertSame('habilitado', $r['estado_documental']);
    }

    private function assertReferenciaInvalidada(): void
    {
        $r = $this->referencia();
        $this->assertFalse($r['referencia']['vigente'], 'La referencia debía perder vigencia.');
        $this->assertSame('requiere_revision', $r['estado_documental']);
    }

    // ── Reenvío idéntico ───────────────────────────────────────────────

    public function test_reenviar_la_evaluacion_identica_conserva_la_referencia_vigente_y_no_toca_nada(): void
    {
        $a = $this->conReferenciaVigente();
        $antes = $a->fresh();

        Carbon::setTestNow('2026-11-15 10:05:00');
        $this->evaluar($this->evaluadorA)->assertOk();

        $despues = $a->fresh();
        $this->assertReferenciaVigente();
        $this->assertSame($antes->revision, $despues->revision, 'Un reenvío idéntico conserva la revisión.');
        $this->assertSame($antes->updated_at->toIso8601String(), $despues->updated_at->toIso8601String());
        $this->assertDatabaseCount('evaluaciones', 2);
        $this->assertDatabaseCount('evaluacion_detalles', 4);
    }

    public function test_reenviar_con_las_claves_del_perfil_y_los_criterios_en_otro_orden_tambien_es_identico(): void
    {
        // El orden en que llegan las claves no es contenido: MySQL normaliza el JSON y la pantalla las envía en el
        // orden de los documentos de la convocatoria.
        $this->conReferenciaVigente(['perfil' => ['doc-'.$this->doc['id'] => true, 'doc-'.$this->doc['hv'] => true]]);

        Carbon::setTestNow('2026-11-15 10:05:00');
        $this->evaluar($this->evaluadorA, [
            'perfil' => ['doc-'.$this->doc['hv'] => true, 'doc-'.$this->doc['id'] => true],
            'criterios' => [
                ['criterio' => 'Experiencia', 'puntaje' => 60, 'ponderacion' => 1],
                ['criterio' => 'Formación', 'puntaje' => 80, 'ponderacion' => 1],
            ],
        ])->assertOk();

        $this->assertReferenciaVigente();
    }

    // ── Cambios reales: invalidan, aunque cumplimiento y total no cambien ──

    public function test_un_cambio_de_criterio_con_el_mismo_puntaje_total_invalida_la_referencia(): void
    {
        $this->conReferenciaVigente();

        // Mismo instante que la designación, mismo total (70) y mismo cumplimiento: solo cambia el nombre de un criterio.
        $this->evaluar($this->evaluadorA, ['criterios' => [
            ['criterio' => 'Formación académica', 'puntaje' => 80, 'ponderacion' => 1],
            ['criterio' => 'Experiencia', 'puntaje' => 60, 'ponderacion' => 1],
        ]])->assertOk();

        $this->assertReferenciaInvalidada();
    }

    public function test_cambiar_los_puntajes_de_los_criterios_dejando_el_mismo_total_invalida_la_referencia(): void
    {
        $this->conReferenciaVigente();

        // 80/60 → 60/80: el promedio sigue siendo 70.
        $this->evaluar($this->evaluadorA, ['criterios' => [
            ['criterio' => 'Formación', 'puntaje' => 60, 'ponderacion' => 1],
            ['criterio' => 'Experiencia', 'puntaje' => 80, 'ponderacion' => 1],
        ]])->assertOk();

        $this->assertReferenciaInvalidada();
        $this->assertSame('70.00', (string) Evaluacion::where('evaluador_id', $this->evaluadorA->id)->value('puntaje_total'));
    }

    public function test_cambiar_que_documento_cumple_con_el_mismo_cumplimiento_invalida_la_referencia(): void
    {
        // Con 50 % y 50 %, cumplir uno u otro da el mismo 50 % (no habilitado): cambia el perfil, no el cumplimiento.
        $this->conReferenciaVigente(['perfil' => ['doc-'.$this->doc['hv'] => true, 'doc-'.$this->doc['id'] => false]]);
        $this->assertSame('no_habilitado', $this->referencia()['estado_documental']);

        $this->evaluar($this->evaluadorA, ['perfil' => ['doc-'.$this->doc['hv'] => false, 'doc-'.$this->doc['id'] => true]])->assertOk();

        $this->assertReferenciaInvalidada();
    }

    public function test_cambiar_solo_las_observaciones_invalida_la_referencia(): void
    {
        $this->conReferenciaVigente();

        $this->evaluar($this->evaluadorA, ['observaciones' => 'Cambio la observación.'])->assertOk();

        $this->assertReferenciaInvalidada();
    }

    public function test_un_cambio_real_en_un_segundo_posterior_tambien_invalida_la_referencia(): void
    {
        $this->conReferenciaVigente();

        Carbon::setTestNow('2026-11-15 10:00:07');
        $this->evaluar($this->evaluadorA, ['perfil' => ['doc-'.$this->doc['hv'] => true, 'doc-'.$this->doc['id'] => false]])->assertOk();

        $this->assertReferenciaInvalidada();
    }

    public function test_varios_cambios_reales_dentro_del_mismo_segundo_invalidan_cada_vez_y_se_puede_reconfirmar(): void
    {
        $a = $this->conReferenciaVigente();

        $this->evaluar($this->evaluadorA, ['observaciones' => 'Primer cambio.'])->assertOk();
        $this->assertReferenciaInvalidada();

        // Reconfirmar la referencia (misma evaluación, ya cambiada) la deja vigente otra vez...
        $this->designar($a->fresh())->assertCreated();
        $this->assertReferenciaVigente();

        // ...y otro cambio real en ese mismo segundo vuelve a invalidarla.
        $this->evaluar($this->evaluadorA, ['observaciones' => 'Segundo cambio.'])->assertOk();
        $this->assertReferenciaInvalidada();
    }

    public function test_un_reenvio_identico_de_una_evaluacion_nueva_no_la_duplica(): void
    {
        $this->evaluar($this->evaluadorA)->assertOk();
        $this->evaluar($this->evaluadorA)->assertOk();

        $this->assertDatabaseCount('evaluaciones', 1);
        $this->assertDatabaseCount('evaluacion_detalles', 2);
    }

    // ── Reemplazar un documento tras varios cambios de evaluación en el mismo segundo ──

    /** El aspirante reemplaza su hoja de vida (un obligatorio solo se reemplaza, no se elimina). */
    private function reemplazarHojaDeVida()
    {
        return $this->actingAs($this->postulacion->user)->withHeaders(['Accept' => 'application/json'])
            ->post("/api/postulaciones/{$this->postulacion->id}/documentos", [
                'codigo' => 'hoja_de_vida',
                'archivo' => UploadedFile::fake()->create('hoja_nueva.pdf', 100, 'application/pdf'),
            ]);
    }

    /** @return array<int,string> */
    private function documentosActualizados(User $evaluador): array
    {
        return $this->actingAs($evaluador)->getJson("/api/evaluaciones/{$this->postulacion->id}")->assertOk()
            ->json('data.evaluacion.documentos_actualizados');
    }

    /** Varios cambios reales de A en el mismo segundo: cada uno adelanta la fecha de actualización de su evaluación. */
    private function variosCambiosEnElMismoSegundo(): void
    {
        foreach (['Primer cambio.', 'Segundo cambio.', 'Tercer cambio.'] as $observacion) {
            $this->evaluar($this->evaluadorA, ['observaciones' => $observacion])->assertOk();
        }
    }

    public function test_reemplazar_un_documento_justo_despues_de_varios_cambios_en_el_mismo_segundo_se_detecta(): void
    {
        $a = $this->conReferenciaVigente();
        $this->variosCambiosEnElMismoSegundo();
        $this->designar($a->fresh())->assertCreated();
        $this->assertReferenciaVigente();

        // Mismo instante: el reemplazo llega después de los cambios (la fecha de A ya está adelantada).
        $this->reemplazarHojaDeVida()->assertOk();

        // Las dos evaluaciones vuelven a «en proceso» y pierden su elegibilidad.
        foreach ([$this->evaluadorA, $this->evaluadorB] as $evaluador) {
            $this->assertDatabaseHas('evaluaciones', ['evaluador_id' => $evaluador->id, 'estado' => 'en_proceso', 'pasa_seleccion' => null]);
        }
        // La referencia pierde vigencia y la postulación exige una revisión nueva.
        $r = $this->referencia();
        $this->assertFalse($r['referencia']['vigente']);
        $this->assertSame('requiere_revision', $r['estado_documental']);
        // El evaluador ve el documento como actualizado, aunque la fecha de su evaluación se hubiera adelantado.
        $this->assertSame(['doc-'.$this->doc['hv']], $this->documentosActualizados($this->evaluadorA));
    }

    /** @return array<string, array{int}> */
    public static function segundosDespues(): array
    {
        return ['un segundo después' => [1], 'diez segundos después' => [10]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('segundosDespues')]
    public function test_reemplazar_un_documento_despues_de_cambios_adelantados_tambien_se_detecta(int $segundos): void
    {
        $this->conReferenciaVigente();
        $this->variosCambiosEnElMismoSegundo(); // la fecha de A queda en 10:00:03, adelantada al reloj

        Carbon::setTestNow(Carbon::parse('2026-11-15 10:00:00')->addSeconds($segundos));
        $this->reemplazarHojaDeVida()->assertOk();

        $this->assertDatabaseHas('evaluaciones', ['evaluador_id' => $this->evaluadorA->id, 'estado' => 'en_proceso', 'pasa_seleccion' => null]);
        $this->assertFalse($this->referencia()['referencia']['vigente']);
        $this->assertSame(['doc-'.$this->doc['hv']], $this->documentosActualizados($this->evaluadorA));
    }

    public function test_tras_el_reemplazo_el_evaluador_rehace_la_evaluacion_y_se_puede_reconfirmar_la_referencia(): void
    {
        $a = $this->conReferenciaVigente();
        $this->variosCambiosEnElMismoSegundo();

        Carbon::setTestNow('2026-11-15 10:05:00');
        $this->reemplazarHojaDeVida()->assertOk();

        // Un minuto después el evaluador vuelve a finalizar: ya no quedan documentos pendientes de revisar.
        Carbon::setTestNow('2026-11-15 10:06:00');
        $this->evaluar($this->evaluadorA, ['observaciones' => 'Tercer cambio.'])->assertOk();
        $this->evaluar($this->evaluadorB)->assertOk();
        $this->assertSame([], $this->documentosActualizados($this->evaluadorA));
        $this->assertSame('requiere_revision', $this->referencia()['estado_documental']);

        $this->designar($a->fresh())->assertCreated();
        $this->assertReferenciaVigente();
    }

    // ── Revisión de la evaluación ──────────────────────────────────────

    private function revision(User $evaluador): int
    {
        return (int) Evaluacion::where('evaluador_id', $evaluador->id)->value('revision');
    }

    public function test_la_revision_nace_en_1_sube_en_1_con_cada_cambio_real_y_no_con_un_reenvio_identico(): void
    {
        $this->evaluar($this->evaluadorA)->assertOk();
        $this->assertSame(1, $this->revision($this->evaluadorA));

        $this->evaluar($this->evaluadorA)->assertOk(); // idéntico
        $this->assertSame(1, $this->revision($this->evaluadorA));

        $this->evaluar($this->evaluadorA, ['observaciones' => 'Primer cambio.'])->assertOk();
        $this->assertSame(2, $this->revision($this->evaluadorA));

        $this->evaluar($this->evaluadorA, ['observaciones' => 'Primer cambio.'])->assertOk(); // idéntico al anterior
        $this->assertSame(2, $this->revision($this->evaluadorA));

        // Tres cambios más en el mismo segundo: cada uno suma exactamente 1.
        foreach (['b', 'c', 'd'] as $i => $letra) {
            $this->evaluar($this->evaluadorA, ['observaciones' => "Cambio {$letra}."])->assertOk();
            $this->assertSame(3 + $i, $this->revision($this->evaluadorA));
        }
    }

    public function test_cada_evaluacion_invalidada_por_un_cambio_documental_suma_una_revision(): void
    {
        $this->conReferenciaVigente();
        $this->assertSame([1, 1], [$this->revision($this->evaluadorA), $this->revision($this->evaluadorB)]);

        $this->reemplazarHojaDeVida()->assertOk();

        $this->assertSame([2, 2], [$this->revision($this->evaluadorA), $this->revision($this->evaluadorB)]);

        // Una evaluación que ya no estaba finalizada no suma nada con otro reemplazo.
        $this->reemplazarHojaDeVida()->assertOk();
        $this->assertSame([2, 2], [$this->revision($this->evaluadorA), $this->revision($this->evaluadorB)]);
    }

    // ── Colisión en el mismo segundo (referencias nuevas) ──────────────

    public function test_una_invalidacion_seguida_de_una_reevaluacion_en_el_mismo_segundo_de_la_designacion_no_reactiva_la_referencia(): void
    {
        $a = $this->conReferenciaVigente(); // designada a las 10:00:00 con la evaluación de A tal como está

        // En ese mismo instante: el aspirante reemplaza un documento (la evaluación vuelve a «en proceso» y su fecha
        // retrocede un segundo) y el evaluador la rehace con EXACTAMENTE el mismo contenido que tenía al designarla.
        $this->reemplazarHojaDeVida()->assertOk();
        $this->evaluar($this->evaluadorA)->assertOk();
        $this->evaluar($this->evaluadorB)->assertOk();

        // Con la regla por fecha la referencia volvía a estar vigente por coincidencia de segundos. Con la revisión no.
        $this->assertSame($a->fresh()->updated_at->toIso8601String(), '2026-11-15T10:00:00+00:00');
        $this->assertReferenciaInvalidada();

        // Reconfirmar funciona y deja la referencia vigente sobre la revisión actual.
        $this->designar($a->fresh())->assertCreated();
        $this->assertReferenciaVigente();
    }

    public function test_reconfirmar_tras_un_cambio_real_deja_la_referencia_vigente_y_un_cambio_posterior_la_invalida_de_nuevo(): void
    {
        $a = $this->conReferenciaVigente();
        $this->evaluar($this->evaluadorA, ['observaciones' => 'Cambio uno.'])->assertOk();
        $this->assertReferenciaInvalidada();

        $this->designar($a->fresh())->assertCreated();
        $this->assertReferenciaVigente();
        $this->assertSame($this->revision($this->evaluadorA), \App\Models\ReferenciaDocumental::orderByDesc('id')->first()->evaluacion_revision);

        $this->evaluar($this->evaluadorA, ['observaciones' => 'Cambio dos.'])->assertOk();
        $this->assertReferenciaInvalidada();
    }

    // ── Referencias antiguas (relleno de la migración, sin respaldo por fecha) ──

    /** La migración se carga como objeto para ejecutar su relleno sobre referencias creadas «a la antigua». */
    private function migracionDeRevision(): object
    {
        return require database_path('migrations/2026_11_16_000001_add_revision_a_evaluaciones_y_referencias.php');
    }

    /** Referencia como existía antes de la migración: sin revisión (0 por defecto) y con la foto de la fecha. */
    private function referenciaAntigua(Evaluacion $evaluacion, ?string $fotoFecha = null): \App\Models\ReferenciaDocumental
    {
        return \App\Models\ReferenciaDocumental::create([
            'postulacion_id' => $this->postulacion->id,
            'evaluacion_id' => $evaluacion->id,
            'evaluador_id' => $evaluacion->evaluador_id,
            'designado_por' => $this->instructor->id,
            'justificacion' => 'Referencia anterior a la migración.',
            'cumplimiento' => $evaluacion->cumplimiento,
            'pasa_seleccion' => $evaluacion->pasa_seleccion,
            'evaluacion_actualizada_en' => $fotoFecha ?? $evaluacion->updated_at,
        ]);
    }

    public function test_una_referencia_antigua_vigente_sigue_vigente_hasta_el_primer_cambio_real(): void
    {
        $this->evaluar($this->evaluadorA)->assertOk();
        $this->evaluar($this->evaluadorB)->assertOk();
        $a = Evaluacion::where('evaluador_id', $this->evaluadorA->id)->sole();
        $this->referenciaAntigua($a); // foto de fecha igual a la de la evaluación: vigente con la regla de antes

        $this->migracionDeRevision()->rellenarRevisionDeReferencias();

        $this->assertSame(1, \App\Models\ReferenciaDocumental::sole()->evaluacion_revision);
        $this->assertReferenciaVigente();

        // El primer cambio real (aunque sea en el mismo segundo) la invalida.
        $this->evaluar($this->evaluadorA, ['observaciones' => 'Primer cambio tras la migración.'])->assertOk();
        $this->assertReferenciaInvalidada();
    }

    public function test_una_referencia_antigua_ya_invalida_sigue_invalida_aunque_la_fecha_vuelva_a_coincidir(): void
    {
        $this->evaluar($this->evaluadorA)->assertOk();
        $this->evaluar($this->evaluadorB)->assertOk();
        $a = Evaluacion::where('evaluador_id', $this->evaluadorA->id)->sole();
        // La foto guarda una fecha anterior: con la regla de antes ya estaba invalidada.
        $this->referenciaAntigua($a, '2026-11-15 09:59:00');

        $this->migracionDeRevision()->rellenarRevisionDeReferencias();

        $this->assertSame(0, \App\Models\ReferenciaDocumental::sole()->evaluacion_revision);
        $this->assertReferenciaInvalidada();

        // Coincidencia de segundos: la fecha de la evaluación vuelve a ser la de la foto, con el mismo cumplimiento y
        // resultado. Con el respaldo por fecha esto la reactivaba; ahora no existe ese respaldo.
        \Illuminate\Support\Facades\DB::table('evaluaciones')->where('id', $a->id)->update(['updated_at' => '2026-11-15 09:59:00']);
        $this->assertReferenciaInvalidada();

        // Reconfirmar la deja vigente con la revisión actual.
        $this->designar($a->fresh())->assertCreated();
        $this->assertReferenciaVigente();
    }

    public function test_el_relleno_deja_en_cero_la_referencia_cuya_evaluacion_ya_no_existe_o_se_reabrio(): void
    {
        $this->evaluar($this->evaluadorA)->assertOk();
        $this->evaluar($this->evaluadorB)->assertOk();
        $a = Evaluacion::where('evaluador_id', $this->evaluadorA->id)->sole();
        $b = Evaluacion::where('evaluador_id', $this->evaluadorB->id)->sole();

        $reabierta = $this->referenciaAntigua($a);
        Evaluacion::whereKey($a->id)->update(['estado' => 'en_proceso', 'pasa_seleccion' => null]);
        $huerfana = $this->referenciaAntigua($b);
        Evaluacion::whereKey($b->id)->delete(); // la fila de la referencia queda con evaluacion_id nulo

        $this->migracionDeRevision()->rellenarRevisionDeReferencias();

        $this->assertSame(0, $reabierta->fresh()->evaluacion_revision);
        $this->assertSame(0, $huerfana->fresh()->evaluacion_revision);
    }

    public function test_el_relleno_trata_un_cumplimiento_nulo_como_la_regla_anterior_y_no_como_cero(): void
    {
        $this->evaluar($this->evaluadorA)->assertOk();
        $this->evaluar($this->evaluadorB)->assertOk();
        $a = Evaluacion::where('evaluador_id', $this->evaluadorA->id)->sole();
        $b = Evaluacion::where('evaluador_id', $this->evaluadorB->id)->sole();

        // Datos antiguos: evaluaciones finalizadas con resultado (pasa_seleccion) pero SIN cumplimiento (nulo). La referencia
        // guarda su foto en 0 (la columna no admite nulo) con la misma fecha y el mismo resultado.
        \Illuminate\Support\Facades\DB::table('evaluaciones')->whereIn('id', [$a->id, $b->id])->update(['cumplimiento' => null, 'pasa_seleccion' => false]);
        \Illuminate\Support\Facades\DB::table('evaluaciones')->where('id', $b->id)->update(['cumplimiento' => 0]); // control: 0 real
        $crear = fn (Evaluacion $e) => \App\Models\ReferenciaDocumental::create([
            'postulacion_id' => $this->postulacion->id, 'evaluacion_id' => $e->id, 'evaluador_id' => $e->evaluador_id,
            'designado_por' => $this->instructor->id, 'justificacion' => 'Referencia anterior a la migración.',
            'cumplimiento' => 0, 'pasa_seleccion' => false, 'evaluacion_actualizada_en' => $e->fresh()->updated_at,
        ]);
        $conNulo = $crear($a);
        $conCero = $crear($b);

        $this->migracionDeRevision()->rellenarRevisionDeReferencias();

        // Bajo la regla anterior (null === 0 es falso) la del cumplimiento nulo ya era inválida: queda en 0.
        $this->assertSame(0, $conNulo->fresh()->evaluacion_revision);
        // La del cumplimiento 0 real sí era vigente: conserva la revisión de su evaluación.
        $this->assertSame($b->fresh()->revision, $conCero->fresh()->evaluacion_revision);
        $this->assertGreaterThan(0, $conCero->fresh()->evaluacion_revision);
    }
}
