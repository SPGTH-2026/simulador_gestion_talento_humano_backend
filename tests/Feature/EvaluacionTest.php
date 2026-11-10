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
        $postulacion = $this->postulacion($convocatoria);

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
        $postulacion = $this->postulacion($convocatoria);

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

        $postulacion = $this->postulacion($convocatoria, $aspirante);

        $this->actingAs($evaluador)->putJson("/api/evaluaciones/{$postulacion->id}", [
            'estado' => 'finalizada',
            'criterios' => [['criterio' => 'Evaluación', 'puntaje' => 100, 'ponderacion' => 1]],
            'perfil' => ['doc-'.$hoja => true],
        ])->assertOk()
            ->assertJsonPath('data.evaluacion.estado', 'finalizada')
            ->assertJsonPath('data.evaluacion.pasa_seleccion', true);

        // Si el aspirante reemplaza el documento, la evaluación finalizada pasa a en_proceso.
        $this->actingAs($aspirante)
            ->withHeaders(['Accept' => 'application/json'])
            ->post("/api/postulaciones/{$postulacion->id}/documentos", [
                'codigo' => 'hoja_de_vida',
                'archivo' => UploadedFile::fake()->create('hoja_nueva.pdf', 100, 'application/pdf'),
            ])->assertCreated();

        $this->actingAs($evaluador)->getJson("/api/evaluaciones/{$postulacion->id}")
            ->assertOk()
            ->assertJsonPath('data.evaluacion.estado', 'en_proceso')
            ->assertJsonPath('data.evaluacion.pasa_seleccion', null);
    }
}
