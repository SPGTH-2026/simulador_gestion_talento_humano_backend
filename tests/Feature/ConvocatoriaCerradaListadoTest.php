<?php

namespace Tests\Feature;

use App\Enums\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ConvocatoriaCerradaOcultaAyudas;
use Tests\TestCase;

class ConvocatoriaCerradaListadoTest extends TestCase
{
    use RefreshDatabase;
    use ConvocatoriaCerradaOcultaAyudas;

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

}
