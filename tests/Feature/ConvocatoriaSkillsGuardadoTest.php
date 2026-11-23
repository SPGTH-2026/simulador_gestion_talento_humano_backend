<?php

namespace Tests\Feature;

use App\Models\ConvocatoriaSkill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ConvocatoriaSkillsCatalogoAyudas;
use Tests\TestCase;

class ConvocatoriaSkillsGuardadoTest extends TestCase
{
    use RefreshDatabase;
    use ConvocatoriaSkillsCatalogoAyudas;

    public function test_una_skill_del_catalogo_se_guarda_con_su_parametro_id(): void
    {
        $instructor = $this->instructor($this->ficha());
        $excel = $this->parametro('skill_catalogo', 'excel');

        $respuesta = $this->actingAs($instructor)->postJson('/api/convocatorias', [
            'nombre' => 'Con skills de catálogo',
            'skills' => [
                ['nombre' => 'Excel', 'parametro_id' => $excel->id],
            ],
        ])->assertCreated();

        // El nombre lo pone el catálogo, no el cliente.
        $this->assertSame(['Excel'], $respuesta->json('data.skills'));

        $detalle = $respuesta->json('data.skills_detalle');
        $this->assertCount(1, $detalle);
        $this->assertSame('Excel', $detalle[0]['nombre']);
        $this->assertSame((int) $excel->id, $detalle[0]['parametro_id']);
        $this->assertIsInt($detalle[0]['id']);

        $guardada = ConvocatoriaSkill::sole();
        $this->assertSame('Excel', $guardada->nombre);
        $this->assertSame((int) $excel->id, (int) $guardada->parametro_id);
    }

    public function test_el_nombre_enviado_por_el_cliente_se_ignora_si_viene_del_catalogo(): void
    {
        $instructor = $this->instructor($this->ficha());
        $excel = $this->parametro('skill_catalogo', 'excel');

        $respuesta = $this->actingAs($instructor)->postJson('/api/convocatorias', [
            'nombre' => 'Nombre inventado',
            'skills' => [
                ['nombre' => 'Nombre inventado', 'parametro_id' => $excel->id],
            ],
        ])->assertCreated();

        $this->assertSame(['Excel'], $respuesta->json('data.skills'));
    }

    public function test_una_skill_de_catalogo_y_otra_libre_conviven(): void
    {
        $instructor = $this->instructor($this->ficha());
        $excel = $this->parametro('skill_catalogo', 'excel');

        $respuesta = $this->actingAs($instructor)->postJson('/api/convocatorias', [
            'nombre' => 'Mixta',
            'skills' => [
                ['nombre' => 'Excel', 'parametro_id' => $excel->id],
                ['nombre' => 'SAP HR'],
            ],
        ])->assertCreated();

        $this->assertSame(['Excel', 'SAP HR'], $respuesta->json('data.skills'));

        $detalle = collect($respuesta->json('data.skills_detalle'))->keyBy('nombre');
        $this->assertSame((int) $excel->id, $detalle['Excel']['parametro_id']);
        $this->assertNull($detalle['SAP HR']['parametro_id']);
    }

    public function test_el_texto_libre_sigue_siendo_valido_sin_parametro_id(): void
    {
        $instructor = $this->instructor($this->ficha());

        $respuesta = $this->actingAs($instructor)->postJson('/api/convocatorias', [
            'nombre' => 'Solo texto libre',
            'skills' => ['Excel avanzado'],
        ])->assertCreated();

        $this->assertSame(['Excel avanzado'], $respuesta->json('data.skills'));
        $this->assertNull(ConvocatoriaSkill::sole()->parametro_id);
    }

    public function test_parametro_id_inexistente_devuelve_422(): void
    {
        $instructor = $this->instructor($this->ficha());

        $respuesta = $this->actingAs($instructor)
            ->postJson('/api/convocatorias', [
                'nombre' => 'X',
                'skills' => [['nombre' => 'Inventada', 'parametro_id' => 999999]],
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['skills.0']]);

        $this->assertSame('La skill seleccionada no es válida.', $respuesta->json('errors')['skills.0'][0]);
        $this->assertSame(0, ConvocatoriaSkill::count());
    }

    public function test_parametro_id_de_otro_tema_devuelve_422(): void
    {
        $instructor = $this->instructor($this->ficha());
        $ajeno = $this->parametroId('sexo', 'hombre');

        $respuesta = $this->actingAs($instructor)
            ->postJson('/api/convocatorias', [
                'nombre' => 'X',
                'skills' => [['nombre' => 'Hombre', 'parametro_id' => $ajeno]],
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['skills.0']]);

        $this->assertSame('La skill seleccionada no es válida.', $respuesta->json('errors')['skills.0'][0]);
        $this->assertSame(0, ConvocatoriaSkill::count());
    }

    public function test_parametro_id_de_un_skill_inactivo_devuelve_422(): void
    {
        $instructor = $this->instructor($this->ficha());
        $parametro = $this->parametro('skill_catalogo', 'excel');
        $parametro->forceFill(['activo' => false])->save();

        $respuesta = $this->actingAs($instructor)
            ->postJson('/api/convocatorias', [
                'nombre' => 'X',
                'skills' => [['nombre' => 'Excel', 'parametro_id' => $parametro->id]],
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['skills.0']]);

        $this->assertSame('La skill seleccionada no es válida.', $respuesta->json('errors')['skills.0'][0]);
    }

    public function test_editar_reemplaza_las_skills_y_conserva_el_parametro_id(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $excel = $this->parametro('skill_catalogo', 'excel');
        $liderazgo = $this->parametro('skill_catalogo', 'liderazgo');

        $convocatoria = $this->crearConvocatoria($ficha, $instructor);

        $this->actingAs($instructor)
            ->patchJson('/api/convocatorias/'.$convocatoria->id, [
                'skills' => [
                    ['nombre' => 'Liderazgo', 'parametro_id' => $liderazgo->id],
                ],
            ])->assertOk();

        $this->assertSame(['Liderazgo'], $convocatoria->fresh()->skills->pluck('nombre')->all());
        $this->assertSame((int) $liderazgo->id, (int) ConvocatoriaSkill::sole()->parametro_id);

        // Y al revés: quitar la skill del catálogo deja la fila en NULL.
        $this->actingAs($instructor)
            ->patchJson('/api/convocatorias/'.$convocatoria->id, [
                'skills' => [
                    ['nombre' => 'Excel', 'parametro_id' => $excel->id],
                ],
            ])->assertOk();

        $this->assertSame((int) $excel->id, (int) ConvocatoriaSkill::sole()->parametro_id);
    }

}
