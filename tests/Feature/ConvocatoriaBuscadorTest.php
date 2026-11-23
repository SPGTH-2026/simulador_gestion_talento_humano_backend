<?php

namespace Tests\Feature;

use App\Models\Ficha;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ConvocatoriaGestionAyudas;
use Tests\TestCase;

class ConvocatoriaBuscadorTest extends TestCase
{
    use RefreshDatabase;
    use ConvocatoriaGestionAyudas;

    public function test_buscador_por_nombre_ignora_mayusculas_y_espacios(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $this->crearConvocatoria($ficha, $instructor, ['codigo' => 'C-1', 'nombre' => 'Instructor de inglés']);
        $this->crearConvocatoria($ficha, $instructor, ['codigo' => 'C-2', 'nombre' => 'Coordinador académico']);

        $respuesta = $this->actingAs($instructor)
            ->getJson('/api/convocatorias?q='.urlencode('  INSTRUCTOR  '))
            ->assertOk();

        $this->assertSame(['C-1'], array_column($respuesta->json('data'), 'codigo'));
    }

    public function test_buscador_por_codigo(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $this->crearConvocatoria($ficha, $instructor, ['codigo' => 'CONV-2026-500', 'nombre' => 'Alpha']);
        $this->crearConvocatoria($ficha, $instructor, ['codigo' => 'CONV-2026-501', 'nombre' => 'Beta']);

        $respuesta = $this->actingAs($instructor)
            ->getJson('/api/convocatorias?q=2026-500')
            ->assertOk();

        $this->assertSame(['CONV-2026-500'], array_column($respuesta->json('data'), 'codigo'));
    }

    public function test_buscador_por_cargo(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $this->crearConvocatoria($ficha, $instructor, ['codigo' => 'C-1', 'nombre' => 'A', 'cargo' => 'Instructor']);
        $this->crearConvocatoria($ficha, $instructor, ['codigo' => 'C-2', 'nombre' => 'B', 'cargo' => 'Coordinador']);

        $respuesta = $this->actingAs($instructor)
            ->getJson('/api/convocatorias?q=coord')
            ->assertOk();

        $this->assertSame(['C-2'], array_column($respuesta->json('data'), 'codigo'));
    }

    public function test_buscador_se_combina_con_estado(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $this->crearConvocatoria($ficha, $instructor, ['codigo' => 'MD-1', 'nombre' => 'Docente']);
        $this->crearConvocatoria($ficha, $instructor, [
            'codigo' => 'MD-2',
            'nombre' => 'Docente',
            'estado_id' => $this->parametroId('estado_convocatoria', 'publicada'),
        ]);

        $respuesta = $this->actingAs($instructor)
            ->getJson('/api/convocatorias?q=Docente&estado=publicada')
            ->assertOk();

        $this->assertSame(['MD-2'], array_column($respuesta->json('data'), 'codigo'));
    }

    public function test_buscador_sin_resultados_devuelve_meta_correcta(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $this->crearConvocatoria($ficha, $instructor, ['codigo' => 'C-1', 'nombre' => 'Alpha']);

        $respuesta = $this->actingAs($instructor)
            ->getJson('/api/convocatorias?q=zzzznoexiste')
            ->assertOk();

        $this->assertSame([], $respuesta->json('data'));
        $this->assertSame(0, $respuesta->json('meta.total'));
        $this->assertSame(1, $respuesta->json('meta.last_page'));
        $this->assertSame(1, $respuesta->json('meta.current_page'));
        $this->assertSame(20, $respuesta->json('meta.per_page'));
    }

    public function test_buscador_de_aspirante_no_ve_borradores(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $this->crearConvocatoria($ficha, $instructor, ['codigo' => 'BX-1', 'nombre' => 'BusquedaX']);
        $this->crearConvocatoria($ficha, $instructor, [
            'codigo' => 'BX-2',
            'nombre' => 'BusquedaX',
            'estado_id' => $this->parametroId('estado_convocatoria', 'publicada'),
        ]);

        $respuesta = $this->actingAs($aspirante)
            ->getJson('/api/convocatorias?q=BusquedaX')
            ->assertOk();

        // Solo ve la publicada (y sin código: es un dato de gestión).
        $this->assertCount(1, $respuesta->json('data'));
        $this->assertNull($respuesta->json('data.0.codigo'));
        $this->assertSame('BusquedaX', $respuesta->json('data.0.nombre'));
    }

    public function test_buscador_por_codigo_solo_lo_usa_quien_gestiona(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $this->crearConvocatoria($ficha, $instructor, [
            'codigo' => 'BUS-9',
            'nombre' => 'Nombre normal',
            'estado_id' => $this->parametroId('estado_convocatoria', 'publicada'),
        ]);

        // Quien gestiona: el código es un criterio de búsqueda válido.
        $delGestor = $this->actingAs($instructor)
            ->getJson('/api/convocatorias?q=BUS-9')
            ->assertOk();
        $this->assertCount(1, $delGestor->json('data'));
        $this->assertSame('BUS-9', $delGestor->json('data.0.codigo'));

        // El Aspirante no busca por código (ni lo recibe): 0 resultados.
        $delAspirante = $this->actingAs($aspirante)
            ->getJson('/api/convocatorias?q=BUS-9')
            ->assertOk();
        $this->assertSame([], $delAspirante->json('data'));
    }

    public function test_buscador_no_cruza_fichas(): void
    {
        $fichaA = $this->ficha('3173334');
        $fichaB = $this->ficha('2631620');
        $instructorA = $this->instructor($fichaA);
        $instructorB = $this->instructor($fichaB);
        $this->crearConvocatoria($fichaB, $instructorB, ['codigo' => 'FB-1', 'nombre' => 'Secreto Ficha B']);

        $respuesta = $this->actingAs($instructorA)
            ->getJson('/api/convocatorias?q=Secreto')
            ->assertOk();

        $this->assertSame([], $respuesta->json('data'));
    }

    public function test_buscador_trata_comodines_como_texto_literal(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $this->crearConvocatoria($ficha, $instructor, ['codigo' => 'W-1', 'nombre' => '100% efectivo']);
        $this->crearConvocatoria($ficha, $instructor, ['codigo' => 'W-2', 'nombre' => '100X efectivo']);
        $this->crearConvocatoria($ficha, $instructor, ['codigo' => 'W-3', 'nombre' => 'soporte_n1']);
        $this->crearConvocatoria($ficha, $instructor, ['codigo' => 'W-4', 'nombre' => 'soporteXn1']);

        $porcentaje = $this->actingAs($instructor)
            ->getJson('/api/convocatorias?'.http_build_query(['q' => '100%']))
            ->assertOk();
        $this->assertSame(['W-1'], array_column($porcentaje->json('data'), 'codigo'));

        $underscore = $this->actingAs($instructor)
            ->getJson('/api/convocatorias?'.http_build_query(['q' => 'soporte_n1']))
            ->assertOk();
        $this->assertSame(['W-3'], array_column($underscore->json('data'), 'codigo'));
    }

    // ── Porcentajes de los documentos (ponderación por convocatoria) ─────

}
