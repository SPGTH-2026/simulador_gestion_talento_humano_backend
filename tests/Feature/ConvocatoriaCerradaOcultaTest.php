<?php

namespace App\Tests\Feature;

use App\Enums\Role;
use App\Models\Convocatoria;
use App\Models\Ficha;
use App\Models\Parametro;
use App\Models\Postulacion;
use App\Models\User;
use Database\Seeders\ParametroSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Convocatorias cerradas: quien NO gestiona no las ve.
 *
 * "Cerrada" = estado 'cerrada', o 'publicada' con la fecha de cierre ya pasada.
 * 'en_proceso' NO se oculta. El gestor (super admin, instructor o aprendiz
 * gestor_convocatorias) sí las ve. "Mis postulaciones" no se toca.
 */
class ConvocatoriaCerradaOcultaTest extends TestCase
{
    use RefreshDatabase;

    private static int $codigoSecuencia = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ParametroSeeder::class);
    }

    private function ficha(): Ficha
    {
        return Ficha::create([
            'codigo' => '3173334',
            'nombre_programa' => 'Análisis y Desarrollo de Software',
        ]);
    }

    private function usuario(Role $role, array $extra = []): User
    {
        return User::factory()->create($extra + [
            'role' => $role,
            'subrole' => null,
            'active' => true,
            'email_verified_at' => now(),
        ]);
    }

    private function instructor(Ficha $ficha): User
    {
        $instructor = $this->usuario(Role::Instructor);
        $instructor->fichas()->attach($ficha->id);

        return $instructor->fresh();
    }

    private function aspirante(Ficha $ficha): User
    {
        return $this->usuario(Role::Aspirante, ['ficha_id' => $ficha->id]);
    }

    private function aprendiz(Ficha $ficha, string $subrol): User
    {
        return $this->usuario(Role::Aprendiz, [
            'subrole' => $subrol,
            'ficha_id' => $ficha->id,
        ]);
    }

    private function parametroId(string $tema, string $codigo): int
    {
        return (int) Parametro::porTemaYCodigo($tema, $codigo)->id;
    }

    /** Convocatoria en el estado indicado, con la fecha de cierre dada (en días respecto a hoy). */
    private function convocatoria(Ficha $ficha, User $creador, string $estado, ?int $diasParaCierre): Convocatoria
    {
        $hoy = now('America/Bogota');

        return Convocatoria::unguarded(fn () => Convocatoria::create([
            'ficha_id' => $ficha->id,
            'codigo' => sprintf('CONV-CER-%03d', ++self::$codigoSecuencia),
            'nombre' => "Convocatoria {$estado} ".self::$codigoSecuencia,
            'vacantes' => 1,
            'tipo_convocatoria_id' => $this->parametroId('tipo_convocatoria', 'externo'),
            'tipo_empleo_id' => $this->parametroId('tipo_empleo', 'tiempo_completo'),
            'tipo_contrato_id' => $this->parametroId('tipo_contrato', 'termino_fijo'),
            'modalidad_id' => $this->parametroId('modalidad', 'presencial'),
            'estado_id' => $this->parametroId('estado_convocatoria', $estado),
            'fecha_apertura' => $hoy->copy()->subDays(10)->toDateString(),
            'fecha_cierre' => $diasParaCierre === null ? null : $hoy->copy()->addDays($diasParaCierre)->toDateString(),
            'creado_por' => $creador->id,
        ]));
    }

    /** @return array{vigente: Convocatoria, vencida: Convocatoria, cerrada: Convocatoria, enProceso: Convocatoria} */
    private function cuatroConvocatorias(Ficha $ficha, User $creador): array
    {
        return [
            'vigente' => $this->convocatoria($ficha, $creador, 'publicada', 5),
            'vencida' => $this->convocatoria($ficha, $creador, 'publicada', -1),
            'cerrada' => $this->convocatoria($ficha, $creador, 'cerrada', -1),
            'enProceso' => $this->convocatoria($ficha, $creador, 'en_proceso', -1),
        ];
    }

    /** @return list<int> */
    private function ids(TestResponse $respuesta): array
    {
        $ids = array_map('intval', array_column($respuesta->json('data'), 'id'));
        sort($ids);

        return $ids;
    }

    /** @return list<int> */
    private function idsEsperados(Convocatoria ...$convocatorias): array
    {
        $ids = array_map(fn (Convocatoria $c) => (int) $c->id, $convocatorias);
        sort($ids);

        return $ids;
    }

    // ── Listado ─────────────────────────────────────────────────────────

    public function test_el_aspirante_solo_ve_la_publicada_vigente(): void
    {
        $ficha = $this->ficha();
        $c = $this->cuatroConvocatorias($ficha, $this->instructor($ficha));

        $respuesta = $this->actingAs($this->aspirante($ficha))->getJson('/api/convocatorias')->assertOk();

        $this->assertSame($this->idsEsperados($c['vigente']), $this->ids($respuesta));
        $this->assertSame(1, $respuesta->json('meta.total'));
    }

    public function test_el_aspirante_no_recibe_nada_al_filtrar_por_cerrada(): void
    {
        $ficha = $this->ficha();
        $this->cuatroConvocatorias($ficha, $this->instructor($ficha));
        $aspirante = $this->aspirante($ficha);

        $this->actingAs($aspirante)->getJson('/api/convocatorias?estado=cerrada')
            ->assertOk()->assertJsonCount(0, 'data');

        $this->actingAs($aspirante)->getJson('/api/convocatorias?estado=en_proceso')
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_el_aprendiz_general_no_ve_cerradas_pero_si_en_proceso(): void
    {
        $ficha = $this->ficha();
        $c = $this->cuatroConvocatorias($ficha, $this->instructor($ficha));

        $respuesta = $this->actingAs($this->aprendiz($ficha, 'general'))->getJson('/api/convocatorias')->assertOk();

        $this->assertSame($this->idsEsperados($c['vigente'], $c['enProceso']), $this->ids($respuesta));

        $this->actingAs($this->aprendiz($ficha, 'general'))->getJson('/api/convocatorias?estado=cerrada')
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_el_gestor_de_convocatorias_ve_las_cuatro(): void
    {
        $ficha = $this->ficha();
        $c = $this->cuatroConvocatorias($ficha, $this->instructor($ficha));
        $gestor = $this->aprendiz($ficha, 'gestor_convocatorias');

        $respuesta = $this->actingAs($gestor)->getJson('/api/convocatorias')->assertOk();

        $this->assertSame($this->idsEsperados($c['vigente'], $c['vencida'], $c['cerrada'], $c['enProceso']), $this->ids($respuesta));

        $this->actingAs($gestor)->getJson('/api/convocatorias?estado=cerrada')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $c['cerrada']->id);
    }

    public function test_el_instructor_y_el_super_admin_ven_las_cuatro(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $c = $this->cuatroConvocatorias($ficha, $instructor);
        $todas = $this->idsEsperados($c['vigente'], $c['vencida'], $c['cerrada'], $c['enProceso']);

        $this->assertSame($todas, $this->ids($this->actingAs($instructor)->getJson('/api/convocatorias')->assertOk()));
        $this->assertSame($todas, $this->ids($this->actingAs($this->usuario(Role::SuperAdmin))->getJson('/api/convocatorias')->assertOk()));
    }

    public function test_el_evaluador_no_tiene_acceso_al_listado(): void
    {
        $ficha = $this->ficha();
        $this->cuatroConvocatorias($ficha, $this->instructor($ficha));

        $this->actingAs($this->aprendiz($ficha, 'evaluador'))->getJson('/api/convocatorias')->assertForbidden();
    }

    public function test_el_conteo_y_la_paginacion_coinciden_con_lo_visible(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        for ($i = 0; $i < 3; $i++) {
            $this->convocatoria($ficha, $instructor, 'publicada', -1);
        }
        $vigente = $this->convocatoria($ficha, $instructor, 'publicada', 5);

        $respuesta = $this->actingAs($this->aspirante($ficha))->getJson('/api/convocatorias?per_page=1')->assertOk();

        $this->assertSame(1, $respuesta->json('meta.total'));
        $this->assertSame(1, $respuesta->json('meta.last_page'));
        $this->assertSame([$vigente->id], $this->ids($respuesta));
    }

    // ── Detalle ─────────────────────────────────────────────────────────

    public function test_el_aspirante_recibe_404_en_el_detalle_de_una_cerrada_y_200_en_la_vigente(): void
    {
        $ficha = $this->ficha();
        $c = $this->cuatroConvocatorias($ficha, $this->instructor($ficha));
        $aspirante = $this->aspirante($ficha);

        $this->actingAs($aspirante)->getJson("/api/convocatorias/{$c['vencida']->id}")->assertNotFound();
        $this->actingAs($aspirante)->getJson("/api/convocatorias/{$c['cerrada']->id}")->assertNotFound();
        $this->actingAs($aspirante)->getJson("/api/convocatorias/{$c['enProceso']->id}")->assertNotFound();
        $this->actingAs($aspirante)->getJson("/api/convocatorias/{$c['vigente']->id}")->assertOk();
    }

    public function test_el_aprendiz_general_recibe_404_en_el_detalle_de_una_cerrada(): void
    {
        $ficha = $this->ficha();
        $c = $this->cuatroConvocatorias($ficha, $this->instructor($ficha));
        $general = $this->aprendiz($ficha, 'general');

        $this->actingAs($general)->getJson("/api/convocatorias/{$c['vencida']->id}")->assertNotFound();
        $this->actingAs($general)->getJson("/api/convocatorias/{$c['cerrada']->id}")->assertNotFound();
        $this->actingAs($general)->getJson("/api/convocatorias/{$c['enProceso']->id}")->assertOk();
    }

    public function test_el_gestor_y_el_instructor_ven_el_detalle_de_una_cerrada(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $c = $this->cuatroConvocatorias($ficha, $instructor);

        $this->actingAs($instructor)->getJson("/api/convocatorias/{$c['cerrada']->id}")->assertOk();
        $this->actingAs($this->aprendiz($ficha, 'gestor_convocatorias'))
            ->getJson("/api/convocatorias/{$c['vencida']->id}")->assertOk();
    }

    // ── Mis postulaciones ───────────────────────────────────────────────

    public function test_mis_postulaciones_sigue_mostrando_la_convocatoria_cerrada_del_aspirante(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $c = $this->cuatroConvocatorias($ficha, $instructor);
        $aspirante = $this->aspirante($ficha);

        Postulacion::create([
            'convocatoria_id' => $c['vencida']->id,
            'user_id' => $aspirante->id,
            'estado_id' => $this->parametroId('estado_postulacion', 'postulada'),
            'fecha_postulacion' => now(),
        ]);

        $respuesta = $this->actingAs($aspirante)->getJson('/api/postulaciones')->assertOk();

        $this->assertContains($c['vencida']->id, array_map('intval', array_column(array_column($respuesta->json('data'), 'convocatoria'), 'id')));
    }

    public function test_no_se_puede_postular_a_una_convocatoria_cerrada(): void
    {
        $ficha = $this->ficha();
        $c = $this->cuatroConvocatorias($ficha, $this->instructor($ficha));
        $aspirante = $this->aspirante($ficha);

        $this->actingAs($aspirante)->postJson("/api/convocatorias/{$c['vencida']->id}/postulaciones")
            ->assertStatus(422);
        $this->actingAs($aspirante)->postJson("/api/convocatorias/{$c['cerrada']->id}/postulaciones")
            ->assertNotFound();
    }
}
