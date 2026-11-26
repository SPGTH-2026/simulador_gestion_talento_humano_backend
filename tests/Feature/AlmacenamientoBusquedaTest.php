<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Ficha;
use App\Models\Postulacion;
use App\Models\PostulacionDocumento;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EvaluacionAyudas;
use Tests\TestCase;

/** Búsqueda y filtros de los documentos de una ficha, y selección de todos los resultados. */
class AlmacenamientoBusquedaTest extends TestCase
{
    use RefreshDatabase;
    use EvaluacionAyudas;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\ParametroSeeder::class);
    }

    private function adicional(Postulacion $postulacion, string $titulo): void
    {
        PostulacionDocumento::query()->create([
            'postulacion_id' => $postulacion->id,
            'parametro_id' => null,
            'titulo' => $titulo,
            'nombre_original' => 'x.pdf',
            'ruta' => "fichas/x/{$postulacion->id}/{$titulo}.pdf",
            'disco' => 'local',
            'mime' => 'application/pdf',
            'tamano_bytes' => 10,
        ]);
    }

    /**
     * Ficha con dos aspirantes. Cada uno: un adicional («diploma») y el requisito obligatorio («hoja de vida»).
     * Devuelve la ficha y el instructor que la administra.
     */
    private function escenario(): array
    {
        $ficha = $this->ficha('2896411');
        $instructor = $this->usuario(Role::Instructor);
        $ficha->instructores()->attach($instructor->id);
        $convocatoria = $this->convocatoria($ficha, $instructor);
        $hoja = $this->parametroId('tipo_documento', 'hoja_de_vida');
        $convocatoria->documentos()->attach($hoja, ['obligatorio' => true, 'ponderacion' => 100]);

        foreach ([['Ana Pérez', 'ana.perez@test.com'], ['Luis Gómez', 'luis.gomez@test.com']] as [$nombre, $correo]) {
            $aspirante = $this->usuario(Role::Aspirante, null, ['ficha_id' => $ficha->id, 'name' => $nombre, 'email' => $correo]);
            $postulacion = $this->postulacion($convocatoria, $aspirante);
            $this->adicional($postulacion, 'diploma de '.explode(' ', $nombre)[0]);
            PostulacionDocumento::query()->create([
                'postulacion_id' => $postulacion->id,
                'parametro_id' => $hoja,
                'nombre_original' => 'hoja.pdf',
                'ruta' => "fichas/x/{$postulacion->id}/hoja.pdf",
                'disco' => 'local',
                'mime' => 'application/pdf',
                'tamano_bytes' => 10,
            ]);
        }

        return [$ficha, $instructor];
    }

    /** @return list<string> títulos de los documentos de la respuesta */
    private function titulos(string $url, User $actor): array
    {
        return collect($this->actingAs($actor)->getJson($url)->assertOk()->json('data'))
            ->map(fn (array $d) => $d['titulo'])
            ->sort()
            ->values()
            ->all();
    }

    public function test_busca_por_el_nombre_del_aspirante(): void
    {
        [$ficha, $instructor] = $this->escenario();

        $titulos = $this->titulos("/api/almacenamiento/fichas/{$ficha->id}/documentos?q=luis", $instructor);

        // Solo los dos documentos de Luis: su adicional y su requisito. Los de Ana no aparecen.
        $this->assertCount(2, $titulos);
        $this->assertContains('diploma de Luis', $titulos);
        $this->assertNotContains('diploma de Ana', $titulos);
    }

    public function test_busca_por_correo_y_no_distingue_mayusculas(): void
    {
        [$ficha, $instructor] = $this->escenario();

        $respuesta = $this->actingAs($instructor)->getJson("/api/almacenamiento/fichas/{$ficha->id}/documentos?q=ANA.PEREZ@TEST")->assertOk();

        $this->assertSame(2, $respuesta->json('meta.total'));
    }

    public function test_busca_por_el_titulo_del_documento(): void
    {
        [$ficha, $instructor] = $this->escenario();

        $respuesta = $this->actingAs($instructor)->getJson("/api/almacenamiento/fichas/{$ficha->id}/documentos?q=diploma")->assertOk();

        $this->assertSame(2, $respuesta->json('meta.total'));
        $this->assertSame(['adicional', 'adicional'], collect($respuesta->json('data'))->pluck('tipo')->all());
    }

    public function test_busca_por_el_nombre_de_un_requisito(): void
    {
        [$ficha, $instructor] = $this->escenario();

        $respuesta = $this->actingAs($instructor)->getJson("/api/almacenamiento/fichas/{$ficha->id}/documentos?q=hoja")->assertOk();

        $this->assertSame(2, $respuesta->json('meta.total'));
        $this->assertSame(['requerido', 'requerido'], collect($respuesta->json('data'))->pluck('tipo')->all());
    }

    public function test_el_filtro_de_tipo_separa_adicionales_y_requeridos(): void
    {
        [$ficha, $instructor] = $this->escenario();

        $this->actingAs($instructor)->getJson("/api/almacenamiento/fichas/{$ficha->id}/documentos?tipo=adicional")
            ->assertJsonPath('meta.total', 2);
        $this->actingAs($instructor)->getJson("/api/almacenamiento/fichas/{$ficha->id}/documentos?tipo=requerido")
            ->assertJsonPath('meta.total', 2);
    }

    public function test_una_busqueda_sin_resultados_responde_lista_vacia(): void
    {
        [$ficha, $instructor] = $this->escenario();

        $this->actingAs($instructor)->getJson("/api/almacenamiento/fichas/{$ficha->id}/documentos?q=nadie-asi")
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
    }

    public function test_seleccionar_todos_cubre_todas_las_paginas_y_solo_los_borrables(): void
    {
        $ficha = $this->ficha('2896411');
        $instructor = $this->usuario(Role::Instructor);
        $ficha->instructores()->attach($instructor->id);
        $convocatoria = $this->convocatoria($ficha, $instructor);
        $hoja = $this->parametroId('tipo_documento', 'hoja_de_vida');
        $convocatoria->documentos()->attach($hoja, ['obligatorio' => true, 'ponderacion' => 100]);

        $aspirante = $this->usuario(Role::Aspirante, null, ['ficha_id' => $ficha->id]);
        $postulacion = $this->postulacion($convocatoria, $aspirante);
        // 25 adicionales (más de una página de 20) y un obligatorio que no debe entrar en la selección.
        for ($i = 1; $i <= 25; $i++) {
            $this->adicional($postulacion, "extra {$i}");
        }
        PostulacionDocumento::query()->create([
            'postulacion_id' => $postulacion->id,
            'parametro_id' => $hoja,
            'nombre_original' => 'hoja.pdf',
            'ruta' => "fichas/x/{$postulacion->id}/hoja.pdf",
            'disco' => 'local',
            'mime' => 'application/pdf',
            'tamano_bytes' => 10,
        ]);

        $respuesta = $this->actingAs($instructor)->getJson("/api/almacenamiento/fichas/{$ficha->id}/documentos/ids")->assertOk();

        $this->assertSame(25, $respuesta->json('data.total'));
        $this->assertCount(25, $respuesta->json('data.ids'));
    }

    public function test_seleccionar_todos_respeta_la_busqueda(): void
    {
        [$ficha, $instructor] = $this->escenario();

        $respuesta = $this->actingAs($instructor)->getJson("/api/almacenamiento/fichas/{$ficha->id}/documentos/ids?q=luis")->assertOk();

        // Solo el adicional de Luis es borrable: su hoja de vida es obligatoria.
        $this->assertSame(1, $respuesta->json('data.total'));
    }

    public function test_el_aprendiz_general_busca_pero_no_obtiene_ids_para_borrar(): void
    {
        [$ficha] = $this->escenario();
        $general = $this->usuario(Role::Aprendiz, 'general', ['ficha_id' => $ficha->id]);

        $this->actingAs($general)->getJson("/api/almacenamiento/fichas/{$ficha->id}/documentos?q=ana")->assertOk()
            ->assertJsonPath('meta.total', 2);
        $this->actingAs($general)->getJson("/api/almacenamiento/fichas/{$ficha->id}/documentos/ids")->assertForbidden();
    }
}
