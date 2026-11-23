<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Parametro;
use App\Models\ParametroTema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ConvocatoriaGestionAyudas;
use Tests\TestCase;

/**
 * ISO-01: un documento nuevo del catálogo solo lo ven las fichas de quien lo creó (y los documentos globales siguen
 * siendo de todos). ISO-05: el instructor no puede averiguar qué fichas existen fuera de su alcance.
 */
class AislamientoCatalogoYFichasTest extends TestCase
{
    use RefreshDatabase;
    use ConvocatoriaGestionAyudas;

    private function nombresDelCatalogo($usuario): array
    {
        return collect(
            $this->actingAs($usuario)->getJson('/api/parametros?tema=tipo_documento')->assertOk()->json('data')
        )->pluck('nombre')->all();
    }

    private function crearDocumento($usuario, string $nombre): int
    {
        return $this->actingAs($usuario)
            ->postJson('/api/parametros/documentos', ['nombre' => $nombre])
            ->assertCreated()
            ->json('data.id');
    }

    // ── ISO-01: catálogo de documentos por ficha ─────────────────────────────

    public function test_un_documento_nuevo_no_se_ve_en_otra_ficha(): void
    {
        $instructorA = $this->instructor($this->ficha('3173334'));
        $instructorB = $this->instructor($this->ficha('2631620'));

        $this->crearDocumento($instructorA, 'Diplomado de la ficha A');

        $this->assertNotContains('Diplomado de la ficha A', $this->nombresDelCatalogo($instructorB));

        // Tampoco en el catálogo completo (sin ?tema=).
        $grupos = $this->actingAs($instructorB)->getJson('/api/parametros')->assertOk()->json('data');
        $documentos = collect($grupos)->firstWhere('tema', 'tipo_documento')['parametros'];
        $this->assertNotContains('Diplomado de la ficha A', array_column($documentos, 'nombre'));
    }

    public function test_el_documento_lo_ven_el_creador_y_su_misma_ficha_y_el_super_admin(): void
    {
        $fichaA = $this->ficha('3173334');
        $creador = $this->instructor($fichaA);
        $companero = $this->instructor($fichaA);
        $superAdmin = $this->usuario(Role::SuperAdmin);

        $this->crearDocumento($creador, 'Maestría de la ficha A');

        $this->assertContains('Maestría de la ficha A', $this->nombresDelCatalogo($creador));
        $this->assertContains('Maestría de la ficha A', $this->nombresDelCatalogo($companero));
        $this->assertContains('Maestría de la ficha A', $this->nombresDelCatalogo($superAdmin));
    }

    public function test_un_documento_creado_por_el_super_admin_es_global(): void
    {
        $instructorB = $this->instructor($this->ficha('2631620'));

        $this->crearDocumento($this->usuario(Role::SuperAdmin), 'Documento global del sistema');

        $this->assertContains('Documento global del sistema', $this->nombresDelCatalogo($instructorB));
    }

    public function test_los_documentos_que_ya_existian_siguen_siendo_globales(): void
    {
        $tema = ParametroTema::where('codigo', 'tipo_documento')->firstOrFail();
        Parametro::create([
            'tema_id' => $tema->id, 'codigo' => null, 'nombre' => 'Documento anterior al aislamiento',
            'orden' => 99, 'activo' => true, 'es_sistema' => false, 'es_defecto' => false,
        ]);

        $instructorB = $this->instructor($this->ficha('2631620'));

        $this->assertContains('Documento anterior al aislamiento', $this->nombresDelCatalogo($instructorB));
    }

    public function test_los_documentos_de_sistema_se_ven_en_todas_las_fichas(): void
    {
        $nombres = $this->nombresDelCatalogo($this->instructor($this->ficha('2631620')));

        $this->assertContains('Hoja de vida', $nombres);
    }

