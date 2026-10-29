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

    public function test_evaluador_registra_evaluacion_y_calcula_promedio_ponderado(): void
    {
        $ficha = $this->ficha('2896411');
        $evaluador = $this->usuario(Role::Aprendiz, 'evaluador', ['ficha_id' => $ficha->id]);
        $postulacion = $this->postulacion($this->convocatoria($ficha));

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

    public function test_re_evaluar_actualiza_y_no_duplica(): void
    {
        $ficha = $this->ficha('2896411');
        $evaluador = $this->usuario(Role::Aprendiz, 'evaluador', ['ficha_id' => $ficha->id]);
        $postulacion = $this->postulacion($this->convocatoria($ficha));

        $this->actingAs($evaluador)->putJson("/api/evaluaciones/{$postulacion->id}", [
            'criterios' => $this->criterios(),
        ])->assertOk();

        $this->actingAs($evaluador)->putJson("/api/evaluaciones/{$postulacion->id}", [
            'criterios' => [['criterio' => 'Único', 'puntaje' => 100, 'ponderacion' => 1]],
        ])->assertOk()->assertJsonPath('data.evaluacion.puntaje_total', '100.00');

        $this->assertSame(1, Evaluacion::where('postulacion_id', $postulacion->id)->count());
        $this->assertDatabaseCount('evaluacion_detalles', 1);
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
}
