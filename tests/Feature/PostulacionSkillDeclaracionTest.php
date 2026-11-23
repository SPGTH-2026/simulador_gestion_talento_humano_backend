<?php

namespace Tests\Feature;

use App\Models\ConvocatoriaSkill;
use App\Models\PostulacionSkill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\PostulacionSkillAyudas;
use Tests\TestCase;

class PostulacionSkillDeclaracionTest extends TestCase
{
    use RefreshDatabase;
    use PostulacionSkillAyudas;

    public function test_declara_una_skill_propia_devuelve_422(): void
    {
        // Antes se podía declarar una skill libre; ahora solo las de la
        // convocatoria: un nombre propio se rechaza.
        [$aspirante, $postulacion] = $this->base();

        $this->actingAs($aspirante)
            ->postJson("/api/postulaciones/{$postulacion->id}/skills", ['nombre' => '  Liderazgo  '])
            ->assertStatus(422)
            ->assertJsonPath('errors.convocatoria_skill_id.0', 'Solo puedes marcar las skills que pide la convocatoria.');

        $this->assertDatabaseCount('postulacion_skills', 0);
    }

    public function test_declara_una_skill_de_la_convocatoria_devuelve_201(): void
    {
        [$aspirante, $postulacion, $convocatoria] = $this->base();
        $skill = $this->skillDeConvocatoria($convocatoria, 'Excel avanzado');

        $this->actingAs($aspirante)
            ->postJson("/api/postulaciones/{$postulacion->id}/skills", ['convocatoria_skill_id' => $skill->id])
            ->assertCreated()
            ->assertJsonPath('data.nombre', 'Excel avanzado')
            ->assertJsonPath('data.origen', 'convocatoria');

        $this->assertDatabaseHas('postulacion_skills', [
            'postulacion_id' => $postulacion->id,
            'convocatoria_skill_id' => $skill->id,
            'nombre' => 'Excel avanzado',
        ]);
    }

    public function test_skill_repetida_es_idempotente(): void
    {
        [$aspirante, $postulacion, $convocatoria] = $this->base();
        $skill = $this->skillDeConvocatoria($convocatoria, 'Excel');
        $url = "/api/postulaciones/{$postulacion->id}/skills";

        $this->actingAs($aspirante)->postJson($url, ['convocatoria_skill_id' => $skill->id])->assertCreated();
        $this->actingAs($aspirante)->postJson($url, ['convocatoria_skill_id' => $skill->id])->assertOk();

        $this->assertSame(1, PostulacionSkill::count());
    }

    public function test_skill_de_otra_convocatoria_devuelve_422(): void
    {
        [$aspirante, $postulacion, $convocatoria] = $this->base();
        $otra = $this->convocatoriaAbierta($convocatoria->ficha, $this->instructor($convocatoria->ficha));
        $ajena = ConvocatoriaSkill::create(['convocatoria_id' => $otra->id, 'nombre' => 'Power BI']);

        $this->actingAs($aspirante)
            ->postJson("/api/postulaciones/{$postulacion->id}/skills", ['convocatoria_skill_id' => $ajena->id])
            ->assertStatus(422)
            ->assertJsonPath('errors.convocatoria_skill_id.0', 'Esa skill no hace parte de la convocatoria.');

        $this->assertDatabaseCount('postulacion_skills', 0);
    }

    public function test_skill_sin_nombre_ni_id_devuelve_422(): void
    {
        [$aspirante, $postulacion] = $this->base();

        $this->actingAs($aspirante)
            ->postJson("/api/postulaciones/{$postulacion->id}/skills", [])
            ->assertStatus(422)
            ->assertJsonPath('errors.convocatoria_skill_id.0', 'Debes marcar una de las skills que pide la convocatoria.');
    }

    public function test_nombre_libre_se_rechaza_por_mas_de_100_caracteres(): void
    {
        // El límite de 100 caracteres se aplica al nombre de la skill de la
        // CONVOCATORIA (ver ConvocatoriaGestionTest); aquí se comprueba que un
        // nombre libre (del POST) no se acepta ni siquiera siendo larguísimo.
        [$aspirante, $postulacion] = $this->base();

        $this->actingAs($aspirante)
            ->postJson("/api/postulaciones/{$postulacion->id}/skills", ['nombre' => str_repeat('a', 101)])
            ->assertStatus(422)
            ->assertJsonPath('errors.convocatoria_skill_id.0', 'Solo puedes marcar las skills que pide la convocatoria.');
    }

    public function test_tope_de_30_skills_devuelve_422(): void
    {
        [$aspirante, $postulacion, $convocatoria] = $this->base();
        $url = "/api/postulaciones/{$postulacion->id}/skills";

        for ($i = 1; $i <= 30; $i++) {
            $skill = $this->skillDeConvocatoria($convocatoria, "Skill {$i}");
            $this->actingAs($aspirante)->postJson($url, ['convocatoria_skill_id' => $skill->id])->assertCreated();
        }

        $extra = $this->skillDeConvocatoria($convocatoria, 'Skill 31');

        $this->actingAs($aspirante)
            ->postJson($url, ['convocatoria_skill_id' => $extra->id])
            ->assertStatus(422)
            ->assertJsonPath('errors.convocatoria_skill_id.0', 'No puedes registrar más de 30 skills en tu postulación.');

        $this->assertSame(30, PostulacionSkill::count());
    }

