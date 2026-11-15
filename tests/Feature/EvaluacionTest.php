<?php

namespace App\Tests\Feature;

use App\Enums\Role;
use App\Models\Convocatoria;
use App\Models\Evaluacion;
use App\Models\Ficha;
use App\Models\Parametro;
use App\Models\ParametroTema;
use App\Models\PerfilAspirante;
use App\Models\Postulacion;
use App\Models\User;
use Database\Seeders\ParametroSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EvaluacionTest extends TestCase
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

    private function convocatoria(Ficha $ficha, ?User $creador = null, string $estado = 'publicada'): Convocatoria
    {
        $creador ??= $this->usuario(Role::Instructor, null, ['ficha_id' => $ficha->id]);

        return Convocatoria::unguarded(fn () => Convocatoria::create([
            'ficha_id' => $ficha->id,
            'codigo' => sprintf('CONV-TEST-%03d', ++self::$codigoSecuencia),
            'nombre' => 'Convocatoria '.$ficha->codigo,
            'creado_por' => $creador->id,
            'estado_id' => $this->parametroId('estado_convocatoria', $estado),
            'tipo_convocatoria_id' => $this->parametroId('tipo_convocatoria', 'externo'),
            'tipo_empleo_id' => $this->parametroId('tipo_empleo', 'tiempo_completo'),
            'tipo_contrato_id' => $this->parametroId('tipo_contrato', 'termino_fijo'),
            'modalidad_id' => $this->parametroId('modalidad', 'presencial'),
            'vacantes' => 5,
        ]));
    }

    private function postulacion(Convocatoria $convocatoria, ?User $aspirante = null): Postulacion
    {
        $aspirante ??= $this->usuario(Role::Aspirante, null, ['ficha_id' => $convocatoria->ficha_id]);

        return Postulacion::create([
            'convocatoria_id' => $convocatoria->id,
            'user_id' => $aspirante->id,
            'estado_id' => $this->parametroId('estado_postulacion', 'postulada'),
            'fecha_postulacion' => now(),
        ]);
    }

    /** El aspirante ya subió todos los documentos obligatorios de la convocatoria (la evaluación exige archivos cargados). */
    private function conDocumentosCargados(Postulacion $postulacion): Postulacion
    {
        $postulacion->convocatoria->load('documentos');
        foreach ($postulacion->convocatoria->documentos as $documento) {
            if ($documento->pivot->obligatorio) {
                $postulacion->documentos()->create([
                    'nombre_original' => 'archivo.pdf',
                    'ruta' => "postulaciones/test/{$postulacion->id}-{$documento->id}.pdf",
                    'disco' => 'local',
                    'mime' => 'application/pdf',
                    'tamano_bytes' => 1024,
                    'parametro_id' => $documento->id,
                ]);
            }
        }

        return $postulacion;
    }

    /** @param array<int,array<string,mixed>> $criterios */
    private function criterios(array $criterios = []): array
    {
        return $criterios ?: [
            ['criterio' => 'Experiencia', 'puntaje' => 80, 'ponderacion' => 2],
            ['criterio' => 'Prueba técnica', 'puntaje' => 60, 'ponderacion' => 1],
        ];
    }

    // ── Acceso ─────────────────────────────────────────────────────────

    public function test_invitado_recibe_401(): void
    {
        $this->getJson('/api/evaluaciones')->assertUnauthorized();
    }

    public function test_aspirante_sin_permiso_recibe_403(): void
    {
        $aspirante = $this->usuario(Role::Aspirante);
        $this->actingAs($aspirante)->getJson('/api/evaluaciones')->assertForbidden();
    }

    // ── Listado ────────────────────────────────────────────────────────

    public function test_evaluador_ve_solo_postulaciones_de_su_ficha(): void
    {
        $fichaA = $this->ficha('2896411');
        $fichaB = $this->ficha('2631620');

        $evaluador = $this->usuario(Role::Aprendiz, 'evaluador', ['ficha_id' => $fichaA->id]);
        $mias = $this->postulacion($this->convocatoria($fichaA));
        $ajenas = $this->postulacion($this->convocatoria($fichaB));

        $ids = collect($this->actingAs($evaluador)->getJson('/api/evaluaciones')->json('data'))
            ->pluck('id')->all();

        $this->assertContains($mias->id, $ids);
        $this->assertNotContains($ajenas->id, $ids);
    }

    public function test_evaluador_no_ve_la_evaluacion_de_otro_evaluador(): void
    {
        $ficha = $this->ficha('2896411');
        $yo = $this->usuario(Role::Aprendiz, 'evaluador', ['ficha_id' => $ficha->id]);
        $otro = $this->usuario(Role::Aprendiz, 'evaluador', ['ficha_id' => $ficha->id]);
        $postulacion = $this->postulacion($this->convocatoria($ficha));

        Evaluacion::create([
            'postulacion_id' => $postulacion->id,
            'evaluador_id' => $otro->id,
            'puntaje_total' => 90,
            'estado' => 'finalizada',
        ]);

        $this->actingAs($yo)->getJson('/api/evaluaciones')
            ->assertOk()
            ->assertJsonPath('data.0.evaluacion', null);
    }

    // ── Registrar evaluación ───────────────────────────────────────────

    public function test_el_instructor_ve_la_evaluacion_del_aprendiz_evaluador(): void
    {
        $ficha = $this->ficha('2896411');
        $instructor = $this->usuario(Role::Instructor, null, ['ficha_id' => $ficha->id]);
        $evaluador = $this->usuario(Role::Aprendiz, 'evaluador', ['ficha_id' => $ficha->id]);
        $postulacion = $this->postulacion($this->convocatoria($ficha, $instructor));

        $evaluacion = Evaluacion::create([
            'postulacion_id' => $postulacion->id,
            'evaluador_id' => $evaluador->id,
            'puntaje_total' => 90,
            'estado' => 'finalizada',
        ]);

        // El Instructor actúa como admin: en el listado de trabajo y en el
        // detalle ve la evaluación que ya realizó el aprendiz con rol evaluador.
        $this->actingAs($instructor)->getJson('/api/evaluaciones')
            ->assertOk()
            ->assertJsonPath('data.0.evaluacion.id', $evaluacion->id)
            ->assertJsonPath('data.0.evaluacion.evaluador_id', $evaluador->id)
            ->assertJsonPath('data.0.evaluacion.es_propia', false);

        $this->actingAs($instructor)->getJson("/api/evaluaciones/{$postulacion->id}")
            ->assertOk()
            ->assertJsonPath('data.evaluacion.id', $evaluacion->id)
            ->assertJsonPath('data.evaluacion.evaluador_id', $evaluador->id)
            ->assertJsonPath('data.evaluacion.es_propia', false);

        // El evaluador reconoce la misma evaluación como propia.
        $this->actingAs($evaluador)->getJson("/api/evaluaciones/{$postulacion->id}")
            ->assertOk()
            ->assertJsonPath('data.evaluacion.id', $evaluacion->id)
            ->assertJsonPath('data.evaluacion.es_propia', true);
    }

    public function test_el_super_admin_ve_la_evaluacion_del_aprendiz_evaluador(): void
    {
        $ficha = $this->ficha('2896411');
        $superAdmin = $this->usuario(Role::SuperAdmin);
        $evaluador = $this->usuario(Role::Aprendiz, 'evaluador', ['ficha_id' => $ficha->id]);
        $postulacion = $this->postulacion($this->convocatoria($ficha));

        $evaluacion = Evaluacion::create([
            'postulacion_id' => $postulacion->id,
            'evaluador_id' => $evaluador->id,
            'puntaje_total' => 80,
            'estado' => 'finalizada',
        ]);

        // El super admin ve el trabajo de los evaluadores en el listado y en
        // "Mis evaluaciones", sin pertenecer a la ficha del aspirante.
        $this->actingAs($superAdmin)->getJson('/api/evaluaciones')
            ->assertOk()
            ->assertJsonPath('data.0.evaluacion.id', $evaluacion->id)
            ->assertJsonPath('data.0.evaluacion.evaluador_id', $evaluador->id);

        $this->actingAs($superAdmin)->getJson('/api/mis-evaluaciones')
            ->assertOk()
            ->assertJsonPath('data.0.id', $evaluacion->id)
            ->assertJsonPath('data.0.evaluador.id', $evaluador->id);
    }

    public function test_la_evaluacion_del_evaluador_prevalece_sobre_la_del_admin(): void
    {
        $ficha = $this->ficha('2896411');
        $instructor = $this->usuario(Role::Instructor, null, ['ficha_id' => $ficha->id]);
        $superAdmin = $this->usuario(Role::SuperAdmin);
        $evaluador = $this->usuario(Role::Aprendiz, 'evaluador', ['ficha_id' => $ficha->id]);
        $postulacion = $this->postulacion($this->convocatoria($ficha, $instructor));

        // El admin ya tenía una evaluación finalizada...
        Evaluacion::create([
            'postulacion_id' => $postulacion->id,
            'evaluador_id' => $instructor->id,
            'puntaje_total' => 100,
            'estado' => 'finalizada',
        ]);

        // ...pero el evaluador tiene un borrador más reciente. El admin y el
        // super admin deben ver el trabajo del evaluador, no la evaluación
        // propia del admin.
        $delEvaluador = Evaluacion::create([
            'postulacion_id' => $postulacion->id,
            'evaluador_id' => $evaluador->id,
            'puntaje_total' => 50,
            'estado' => 'en_proceso',
        ]);

        $this->actingAs($instructor)->getJson('/api/evaluaciones')
            ->assertOk()
            ->assertJsonPath('data.0.evaluacion.id', $delEvaluador->id)
            ->assertJsonPath('data.0.evaluacion.evaluador_id', $evaluador->id);

        $this->actingAs($instructor)->getJson("/api/evaluaciones/{$postulacion->id}")
            ->assertOk()
            ->assertJsonPath('data.evaluacion.id', $delEvaluador->id);

        $this->actingAs($superAdmin)->getJson('/api/evaluaciones')
            ->assertOk()
            ->assertJsonPath('data.0.evaluacion.id', $delEvaluador->id)
            ->assertJsonPath('data.0.evaluacion.evaluador_id', $evaluador->id);
    }

    public function test_el_instructor_edita_la_evaluacion_del_aprendiz_sin_duplicarla(): void
    {
        $ficha = $this->ficha('2896411');
        $instructor = $this->usuario(Role::Instructor, null, ['ficha_id' => $ficha->id]);
        $evaluador = $this->usuario(Role::Aprendiz, 'evaluador', ['ficha_id' => $ficha->id]);
        $convocatoria = $this->convocatoria($ficha, $instructor);
        $ids = $this->documentosConPeso($convocatoria, [
            'hoja_de_vida' => 50,
            'documento_identidad' => 50,
        ]);
        $postulacion = $this->conDocumentosCargados($this->postulacion($convocatoria));

        // El aprendiz evaluador finaliza con el 100 %.
        $this->actingAs($evaluador)->putJson("/api/evaluaciones/{$postulacion->id}", [
            'estado' => 'finalizada',
            'criterios' => [['criterio' => 'Evaluación', 'puntaje' => 100, 'ponderacion' => 1]],
            'perfil' => [
                'doc-'.$ids['hoja_de_vida'] => true,
                'doc-'.$ids['documento_identidad'] => true,
            ],
        ])->assertOk()->assertJsonPath('data.evaluacion.cumplimiento', 100);

        // El Instructor corrige esa misma evaluación como administrador.
        $this->actingAs($instructor)->putJson("/api/evaluaciones/{$postulacion->id}", [
            'estado' => 'finalizada',
            'criterios' => [['criterio' => 'Evaluación', 'puntaje' => 50, 'ponderacion' => 1]],
            'perfil' => [
                'doc-'.$ids['hoja_de_vida'] => true,
                'doc-'.$ids['documento_identidad'] => false,
            ],
        ])->assertOk()
            ->assertJsonPath('data.evaluacion.cumplimiento', 50)
            ->assertJsonPath('data.evaluacion.pasa_seleccion', false)
            ->assertJsonPath('data.evaluacion.evaluador_id', $evaluador->id);

        // Sigue existiendo una sola fila y conserva al evaluador original: el
        // Instructor administra la evaluación, no crea una paralela.
        $this->assertDatabaseCount('evaluaciones', 1);
        $this->assertDatabaseHas('evaluaciones', [
            'postulacion_id' => $postulacion->id,
            'evaluador_id' => $evaluador->id,
            'cumplimiento' => 50,
            'pasa_seleccion' => false,
        ]);
    }

    public function test_evaluador_registra_evaluacion_y_calcula_promedio_ponderado(): void
    {
        $ficha = $this->ficha('2896411');
        $evaluador = $this->usuario(Role::Aprendiz, 'evaluador', ['ficha_id' => $ficha->id]);
        $convocatoria = $this->convocatoria($ficha);
        // El 60 % exige documentos obligatorios configurados.
        $this->documentosConPeso($convocatoria, [
            'hoja_de_vida' => null,
            'documento_identidad' => null,
        ]);
        $postulacion = $this->conDocumentosCargados($this->postulacion($convocatoria));

        $this->actingAs($evaluador)->putJson("/api/evaluaciones/{$postulacion->id}", [
            'observaciones' => 'Buen perfil',
            'estado' => 'finalizada',
            'criterios' => $this->criterios(),
        ])->assertOk()
            ->assertJsonPath('data.evaluacion.estado', 'finalizada')
            ->assertJsonPath('data.evaluacion.puntaje_total', '73.33')
            ->assertJsonCount(2, 'data.evaluacion.criterios');

        $this->assertDatabaseHas('evaluaciones', [
            'postulacion_id' => $postulacion->id,
            'evaluador_id' => $evaluador->id,
            'puntaje_total' => 73.33,
        ]);
        $this->assertDatabaseCount('evaluacion_detalles', 2);
    }

    public function test_un_borrador_no_define_si_pasa_a_seleccion(): void
    {
        $ficha = $this->ficha('2896411');
        $evaluador = $this->usuario(Role::Aprendiz, 'evaluador', ['ficha_id' => $ficha->id]);
        $convocatoria = $this->convocatoria($ficha);
        $this->documentosConPeso($convocatoria, [
            'hoja_de_vida' => null,
            'documento_identidad' => null,
        ]);
        $postulacion = $this->postulacion($convocatoria);

        $this->actingAs($evaluador)->putJson("/api/evaluaciones/{$postulacion->id}", [
            'estado' => 'en_proceso',
            'criterios' => [['criterio' => 'Evaluación', 'puntaje' => 90, 'ponderacion' => 1]],
            'cumplimiento' => 90,
        ])->assertOk()
            ->assertJsonPath('data.evaluacion.estado', 'en_proceso')
            ->assertJsonPath('data.evaluacion.pasa_seleccion', null);
    }

    public function test_re_evaluar_actualiza_y_no_duplica(): void
    {
        $ficha = $this->ficha('2896411');
        $evaluador = $this->usuario(Role::Aprendiz, 'evaluador', ['ficha_id' => $ficha->id]);
        $convocatoria = $this->convocatoria($ficha);
        $this->documentosConPeso($convocatoria, [
            'hoja_de_vida' => null,
            'documento_identidad' => null,
        ]);
        $postulacion = $this->postulacion($convocatoria);

        $this->actingAs($evaluador)->putJson("/api/evaluaciones/{$postulacion->id}", [
            'criterios' => $this->criterios(),
        ])->assertOk();

        $this->actingAs($evaluador)->putJson("/api/evaluaciones/{$postulacion->id}", [
            'criterios' => [['criterio' => 'Único', 'puntaje' => 100, 'ponderacion' => 1]],
        ])->assertOk()->assertJsonPath('data.evaluacion.puntaje_total', '100.00');

        $this->assertSame(1, Evaluacion::where('postulacion_id', $postulacion->id)->count());
        $this->assertDatabaseCount('evaluacion_detalles', 1);
    }

    public function test_un_borrador_no_aparece_en_el_listado_de_evaluacion(): void
    {
        $ficha = $this->ficha('2896411');
        $evaluador = $this->usuario(Role::Aprendiz, 'evaluador', ['ficha_id' => $ficha->id]);
        $enviada = $this->postulacion($this->convocatoria($ficha));
        $borrador = $this->postulacion($this->convocatoria($ficha));
        $borrador->update(['estado_id' => $this->parametroId('estado_postulacion', 'borrador')]);

        $ids = collect($this->actingAs($evaluador)->getJson('/api/evaluaciones')->json('data'))
            ->pluck('id')->all();

        $this->assertContains($enviada->id, $ids);
        $this->assertNotContains($borrador->id, $ids);
    }

    public function test_no_se_puede_evaluar_una_postulacion_en_borrador(): void
    {
        $ficha = $this->ficha('2896411');
        $evaluador = $this->usuario(Role::Aprendiz, 'evaluador', ['ficha_id' => $ficha->id]);
        $postulacion = $this->postulacion($this->convocatoria($ficha));
        $postulacion->update(['estado_id' => $this->parametroId('estado_postulacion', 'borrador')]);

        $this->actingAs($evaluador)->putJson("/api/evaluaciones/{$postulacion->id}", [
            'criterios' => $this->criterios(),
        ])->assertStatus(422);

        $this->assertDatabaseMissing('evaluaciones', [
            'postulacion_id' => $postulacion->id,
            'evaluador_id' => $evaluador->id,
        ]);
    }

    public function test_no_se_puede_evaluar_una_postulacion_retirada(): void
    {
        $ficha = $this->ficha('2896411');
        $evaluador = $this->usuario(Role::Aprendiz, 'evaluador', ['ficha_id' => $ficha->id]);
        $postulacion = $this->postulacion($this->convocatoria($ficha));
        $postulacion->update(['estado_id' => $this->parametroId('estado_postulacion', 'retirada')]);

        $this->actingAs($evaluador)->putJson("/api/evaluaciones/{$postulacion->id}", [
            'criterios' => $this->criterios(),
        ])->assertStatus(422);
    }

    public function test_no_puede_evaluar_postulacion_de_otra_ficha(): void
    {
        $fichaA = $this->ficha('2896411');
        $fichaB = $this->ficha('2631620');
        $evaluador = $this->usuario(Role::Aprendiz, 'evaluador', ['ficha_id' => $fichaA->id]);
        $ajena = $this->postulacion($this->convocatoria($fichaB));

        $this->actingAs($evaluador)->putJson("/api/evaluaciones/{$ajena->id}", [
            'criterios' => $this->criterios(),
        ])->assertForbidden();
    }

    public function test_aspirante_no_puede_evaluar(): void
    {
        $ficha = $this->ficha('2896411');
        $aspirante = $this->usuario(Role::Aspirante, null, ['ficha_id' => $ficha->id]);
        $postulacion = $this->postulacion($this->convocatoria($ficha), $aspirante);

        $this->actingAs($aspirante)->putJson("/api/evaluaciones/{$postulacion->id}", [
            'criterios' => $this->criterios(),
        ])->assertForbidden();
    }

    // ── Validaciones ───────────────────────────────────────────────────

    public function test_criterios_son_obligatorios(): void
    {
        $ficha = $this->ficha('2896411');
        $evaluador = $this->usuario(Role::Aprendiz, 'evaluador', ['ficha_id' => $ficha->id]);
        $postulacion = $this->postulacion($this->convocatoria($ficha));

        $this->actingAs($evaluador)->putJson("/api/evaluaciones/{$postulacion->id}", [
            'criterios' => [],
        ])->assertStatus(422)->assertJsonValidationErrors('criterios');
    }

    public function test_puntaje_fuera_de_rango_rechaza(): void
    {
        $ficha = $this->ficha('2896411');
        $evaluador = $this->usuario(Role::Aprendiz, 'evaluador', ['ficha_id' => $ficha->id]);
        $postulacion = $this->postulacion($this->convocatoria($ficha));

        $this->actingAs($evaluador)->putJson("/api/evaluaciones/{$postulacion->id}", [
            'criterios' => [['criterio' => 'Experiencia', 'puntaje' => 150]],
        ])->assertStatus(422)->assertJsonValidationErrors('criterios.0.puntaje');
    }

    // ── Filtro por ficha ───────────────────────────────────────────────

    public function test_filtra_postulaciones_por_ficha_y_devuelve_las_fichas(): void
    {
        $fichaA = $this->ficha('2896411');
        $fichaB = $this->ficha('2631620');

        $evaluador = $this->usuario(Role::Aprendiz, 'evaluador', ['ficha_id' => $fichaA->id]);
        $evaluador->fichas()->syncWithoutDetaching([$fichaB->id]);

        $deA = $this->postulacion($this->convocatoria($fichaA));
        $deB = $this->postulacion($this->convocatoria($fichaB));

        $respuesta = $this->actingAs($evaluador)
            ->getJson("/api/evaluaciones?ficha_id={$fichaB->id}")
            ->assertOk();

        $ids = collect($respuesta->json('data'))->pluck('id')->all();

        $this->assertContains($deB->id, $ids);
        $this->assertNotContains($deA->id, $ids);

        // El meta trae las fichas del alcance para poblar el filtro.
        $this->assertCount(2, $respuesta->json('meta.fichas'));
    }

    public function test_ficha_ajena_al_alcance_no_devuelve_nada(): void
    {
        $fichaA = $this->ficha('2896411');
        $fichaB = $this->ficha('2631620');

        $evaluador = $this->usuario(Role::Aprendiz, 'evaluador', ['ficha_id' => $fichaA->id]);
        $this->postulacion($this->convocatoria($fichaA));
        $this->postulacion($this->convocatoria($fichaB));

        $respuesta = $this->actingAs($evaluador)
            ->getJson("/api/evaluaciones?ficha_id={$fichaB->id}")
            ->assertOk();

        $this->assertSame([], $respuesta->json('data'));
        // El filtro solo ofrece las fichas del alcance (no la B).
        $this->assertCount(1, $respuesta->json('meta.fichas'));
    }

    // ── El aspirante ve lo suyo ────────────────────────────────────────

    public function test_aspirante_ve_sus_evaluaciones(): void
    {
        $ficha = $this->ficha('2896411');
        $aspirante = $this->usuario(Role::Aspirante, null, ['ficha_id' => $ficha->id]);
        $evaluador = $this->usuario(Role::Aprendiz, 'evaluador', ['ficha_id' => $ficha->id]);
        $postulacion = $this->postulacion($this->convocatoria($ficha), $aspirante);

        Evaluacion::create([
            'postulacion_id' => $postulacion->id,
            'evaluador_id' => $evaluador->id,
            'puntaje_total' => 88.5,
            'estado' => 'finalizada',
            'observaciones' => 'Recomendado',
        ]);

        $this->actingAs($aspirante)->getJson('/api/mis-evaluaciones')
            ->assertOk()
            ->assertJsonPath('data.0.puntaje_total', '88.50')
            ->assertJsonPath('data.0.observaciones', 'Recomendado');
    }

    // ── Porcentajes configurables por convocatoria ─────────────────────

    /** Adjunta documentos a la convocatoria con su ponderación (null = sin configurar). */
    private function documentosConPeso(Convocatoria $convocatoria, array $codigos): array
    {
        $ids = [];
        foreach ($codigos as $codigo => $peso) {
            $id = $this->parametroId('tipo_documento', $codigo);
            $convocatoria->documentos()->attach($id, [
                'obligatorio' => true,
                'ponderacion' => $peso,
            ]);
            $ids[$codigo] = $id;
        }

        return $ids;
    }

    public function test_cumplimiento_usa_los_porcentajes_de_la_convocatoria(): void
    {
        $ficha = $this->ficha('2896411');
        $evaluador = $this->usuario(Role::Aprendiz, 'evaluador', ['ficha_id' => $ficha->id]);
        $convocatoria = $this->convocatoria($ficha);
        $postulacion = $this->postulacion($convocatoria);

        // Hoja de vida vale 80 % y el documento de identidad 20 %.
        $ids = $this->documentosConPeso($convocatoria, [
            'hoja_de_vida' => 80,
            'documento_identidad' => 20,
        ]);
        $this->conDocumentosCargados($postulacion);

        // Solo cumple el documento que vale 80 % → 80 % de cumplimiento.
        $this->actingAs($evaluador)->putJson("/api/evaluaciones/{$postulacion->id}", [
            'estado' => 'finalizada',
            'criterios' => [['criterio' => 'Evaluación', 'puntaje' => 80, 'ponderacion' => 1]],
            'perfil' => [
                'doc-'.$ids['hoja_de_vida'] => true,
                'doc-'.$ids['documento_identidad'] => false,
            ],
            // Se envía un valor distinto a propósito: el servidor manda.
            'cumplimiento' => 100,
        ])->assertOk()
            ->assertJsonPath('data.evaluacion.cumplimiento', 80)
            ->assertJsonPath('data.evaluacion.pasa_seleccion', true);
    }

    public function test_el_conteo_de_cargados_solo_incluye_documentos_requeridos(): void
    {
        $ficha = $this->ficha('2896411');
        $evaluador = $this->usuario(Role::Aprendiz, 'evaluador', ['ficha_id' => $ficha->id]);
        $convocatoria = $this->convocatoria($ficha);
        $postulacion = $this->postulacion($convocatoria);

        $ids = $this->documentosConPeso($convocatoria, [
            'hoja_de_vida' => 50,
            'documento_identidad' => 50,
        ]);

        // Un requerido cargado y un documento adicional (sin parámetro).
        $archivo = [
            'nombre_original' => 'archivo.pdf',
            'ruta' => 'postulaciones/test/archivo.pdf',
            'disco' => 'local',
            'mime' => 'application/pdf',
            'tamano_bytes' => 1024,
        ];
        $postulacion->documentos()->create($archivo + ['parametro_id' => $ids['hoja_de_vida']]);
        $postulacion->documentos()->create($archivo + ['parametro_id' => null, 'titulo' => 'Extra']);

        $this->actingAs($evaluador)->getJson('/api/evaluaciones')
            ->assertOk()
            ->assertJsonPath('data.0.documentos.total', 2)
            ->assertJsonPath('data.0.documentos.cargados', 1);
    }

    public function test_sin_porcentajes_configurados_reparte_en_partes_iguales(): void
    {
        $ficha = $this->ficha('2896411');
        $evaluador = $this->usuario(Role::Aprendiz, 'evaluador', ['ficha_id' => $ficha->id]);
        $convocatoria = $this->convocatoria($ficha);
        $postulacion = $this->postulacion($convocatoria);

        $ids = $this->documentosConPeso($convocatoria, [
            'hoja_de_vida' => null,
            'documento_identidad' => null,
        ]);
        $this->conDocumentosCargados($postulacion);

        // Dos documentos sin peso y solo uno cumplido → 50 %.
        $this->actingAs($evaluador)->putJson("/api/evaluaciones/{$postulacion->id}", [
            'estado' => 'finalizada',
            'criterios' => [['criterio' => 'Evaluación', 'puntaje' => 50, 'ponderacion' => 1]],
            'perfil' => [
                'doc-'.$ids['hoja_de_vida'] => true,
                'doc-'.$ids['documento_identidad'] => false,
            ],
        ])->assertOk()
            ->assertJsonPath('data.evaluacion.cumplimiento', 50)
            ->assertJsonPath('data.evaluacion.pasa_seleccion', false);
    }

    public function test_documentos_no_obligatorios_no_cuentan_para_el_60(): void
    {
        $ficha = $this->ficha('2896411');
        $evaluador = $this->usuario(Role::Aprendiz, 'evaluador', ['ficha_id' => $ficha->id]);
        $convocatoria = $this->convocatoria($ficha);
        $postulacion = $this->postulacion($convocatoria);

        $obligatorio = $this->parametroId('tipo_documento', 'hoja_de_vida');
        $opcional = $this->parametroId('tipo_documento', 'tarjeta_profesional');

        $convocatoria->documentos()->attach($obligatorio, ['obligatorio' => true, 'ponderacion' => null]);
        $convocatoria->documentos()->attach($opcional, ['obligatorio' => false, 'ponderacion' => null]);
        $this->conDocumentosCargados($postulacion);

        // Solo cumple el obligatorio. Si el opcional contara, el resultado sería 50.
        $this->actingAs($evaluador)->putJson("/api/evaluaciones/{$postulacion->id}", [
            'estado' => 'finalizada',
            'criterios' => [['criterio' => 'Evaluación', 'puntaje' => 100, 'ponderacion' => 1]],
            'perfil' => ['doc-'.$obligatorio => true],
        ])->assertOk()
            ->assertJsonPath('data.evaluacion.cumplimiento', 100)
            ->assertJsonPath('data.evaluacion.pasa_seleccion', true);
    }

    public function test_un_documento_nuevo_obligatorio_cuenta_para_el_60(): void
    {
        $ficha = $this->ficha('2896411');
        $evaluador = $this->usuario(Role::Aprendiz, 'evaluador', ['ficha_id' => $ficha->id]);
        $convocatoria = $this->convocatoria($ficha);
        $postulacion = $this->postulacion($convocatoria);

        // Documento nuevo del catálogo (sin código), obligatorio y con todo el
        // peso: debe contar igual que los documentos fijos.
        $tema = ParametroTema::where('codigo', 'tipo_documento')->firstOrFail();
        $nuevo = Parametro::create([
            'tema_id' => $tema->id,
            'codigo' => null,
            'nombre' => 'Especialización',
            'orden' => 99,
            'activo' => true,
        ]);

        $convocatoria->documentos()->attach($nuevo->id, [
            'obligatorio' => true,
            'ponderacion' => 100,
        ]);
        $this->conDocumentosCargados($postulacion);

        $this->actingAs($evaluador)->putJson("/api/evaluaciones/{$postulacion->id}", [
            'estado' => 'finalizada',
            'criterios' => [['criterio' => 'Evaluación', 'puntaje' => 100, 'ponderacion' => 1]],
            'perfil' => ['doc-'.$nuevo->id => true],
        ])->assertOk()
            ->assertJsonPath('data.evaluacion.cumplimiento', 100)
            ->assertJsonPath('data.evaluacion.pasa_seleccion', true);
    }

    public function test_la_libreta_militar_de_una_mujer_no_cuenta_para_el_60(): void
    {
        $ficha = $this->ficha('2896411');
        $evaluador = $this->usuario(Role::Aprendiz, 'evaluador', ['ficha_id' => $ficha->id]);
        $convocatoria = $this->convocatoria($ficha);
        $aspirante = $this->usuario(Role::Aspirante, null, ['ficha_id' => $ficha->id]);

        PerfilAspirante::create([
            'user_id' => $aspirante->id,
            'sexo_id' => $this->parametroId('sexo', 'mujer'),
        ]);

        $postulacion = $this->postulacion($convocatoria, $aspirante);

        $hoja = $this->parametroId('tipo_documento', 'hoja_de_vida');
        $libreta = $this->parametroId('tipo_documento', 'libreta_militar');

        $convocatoria->documentos()->attach($hoja, ['obligatorio' => true, 'ponderacion' => null]);
        $convocatoria->documentos()->attach($libreta, ['obligatorio' => true, 'ponderacion' => null]);
        // Solo sube la hoja de vida: la libreta no aplica a una mujer y no se le exige.
        $postulacion->documentos()->create([
            'nombre_original' => 'hoja.pdf', 'ruta' => "postulaciones/test/{$postulacion->id}-hoja.pdf", 'disco' => 'local',
            'mime' => 'application/pdf', 'tamano_bytes' => 1024, 'parametro_id' => $hoja,
        ]);

        // La libreta no aplica: solo pesa la hoja de vida.
        $this->actingAs($evaluador)->putJson("/api/evaluaciones/{$postulacion->id}", [
            'estado' => 'finalizada',
            'criterios' => [['criterio' => 'Evaluación', 'puntaje' => 100, 'ponderacion' => 1]],
            'perfil' => ['doc-'.$hoja => true],
        ])->assertOk()
            ->assertJsonPath('data.evaluacion.cumplimiento', 100)
            ->assertJsonPath('data.evaluacion.pasa_seleccion', true);
    }

    public function test_no_se_puede_evaluar_sin_documentos_obligatorios_aplicables(): void
    {
        $ficha = $this->ficha('2896411');
        $evaluador = $this->usuario(Role::Aprendiz, 'evaluador', ['ficha_id' => $ficha->id]);
        $postulacion = $this->postulacion($this->convocatoria($ficha));

        // Aunque el navegador mande cumplimiento 100, sin documentos no hay base
        // para decidir el 60 %: se rechaza.
        $this->actingAs($evaluador)->putJson("/api/evaluaciones/{$postulacion->id}", [
            'estado' => 'finalizada',
            'criterios' => [['criterio' => 'Evaluación', 'puntaje' => 100, 'ponderacion' => 1]],
            'cumplimiento' => 100,
        ])->assertStatus(422);
    }

    // ── Documentos faltantes (RF-DOC-006 + RF-EVA-003) ─────────────────

    /** Postulación a la que solo le falta la hoja de vida (sí subió el documento de identidad). */
    private function postulacionSinHojaDeVida(): array
    {
        $ficha = $this->ficha('2896411');
        $evaluador = $this->usuario(Role::Aprendiz, 'evaluador', ['ficha_id' => $ficha->id]);
        $convocatoria = $this->convocatoria($ficha);
        $ids = $this->documentosConPeso($convocatoria, ['hoja_de_vida' => 40, 'documento_identidad' => 60]);
        $postulacion = $this->postulacion($convocatoria);
        $postulacion->documentos()->create([
            'nombre_original' => 'id.pdf', 'ruta' => "postulaciones/test/{$postulacion->id}-id.pdf", 'disco' => 'local',
            'mime' => 'application/pdf', 'tamano_bytes' => 1024, 'parametro_id' => $ids['documento_identidad'],
        ]);

        return [$evaluador, $postulacion, $ids];
    }

    public function test_no_se_puede_marcar_cumple_un_documento_obligatorio_sin_archivo(): void
    {
        [$evaluador, $postulacion, $ids] = $this->postulacionSinHojaDeVida();

        foreach (['en_proceso', 'finalizada'] as $estado) {
            $this->actingAs($evaluador)->putJson("/api/evaluaciones/{$postulacion->id}", [
                'estado' => $estado,
                'criterios' => [['criterio' => 'Evaluación', 'puntaje' => 100, 'ponderacion' => 1]],
                'perfil' => ['doc-'.$ids['hoja_de_vida'] => true, 'doc-'.$ids['documento_identidad'] => true],
            ])->assertStatus(422)
                ->assertJsonPath('message', 'No puedes marcar «Cumple» un documento que el aspirante no ha cargado: Hoja de vida.');
        }

        $this->assertDatabaseCount('evaluaciones', 0);
    }

    public function test_no_se_puede_finalizar_con_un_obligatorio_aplicable_faltante_aunque_se_marque_no(): void
    {
        [$evaluador, $postulacion, $ids] = $this->postulacionSinHojaDeVida();

        // El resto cumple y alcanzaría el 60 %, pero falta un obligatorio: no se finaliza, se pide la corrección.
        $this->actingAs($evaluador)->putJson("/api/evaluaciones/{$postulacion->id}", [
            'estado' => 'finalizada',
            'criterios' => [['criterio' => 'Evaluación', 'puntaje' => 60, 'ponderacion' => 1]],
            'perfil' => ['doc-'.$ids['hoja_de_vida'] => false, 'doc-'.$ids['documento_identidad'] => true],
        ])->assertStatus(422)
            ->assertJsonPath('message', 'No se puede finalizar la evaluación: faltan documentos obligatorios (Hoja de vida). Solicita la corrección al aspirante.');

        $this->assertDatabaseCount('evaluaciones', 0);
    }

    public function test_con_un_obligatorio_faltante_se_puede_guardar_en_proceso_y_pedir_la_correccion(): void
    {
        [$evaluador, $postulacion, $ids] = $this->postulacionSinHojaDeVida();
        $perfil = ['doc-'.$ids['hoja_de_vida'] => false, 'doc-'.$ids['documento_identidad'] => true];

        $this->actingAs($evaluador)->putJson("/api/evaluaciones/{$postulacion->id}", [
            'estado' => 'en_proceso',
            'criterios' => [['criterio' => 'Evaluación', 'puntaje' => 60, 'ponderacion' => 1]],
            'perfil' => $perfil,
        ])->assertOk()->assertJsonPath('data.evaluacion.estado', 'en_proceso')->assertJsonPath('data.evaluacion.pasa_seleccion', null);

        $this->actingAs($evaluador)->putJson("/api/evaluaciones/{$postulacion->id}", [
            'estado' => 'correccion_solicitada',
            'observaciones' => 'Falta subir la hoja de vida.',
            'criterios' => [['criterio' => 'Evaluación', 'puntaje' => 60, 'ponderacion' => 1]],
            'perfil' => $perfil,
        ])->assertOk()->assertJsonPath('data.evaluacion.estado', 'correccion_solicitada')->assertJsonPath('data.evaluacion.pasa_seleccion', null);
    }

    public function test_la_libreta_de_una_mujer_y_un_opcional_ausente_no_bloquean_la_finalizacion(): void
    {
        $ficha = $this->ficha('2896411');
        $evaluador = $this->usuario(Role::Aprendiz, 'evaluador', ['ficha_id' => $ficha->id]);
        $convocatoria = $this->convocatoria($ficha);
        $aspirante = $this->usuario(Role::Aspirante, null, ['ficha_id' => $ficha->id]);
        PerfilAspirante::create(['user_id' => $aspirante->id, 'sexo_id' => $this->parametroId('sexo', 'mujer')]);
        $ids = $this->documentosConPeso($convocatoria, ['hoja_de_vida' => 100, 'libreta_militar' => null]);
        $convocatoria->documentos()->attach($this->parametroId('tipo_documento', 'tarjeta_profesional'), ['obligatorio' => false, 'ponderacion' => null]);
        $postulacion = $this->postulacion($convocatoria, $aspirante);
        $postulacion->documentos()->create([
            'nombre_original' => 'hv.pdf', 'ruta' => "postulaciones/test/{$postulacion->id}-hv.pdf", 'disco' => 'local',
            'mime' => 'application/pdf', 'tamano_bytes' => 1024, 'parametro_id' => $ids['hoja_de_vida'],
        ]);

        $this->actingAs($evaluador)->putJson("/api/evaluaciones/{$postulacion->id}", [
            'estado' => 'finalizada',
            'criterios' => [['criterio' => 'Evaluación', 'puntaje' => 100, 'ponderacion' => 1]],
            'perfil' => ['doc-'.$ids['hoja_de_vida'] => true],
        ])->assertOk()->assertJsonPath('data.evaluacion.pasa_seleccion', true);
    }

    public function test_el_conteo_de_documentos_excluye_opcionales_y_no_aplica(): void
    {
        $ficha = $this->ficha('2896411');
        $evaluador = $this->usuario(Role::Aprendiz, 'evaluador', ['ficha_id' => $ficha->id]);
        $convocatoria = $this->convocatoria($ficha);
        $aspirante = $this->usuario(Role::Aspirante, null, ['ficha_id' => $ficha->id]);

        PerfilAspirante::create([
            'user_id' => $aspirante->id,
            'sexo_id' => $this->parametroId('sexo', 'mujer'),
        ]);

        $this->postulacion($convocatoria, $aspirante);

        $hoja = $this->parametroId('tipo_documento', 'hoja_de_vida');
        $libreta = $this->parametroId('tipo_documento', 'libreta_militar');
        $opcional = $this->parametroId('tipo_documento', 'tarjeta_profesional');

        $convocatoria->documentos()->attach($hoja, ['obligatorio' => true, 'ponderacion' => null]);
        $convocatoria->documentos()->attach($libreta, ['obligatorio' => true, 'ponderacion' => null]);
        $convocatoria->documentos()->attach($opcional, ['obligatorio' => false, 'ponderacion' => null]);

        // Solo queda la hoja de vida: la libreta no aplica y la tarjeta es opcional.
        $this->actingAs($evaluador)->getJson('/api/evaluaciones')
            ->assertOk()
            ->assertJsonPath('data.0.documentos.total', 1)
            ->assertJsonPath('data.0.documentos.cargados', 0);
    }

    public function test_evaluador_puede_mandar_a_corregir_y_reemplazar_documento_lo_marca_actualizado(): void
    {
        Storage::fake('local');

        $ficha = $this->ficha('2896411');
        $evaluador = $this->usuario(Role::Aprendiz, 'evaluador', ['ficha_id' => $ficha->id]);
        $aspirante = $this->usuario(Role::Aspirante, null, ['ficha_id' => $ficha->id]);
        $convocatoria = $this->convocatoria($ficha);
        $convocatoria->forceFill([
            'fecha_apertura' => now('America/Bogota')->subDay()->toDateString(),
            'fecha_cierre' => now('America/Bogota')->addDay()->toDateString(),
        ])->save();

        $hoja = $this->parametroId('tipo_documento', 'hoja_de_vida');
        $convocatoria->documentos()->attach($hoja, ['obligatorio' => true, 'ponderacion' => 100]);

        $postulacion = $this->postulacion($convocatoria, $aspirante);

        // 1) Mandar a corregir sin observaciones se rechaza con 422.
        $this->actingAs($evaluador)->putJson("/api/evaluaciones/{$postulacion->id}", [
            'estado' => 'correccion_solicitada',
            'criterios' => [['criterio' => 'Evaluación', 'puntaje' => 0, 'ponderacion' => 1]],
            'perfil' => ['doc-'.$hoja => false],
        ])->assertStatus(422);

        // 2) Mandar a corregir con documento en false y observaciones funciona.
        $this->actingAs($evaluador)->putJson("/api/evaluaciones/{$postulacion->id}", [
            'estado' => 'correccion_solicitada',
            'observaciones' => 'La hoja de vida está borrosa.',
            'criterios' => [['criterio' => 'Evaluación', 'puntaje' => 0, 'ponderacion' => 1]],
            'perfil' => ['doc-'.$hoja => false],
        ])->assertOk()
            ->assertJsonPath('data.evaluacion.estado', 'correccion_solicitada')
            ->assertJsonPath('data.evaluacion.pasa_seleccion', null);

        // 3) El aspirante reemplaza el documento requerido sin error 500.
        $this->actingAs($aspirante)
            ->withHeaders(['Accept' => 'application/json'])
            ->post("/api/postulaciones/{$postulacion->id}/documentos", [
                'codigo' => 'hoja_de_vida',
                'archivo' => UploadedFile::fake()->create('hoja_corregida.pdf', 100, 'application/pdf'),
            ])->assertCreated();

        // 4) El evaluador ve el documento en documentos_actualizados.
        $this->actingAs($evaluador)->getJson("/api/evaluaciones/{$postulacion->id}")
            ->assertOk()
            ->assertJsonPath('data.evaluacion.documentos_actualizados', ['doc-'.$hoja]);
    }

    public function test_aspirante_envia_la_correccion_solo_cuando_reemplaza_todos_los_marcados(): void
    {
        Storage::fake('local');

        $ficha = $this->ficha('2896412');
        $evaluador = $this->usuario(Role::Aprendiz, 'evaluador', ['ficha_id' => $ficha->id]);
        $aspirante = $this->usuario(Role::Aspirante, null, ['ficha_id' => $ficha->id]);
        $convocatoria = $this->convocatoria($ficha);
        $convocatoria->forceFill([
            'fecha_apertura' => now('America/Bogota')->subDay()->toDateString(),
            'fecha_cierre' => now('America/Bogota')->addDay()->toDateString(),
        ])->save();

        $hoja = $this->parametroId('tipo_documento', 'hoja_de_vida');
        $identidad = $this->parametroId('tipo_documento', 'documento_identidad');
        $convocatoria->documentos()->attach($hoja, ['obligatorio' => true, 'ponderacion' => 50]);
        $convocatoria->documentos()->attach($identidad, ['obligatorio' => true, 'ponderacion' => 50]);

        $postulacion = $this->postulacion($convocatoria, $aspirante);

        // El evaluador marca dos documentos con "Corregir".
        $this->actingAs($evaluador)->putJson("/api/evaluaciones/{$postulacion->id}", [
            'estado' => 'correccion_solicitada',
            'observaciones' => 'Subir de nuevo hoja de vida e identidad.',
            'criterios' => [['criterio' => 'Evaluación', 'puntaje' => 0, 'ponderacion' => 1]],
            'perfil' => ['doc-'.$hoja => false, 'doc-'.$identidad => false],
        ])->assertOk();

        // Sin reemplazar nada, el envío se rechaza y no guarda nada.
        $this->actingAs($aspirante)->postJson("/api/postulaciones/{$postulacion->id}/correccion/enviar")
            ->assertStatus(422)
            ->assertJsonPath('faltantes', collect([$hoja, $identidad])->sort()->values()->all());

        // Reemplaza solo uno: sigue faltando el otro.
        $this->actingAs($aspirante)->withHeaders(['Accept' => 'application/json'])
            ->post("/api/postulaciones/{$postulacion->id}/documentos", [
                'codigo' => 'hoja_de_vida',
                'archivo' => UploadedFile::fake()->create('hoja.pdf', 100, 'application/pdf'),
            ])->assertCreated();
        $this->actingAs($aspirante)->postJson("/api/postulaciones/{$postulacion->id}/correccion/enviar")
            ->assertStatus(422)
            ->assertJsonPath('faltantes', [$identidad]);

        // Reemplaza el segundo: el envío queda registrado.
        $this->actingAs($aspirante)->withHeaders(['Accept' => 'application/json'])
            ->post("/api/postulaciones/{$postulacion->id}/documentos", [
                'codigo' => 'documento_identidad',
                'archivo' => UploadedFile::fake()->create('identidad.pdf', 100, 'application/pdf'),
            ])->assertCreated();
        $this->actingAs($aspirante)->postJson("/api/postulaciones/{$postulacion->id}/correccion/enviar")
            ->assertOk()
            ->assertJsonStructure(['data' => ['correccion_enviada_at']]);

        // El evaluador y el aspirante ven la fecha del envío, y los reemplazos siguen marcados.
        $this->actingAs($evaluador)->getJson("/api/evaluaciones/{$postulacion->id}")
            ->assertOk()
            ->assertJsonCount(2, 'data.evaluacion.documentos_actualizados')->assertJsonFragment(['doc-'.$hoja])->assertJsonFragment(['doc-'.$identidad]);
        $this->actingAs($aspirante)->getJson('/api/mis-evaluaciones')
            ->assertOk()
            ->assertJsonPath('data.0.correccion_enviada_at', fn ($v) => $v !== null);

        // Otro usuario no puede enviar la corrección de esta postulación.
        $otro = $this->usuario(Role::Aspirante, null, ['ficha_id' => $ficha->id]);
        $this->actingAs($otro)->postJson("/api/postulaciones/{$postulacion->id}/correccion/enviar")
            ->assertNotFound();
    }

    public function test_pedir_correccion_fija_plazo_de_dos_dias_que_se_conserva_y_se_limpia_al_finalizar(): void
    {
        Storage::fake('local');

        $ficha = $this->ficha('2896413');
        $evaluador = $this->usuario(Role::Aprendiz, 'evaluador', ['ficha_id' => $ficha->id]);
        $aspirante = $this->usuario(Role::Aspirante, null, ['ficha_id' => $ficha->id]);
        $convocatoria = $this->convocatoria($ficha);
        $hoja = $this->parametroId('tipo_documento', 'hoja_de_vida');
        $convocatoria->documentos()->attach($hoja, ['obligatorio' => true, 'ponderacion' => 100]);
        $postulacion = $this->postulacion($convocatoria, $aspirante);

        $solicitud = [
            'estado' => 'correccion_solicitada',
            'observaciones' => 'La hoja de vida está borrosa.',
            'criterios' => [['criterio' => 'Evaluación', 'puntaje' => 0, 'ponderacion' => 1]],
            'perfil' => ['doc-'.$hoja => false],
        ];

        // 1) Al pedir la corrección se fija un plazo de dos días.
        $this->actingAs($evaluador)->putJson("/api/evaluaciones/{$postulacion->id}", $solicitud)->assertOk();
        $primero = Evaluacion::where('postulacion_id', $postulacion->id)->firstOrFail()->fecha_limite_correccion;
        $this->assertNotNull($primero);
        $this->assertEqualsWithDelta(now()->addDays(2)->timestamp, $primero->timestamp, 60);

        // 2) Volver a guardar la misma solicitud conserva el plazo original.
        $this->actingAs($evaluador)->putJson("/api/evaluaciones/{$postulacion->id}", $solicitud)->assertOk();
        $this->assertEquals(
            $primero->timestamp,
            Evaluacion::where('postulacion_id', $postulacion->id)->firstOrFail()->fecha_limite_correccion->timestamp,
        );

        // 3) El aspirante ve el plazo en su listado.
        $this->actingAs($aspirante)->getJson('/api/mis-evaluaciones')
            ->assertOk()
            ->assertJsonPath('data.0.fecha_limite_correccion', fn ($v) => $v !== null);

        // 4) Al finalizar, el plazo deja de aplicar. Antes el aspirante carga la hoja de vida (finalizar exige los obligatorios).
        $this->actingAs($aspirante)->withHeaders(['Accept' => 'application/json'])
            ->post("/api/postulaciones/{$postulacion->id}/documentos", [
                'codigo' => 'hoja_de_vida',
                'archivo' => UploadedFile::fake()->create('hoja.pdf', 100, 'application/pdf'),
            ])->assertCreated();
        $this->actingAs($evaluador)->putJson("/api/evaluaciones/{$postulacion->id}", [
            'estado' => 'finalizada',
            'criterios' => [['criterio' => 'Evaluación', 'puntaje' => 80, 'ponderacion' => 1]],
            'perfil' => ['doc-'.$hoja => true],
        ])->assertOk();
        $this->assertNull(Evaluacion::where('postulacion_id', $postulacion->id)->firstOrFail()->fecha_limite_correccion);
    }

    public function test_con_el_plazo_vencido_el_aspirante_no_puede_enviar_la_correccion(): void
    {
        Storage::fake('local');

        $ficha = $this->ficha('2896414');
        $evaluador = $this->usuario(Role::Aprendiz, 'evaluador', ['ficha_id' => $ficha->id]);
        $aspirante = $this->usuario(Role::Aspirante, null, ['ficha_id' => $ficha->id]);
        $convocatoria = $this->convocatoria($ficha);
        $hoja = $this->parametroId('tipo_documento', 'hoja_de_vida');
        $convocatoria->documentos()->attach($hoja, ['obligatorio' => true, 'ponderacion' => 100]);
        $postulacion = $this->postulacion($convocatoria, $aspirante);

        $this->actingAs($evaluador)->putJson("/api/evaluaciones/{$postulacion->id}", [
            'estado' => 'correccion_solicitada',
            'observaciones' => 'Hoja de vida borrosa.',
            'criterios' => [['criterio' => 'Evaluación', 'puntaje' => 0, 'ponderacion' => 1]],
            'perfil' => ['doc-'.$hoja => false],
        ])->assertOk();

        // El plazo ya pasó.
        Evaluacion::where('postulacion_id', $postulacion->id)->update([
            'fecha_limite_correccion' => now()->subMinute(),
        ]);

        // Aunque reemplace el documento, el envío se rechaza.
        $this->actingAs($aspirante)->withHeaders(['Accept' => 'application/json'])
            ->post("/api/postulaciones/{$postulacion->id}/documentos", [
                'codigo' => 'hoja_de_vida',
                'archivo' => UploadedFile::fake()->create('hoja.pdf', 100, 'application/pdf'),
            ])->assertCreated();

        $this->actingAs($aspirante)->postJson("/api/postulaciones/{$postulacion->id}/correccion/enviar")
            ->assertStatus(422)
            ->assertJsonPath('errors.correccion.0', fn ($m) => str_contains($m, 'venció'));

        $this->assertNull(Evaluacion::where('postulacion_id', $postulacion->id)->firstOrFail()->correccion_enviada_at);
    }

    public function test_reemplazar_documento_reabre_evaluacion_finalizada_a_en_proceso(): void
    {
        Storage::fake('local');

        $ficha = $this->ficha('2896411');
        $evaluador = $this->usuario(Role::Aprendiz, 'evaluador', ['ficha_id' => $ficha->id]);
        $aspirante = $this->usuario(Role::Aspirante, null, ['ficha_id' => $ficha->id]);
        $convocatoria = $this->convocatoria($ficha);
        $convocatoria->forceFill([
            'fecha_apertura' => now('America/Bogota')->subDay()->toDateString(),
            'fecha_cierre' => now('America/Bogota')->addDay()->toDateString(),
        ])->save();

        $hoja = $this->parametroId('tipo_documento', 'hoja_de_vida');
        $convocatoria->documentos()->attach($hoja, ['obligatorio' => true, 'ponderacion' => 100]);

        $postulacion = $this->conDocumentosCargados($this->postulacion($convocatoria, $aspirante));

        $this->actingAs($evaluador)->putJson("/api/evaluaciones/{$postulacion->id}", [
            'estado' => 'finalizada',
            'criterios' => [['criterio' => 'Evaluación', 'puntaje' => 100, 'ponderacion' => 1]],
            'perfil' => ['doc-'.$hoja => true],
        ])->assertOk()
            ->assertJsonPath('data.evaluacion.estado', 'finalizada')
            ->assertJsonPath('data.evaluacion.pasa_seleccion', true);

        // Si el aspirante reemplaza el documento (ya existía: 200), la evaluación finalizada pasa a en_proceso.
        $this->actingAs($aspirante)
            ->withHeaders(['Accept' => 'application/json'])
            ->post("/api/postulaciones/{$postulacion->id}/documentos", [
                'codigo' => 'hoja_de_vida',
                'archivo' => UploadedFile::fake()->create('hoja_nueva.pdf', 100, 'application/pdf'),
            ])->assertOk();

        $this->actingAs($evaluador)->getJson("/api/evaluaciones/{$postulacion->id}")
            ->assertOk()
            ->assertJsonPath('data.evaluacion.estado', 'en_proceso')
            ->assertJsonPath('data.evaluacion.pasa_seleccion', null);
    }
}
