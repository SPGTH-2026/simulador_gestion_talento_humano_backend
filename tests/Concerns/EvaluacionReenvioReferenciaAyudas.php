<?php

namespace Tests\Concerns;

use App\Enums\Role;
use App\Models\Convocatoria;
use App\Models\Evaluacion;
use App\Models\Ficha;
use App\Models\Parametro;
use App\Models\Postulacion;
use App\Models\User;
use Database\Seeders\ParametroSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

trait EvaluacionReenvioReferenciaAyudas
{
    use MientrasEditable;

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

    /** El aspirante reemplaza su hoja de vida (un obligatorio solo se reemplaza, no se elimina). */
    private function reemplazarHojaDeVida()
    {
        // Enviada la postulación no se edita: el cambio se simula con la postulación retirada (ver MientrasEditable).
        return $this->mientrasEditable($this->postulacion, fn () => $this->actingAs($this->postulacion->user)->withHeaders(['Accept' => 'application/json'])
            ->post("/api/postulaciones/{$this->postulacion->id}/documentos", [
                'codigo' => 'hoja_de_vida',
                'archivo' => UploadedFile::fake()->create('hoja_nueva.pdf', 100, 'application/pdf'),
            ]));
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

    /** @return array<string, array{int}> */
    public static function segundosDespues(): array
    {
        return ['un segundo después' => [1], 'diez segundos después' => [10]];
    }

    private function revision(User $evaluador): int
    {
        return (int) Evaluacion::where('evaluador_id', $evaluador->id)->value('revision');
    }

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
}
