<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ConvocatoriaGestionAyudas;
use Tests\TestCase;

class ConvocatoriaListadoTest extends TestCase
{
    use RefreshDatabase;
    use ConvocatoriaGestionAyudas;

    public function test_listado_paginado_y_ordenado(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);

        $this->crearConvocatoria($ficha, $instructor, ['codigo' => 'C-1', 'nombre' => 'Uno', 'fecha_apertura' => '2026-10-01']);
        $this->crearConvocatoria($ficha, $instructor, ['codigo' => 'C-2', 'nombre' => 'Dos', 'fecha_apertura' => '2026-10-03']);
        $this->crearConvocatoria($ficha, $instructor, ['codigo' => 'C-3', 'nombre' => 'Tres', 'fecha_apertura' => '2026-10-02']);

        $respuesta = $this->actingAs($instructor)
            ->getJson('/api/convocatorias?per_page=2&page=1')
            ->assertOk();

        $this->assertCount(2, $respuesta->json('data'));
        $this->assertSame('Dos', $respuesta->json('data.0.nombre'));
        $this->assertSame('Tres', $respuesta->json('data.1.nombre'));
        $this->assertSame(3, $respuesta->json('meta.total'));
        $this->assertSame(2, $respuesta->json('meta.last_page'));
        $this->assertSame(2, $respuesta->json('meta.per_page'));
    }

    public function test_listado_no_muestra_otras_fichas(): void
    {
        $fichaA = $this->ficha('3173334');
        $fichaB = $this->ficha('2631620');
        $instructorA = $this->instructor($fichaA);
        $instructorB = $this->instructor($fichaB);

        $this->crearConvocatoria($fichaA, $instructorA, ['codigo' => 'A-1']);
        $this->crearConvocatoria($fichaB, $instructorB, ['codigo' => 'B-1']);

        $respuesta = $this->actingAs($instructorA)->getJson('/api/convocatorias')->assertOk();

        $this->assertCount(1, $respuesta->json('data'));
        $this->assertSame('A-1', $respuesta->json('data.0.codigo'));
    }

    public function test_aspirante_solo_ve_publicadas(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);

        $this->crearConvocatoria($ficha, $instructor, ['codigo' => 'BOR-1', 'nombre' => 'Borrador']);
        $this->crearConvocatoria($ficha, $instructor, [
            'codigo' => 'PUB-1',
            'nombre' => 'Publicada',
            'estado_id' => $this->parametroId('estado_convocatoria', 'publicada'),
        ]);

        $respuesta = $this->actingAs($aspirante)->getJson('/api/convocatorias')->assertOk();

        $this->assertCount(1, $respuesta->json('data'));
        // El código (CONV-AAAA-NNN) es un dato de gestión: el aspiring candidate no lo recibe.
        $this->assertNull($respuesta->json('data.0.codigo'));
    }

    public function test_listado_filtra_por_estado(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);

        $this->crearConvocatoria($ficha, $instructor, ['codigo' => 'BOR-1']);
        $this->crearConvocatoria($ficha, $instructor, [
            'codigo' => 'PUB-1',
            'estado_id' => $this->parametroId('estado_convocatoria', 'publicada'),
        ]);

        $respuesta = $this->actingAs($instructor)
            ->getJson('/api/convocatorias?estado=publicada')
            ->assertOk();

        $this->assertCount(1, $respuesta->json('data'));
        $this->assertSame('PUB-1', $respuesta->json('data.0.codigo'));
    }

    public function test_listado_estado_invalido(): void
    {
        $instructor = $this->instructor($this->ficha());

        $this->actingAs($instructor)
            ->getJson('/api/convocatorias?estado=no_existe')
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['estado']]);
    }

    public function test_listado_filtra_por_ficha(): void
    {
        $fichaA = $this->ficha('3173334');
        $fichaB = $this->ficha('2631620');
        // El instructor tiene las dos fichas: el filtro elige entre ambas.
        $instructor = $this->instructor($fichaA, $fichaB);

        $this->crearConvocatoria($fichaA, $instructor, ['codigo' => 'A-1']);
        $this->crearConvocatoria($fichaB, $instructor, ['codigo' => 'B-1']);

        $respuesta = $this->actingAs($instructor)
            ->getJson('/api/convocatorias?ficha_id='.$fichaB->id)
            ->assertOk();

        $this->assertCount(1, $respuesta->json('data'));
        $this->assertSame('B-1', $respuesta->json('data.0.codigo'));
    }

    public function test_listado_filtro_por_ficha_no_amplia_el_alcance(): void
    {
        $fichaA = $this->ficha('3173334');
        $fichaB = $this->ficha('2631620');
        // El instructor solo tiene la ficha A: pedir la B no revela nada.
        $instructor = $this->instructor($fichaA);

        $this->crearConvocatoria($fichaA, $instructor, ['codigo' => 'A-1']);

        $respuesta = $this->actingAs($instructor)
            ->getJson('/api/convocatorias?ficha_id='.$fichaB->id)
            ->assertOk();

        $this->assertCount(0, $respuesta->json('data'));
    }

    public function test_listado_ficha_invalida(): void
    {
        $instructor = $this->instructor($this->ficha());

        $this->actingAs($instructor)
            ->getJson('/api/convocatorias?ficha_id=999999')
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['ficha_id']]);
    }

    public function test_listado_per_page_fuera_de_rango(): void
    {
        $instructor = $this->instructor($this->ficha());

        $this->actingAs($instructor)
            ->getJson('/api/convocatorias?per_page=200')
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['per_page']]);
    }

    // ── Detalle ────────────────────────────────────────────────────────

}