    // ── Las formas antiguas (catálogo y nombre propio) se rechazan ──────

    public function test_declara_una_skill_del_catalogo_devuelve_422(): void
    {
        [$aspirante, $postulacion] = $this->base();
        $parametroId = $this->parametroId('skill_catalogo', 'excel');

        $this->actingAs($aspirante)
            ->postJson("/api/postulaciones/{$postulacion->id}/skills", ['parametro_id' => $parametroId])
            ->assertStatus(422)
            ->assertJsonPath('errors.convocatoria_skill_id.0', 'Solo puedes marcar las skills que pide la convocatoria.');

        $this->assertDatabaseCount('postulacion_skills', 0);
    }

    public function test_parametro_de_otro_tema_devuelve_422(): void
    {
        [$aspirante, $postulacion] = $this->base();
        $ajeno = $this->parametroId('sexo', 'hombre');

        $this->actingAs($aspirante)
            ->postJson("/api/postulaciones/{$postulacion->id}/skills", ['parametro_id' => $ajeno])
            ->assertStatus(422)
            ->assertJsonPath('errors.convocatoria_skill_id.0', 'Solo puedes marcar las skills que pide la convocatoria.');

        $this->assertDatabaseCount('postulacion_skills', 0);
    }

    public function test_parametro_inexistente_devuelve_422(): void
    {
        [$aspirante, $postulacion] = $this->base();

        $this->actingAs($aspirante)
            ->postJson("/api/postulaciones/{$postulacion->id}/skills", ['parametro_id' => 999999])
            ->assertStatus(422)
            ->assertJsonPath('errors.convocatoria_skill_id.0', 'Solo puedes marcar las skills que pide la convocatoria.');
    }

    public function test_enviar_dos_formas_devuelve_422(): void
    {
        [$aspirante, $postulacion, $convocatoria] = $this->base();
        $skill = $this->skillDeConvocatoria($convocatoria, 'Excel avanzado');
        $url = "/api/postulaciones/{$postulacion->id}/skills";

        // convocatoria_skill_id + nombre.
        $this->actingAs($aspirante)
            ->postJson($url, ['convocatoria_skill_id' => $skill->id, 'nombre' => 'Otra'])
            ->assertStatus(422)
            ->assertJsonPath('errors.convocatoria_skill_id.0', 'Solo puedes marcar las skills que pide la convocatoria.');

        // parametro_id + nombre.
        $this->actingAs($aspirante)
            ->postJson($url, ['parametro_id' => $this->parametroId('skill_catalogo', 'excel'), 'nombre' => 'Otra'])
            ->assertStatus(422)
            ->assertJsonPath('errors.convocatoria_skill_id.0', 'Solo puedes marcar las skills que pide la convocatoria.');

        // convocatoria_skill_id + parametro_id.
        $this->actingAs($aspirante)
            ->postJson($url, ['convocatoria_skill_id' => $skill->id, 'parametro_id' => $this->parametroId('skill_catalogo', 'excel')])
            ->assertStatus(422)
            ->assertJsonPath('errors.convocatoria_skill_id.0', 'Solo puedes marcar las skills que pide la convocatoria.');

        $this->assertDatabaseCount('postulacion_skills', 0);
    }

    public function test_skill_del_catalogo_repetida_por_nombre_devuelve_422(): void
    {
        [$aspirante, $postulacion] = $this->base();
        $url = "/api/postulaciones/{$postulacion->id}/skills";

        $this->actingAs($aspirante)
            ->postJson($url, ['parametro_id' => $this->parametroId('skill_catalogo', 'excel')])
            ->assertStatus(422);

        // La misma skill, ahora como texto libre: también se rechaza.
        $this->actingAs($aspirante)
            ->postJson($url, ['nombre' => 'excel'])
            ->assertStatus(422);

        $this->assertSame(0, PostulacionSkill::count());
    }

    public function test_skill_de_convocatoria_repetida_por_catalogo_devuelve_422(): void
    {
        [$aspirante, $postulacion, $convocatoria] = $this->base();
        $skill = $this->skillDeConvocatoria($convocatoria, 'Excel');
        $url = "/api/postulaciones/{$postulacion->id}/skills";

        $this->actingAs($aspirante)
            ->postJson($url, ['convocatoria_skill_id' => $skill->id])
            ->assertCreated()
            ->assertJsonPath('data.origen', 'convocatoria');

        // El catálogo tiene "Excel" (mismo nombre): ya no se puede declarar.
        $this->actingAs($aspirante)
            ->postJson($url, ['parametro_id' => $this->parametroId('skill_catalogo', 'excel')])
            ->assertStatus(422);

        $this->assertSame(1, PostulacionSkill::count());
    }

}