    public function test_no_se_puede_usar_un_documento_de_otra_ficha_en_una_convocatoria(): void
    {
        $instructorA = $this->instructor($this->ficha('3173334'));
        $instructorB = $this->instructor($this->ficha('2631620'));
        $idDeA = $this->crearDocumento($instructorA, 'Especialización solo de la ficha A');

        $ajeno = $this->actingAs($instructorB)
            ->postJson('/api/convocatorias', ['nombre' => 'Convocatoria de B', 'documentos' => [['id' => $idDeA, 'ponderacion' => 10]]])
            ->assertStatus(422);
        $this->assertContains('documentos.0', array_keys($ajeno->json('errors') ?? []));

        $propio = $this->actingAs($instructorA)
            ->postJson('/api/convocatorias', ['nombre' => 'Convocatoria de A', 'documentos' => [['id' => $idDeA, 'ponderacion' => 10]]]);
        $this->assertNotContains('documentos.0', array_keys($propio->json('errors') ?? []));
    }

    // ── ISO-05: el instructor no averigua qué fichas existen ─────────────────

    public function test_listar_usuarios_con_una_ficha_ajena_o_inexistente_responde_igual(): void
    {
        $propia = $this->ficha('3173334');
        $ajena = $this->ficha('2631620');
        $instructor = $this->instructor($propia);
        $this->usuario(Role::Aprendiz, ['ficha_id' => $ajena->id]);

        $conAjena = $this->actingAs($instructor)->getJson("/api/usuarios?ficha_id={$ajena->id}")->assertOk();
        $conInexistente = $this->actingAs($instructor)->getJson('/api/usuarios?ficha_id=999999')->assertOk();

        $this->assertSame($conAjena->json('data'), $conInexistente->json('data'));
        $this->assertSame([], $conAjena->json('data'));
        $this->assertSame($conAjena->json('meta.total'), $conInexistente->json('meta.total'));
    }

    public function test_el_super_admin_sigue_viendo_error_si_la_ficha_no_existe(): void
    {
        $this->actingAs($this->usuario(Role::SuperAdmin))
            ->getJson('/api/usuarios?ficha_id=999999')
            ->assertStatus(422)
            ->assertJsonValidationErrors('ficha_id');
    }

    public function test_mover_a_una_ficha_ajena_o_inexistente_da_el_mismo_error(): void
    {
        $propia = $this->ficha('3173334');
        $ajena = $this->ficha('2631620');
        $instructor = $this->instructor($propia);
        $aspirante = $this->usuario(Role::Aspirante, ['ficha_id' => $propia->id]);

        $cuerpo = fn (int $ficha) => ['role' => 'aspirante', 'active' => true, 'ficha_id' => $ficha];

        $conAjena = $this->actingAs($instructor)->patchJson("/api/usuarios/{$aspirante->id}", $cuerpo($ajena->id))->assertStatus(422);
        $conInexistente = $this->actingAs($instructor)->patchJson("/api/usuarios/{$aspirante->id}", $cuerpo(999999))->assertStatus(422);

        $this->assertSame($conAjena->json('errors'), $conInexistente->json('errors'));
        $this->assertSame($conAjena->json('message'), $conInexistente->json('message'));
        $this->assertSame($propia->id, $aspirante->fresh()->ficha_id);
    }

    public function test_el_instructor_si_puede_mover_a_una_de_sus_fichas(): void
    {
        $primera = $this->ficha('3173334');
        $segunda = $this->ficha('2631620');
        $instructor = $this->instructor($primera, $segunda);
        $aspirante = $this->usuario(Role::Aspirante, ['ficha_id' => $primera->id]);

        $this->actingAs($instructor)
            ->patchJson("/api/usuarios/{$aspirante->id}", ['role' => 'aspirante', 'active' => true, 'ficha_id' => $segunda->id])
            ->assertOk();

        $this->assertSame($segunda->id, $aspirante->fresh()->ficha_id);
    }
}
