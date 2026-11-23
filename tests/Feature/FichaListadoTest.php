<?php

namespace Tests\Feature;

use App\Enums\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FichaCrudAyudas;
use Tests\TestCase;

class FichaListadoTest extends TestCase
{
    use RefreshDatabase;
    use FichaCrudAyudas;

    public function test_el_instructor_ve_sus_fichas_del_pivot_y_las_que_creo(): void
    {
        $delPivot = $this->ficha('1111111');
        $ajena = $this->ficha('2222222');

        $instructor = $this->instructor($delPivot);

        $creada = $this->actingAs($instructor)->postJson('/api/fichas', [
            'codigo' => '3333333',
            'nombre_programa' => 'Programación de Software',
        ])->assertCreated()->json('data');

        $this->actingAs($instructor->fresh())
            ->getJson('/api/fichas')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $delPivot->id)
            ->assertJsonPath('data.1.id', $creada['id']);

        $this->assertNotContains($ajena->id, $instructor->fresh()->fichaIds());
    }

    public function test_el_super_admin_ve_todas_las_fichas(): void
    {
        $this->ficha('1111111');
        $this->ficha('2222222');

        $this->actingAs($this->superAdmin())
            ->getJson('/api/fichas')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_el_listado_conserva_la_forma_de_los_selectores(): void
    {
        $ficha = $this->ficha('3173334');

        $this->actingAs($this->instructor($ficha))
            ->getJson('/api/fichas')
            ->assertOk()
            ->assertJsonPath('data.0.id', $ficha->id)
            ->assertJsonPath('data.0.codigo', '3173334')
            ->assertJsonPath('data.0.nombre_programa', 'Análisis y Desarrollo de Software')
            ->assertJsonPath('data.0.estado', 'activa');
    }

    public function test_el_aspirante_no_puede_listar_fichas(): void
    {
        $this->actingAs($this->usuario(Role::Aspirante))
            ->getJson('/api/fichas')
            ->assertForbidden();
    }

    // ── Alta ───────────────────────────────────────────────────────────

}
