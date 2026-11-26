<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Ficha;
use App\Models\PostulacionDocumento;
use App\Services\Almacenamiento\UsoAlmacenamiento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\EvaluacionAyudas;
use Tests\TestCase;

/** Casos límite del módulo de Almacenamiento: fallos del disco, lotes, paginación, permisos y auditoría. */
class AlmacenamientoCasosLimiteTest extends TestCase
{
    use RefreshDatabase;
    use EvaluacionAyudas;

    private string $disco = 'local';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\ParametroSeeder::class);
        config(['filesystems.documentos_disk' => $this->disco]);
        Storage::fake($this->disco);
    }

    /** Un adicional con su archivo en el disco falso. */
    private function adicional(\App\Models\Postulacion $postulacion, Ficha $ficha, string $nombre, int $bytes): PostulacionDocumento
    {
        $ruta = "fichas/{$ficha->codigo}/usuarios/{$postulacion->user_id}/postulaciones/{$postulacion->id}/adicionales/{$nombre}";
        Storage::disk($this->disco)->put($ruta, str_repeat('x', $bytes));

        return PostulacionDocumento::query()->create([
            'postulacion_id' => $postulacion->id,
            'parametro_id' => null,
            'titulo' => 'Certificado '.$nombre,
            'nombre_original' => $nombre,
            'ruta' => $ruta,
            'disco' => $this->disco,
            'mime' => 'application/pdf',
            'tamano_bytes' => $bytes,
        ]);
    }

    private function instructorDe(Ficha ...$fichas): \App\Models\User
    {
        $instructor = $this->usuario(Role::Instructor);
        foreach ($fichas as $ficha) {
            $ficha->instructores()->attach($instructor->id);
        }

        return $instructor;
    }

    /** Una postulación en la ficha con n adicionales. */
    private function conAdicionales(Ficha $ficha, int $n): array
    {
        $convocatoria = $this->convocatoria($ficha);
        $aspirante = $this->usuario(Role::Aspirante, null, ['ficha_id' => $ficha->id]);
        $postulacion = $this->postulacion($convocatoria, $aspirante);

        $documentos = [];
        for ($i = 1; $i <= $n; $i++) {
            $documentos[] = $this->adicional($postulacion, $ficha, "doc{$i}.pdf", 10 * $i);
        }

        return $documentos;
    }

    public function test_un_disco_que_no_existe_muestra_no_disponible_sin_romper_el_resumen(): void
    {
        $ficha = $this->ficha('2896411');
        $this->conAdicionales($ficha, 1);
        config(['filesystems.documentos_disk' => 'disco-que-no-existe']);
        $admin = $this->usuario(Role::SuperAdmin);

        $respuesta = $this->actingAs($admin)->getJson('/api/almacenamiento/resumen')->assertOk();

        $fila = collect($respuesta->json('data.fichas'))->firstWhere('codigo', '2896411');
        $this->assertNull($fila['almacenamiento']['bytes']);
        $this->assertNull($fila['almacenamiento']['objetos']);
        // Los documentos registrados siguen contándose: la base no depende del disco.
        $this->assertSame(1, $fila['documentos']);
    }

    public function test_un_fallo_del_disco_no_se_guarda_en_cache(): void
    {
        $ficha = $this->ficha('2896411');
        $this->conAdicionales($ficha, 1);

        config(['filesystems.documentos_disk' => 'disco-que-no-existe']);
        $this->assertNull(app(UsoAlmacenamiento::class)->documentosDeFicha($ficha)['bytes']);

        // Cuando el disco vuelve, la siguiente consulta mide de verdad (el fallo no quedó guardado).
        config(['filesystems.documentos_disk' => $this->disco]);
        $this->assertSame(10, app(UsoAlmacenamiento::class)->documentosDeFicha($ficha)['bytes']);
    }

    public function test_la_medida_queda_en_cache_hasta_que_se_olvida_o_se_borra(): void
    {
        $ficha = $this->ficha('2896411');
        $documentos = $this->conAdicionales($ficha, 1);
        $admin = $this->usuario(Role::SuperAdmin);

        $this->assertSame(10, $this->actingAs($admin)->getJson('/api/almacenamiento/resumen')->json('data.totales.bytes'));

        // Un archivo nuevo no se ve de inmediato: la medida dura unos minutos en caché.
        Storage::disk($this->disco)->put('fichas/2896411/extra.pdf', str_repeat('y', 500));
        $this->assertSame(10, $this->actingAs($admin)->getJson('/api/almacenamiento/resumen')->json('data.totales.bytes'));

        // Al borrar un documento la medida de la ficha se recalcula (incluye el archivo nuevo).
        $this->actingAs($admin)->postJson('/api/almacenamiento/documentos/borrar', ['ids' => [$documentos[0]->id]])->assertOk();
        $this->assertSame(500, $this->actingAs($admin)->getJson('/api/almacenamiento/resumen')->json('data.totales.bytes'));
    }

    public function test_la_lista_de_documentos_se_pagina(): void
    {
        $ficha = $this->ficha('2896411');
        $this->conAdicionales($ficha, 3);
        $instructor = $this->instructorDe($ficha);

        $pagina1 = $this->actingAs($instructor)->getJson("/api/almacenamiento/fichas/{$ficha->id}/documentos?per_page=2&page=1")
            ->assertOk()
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.last_page', 2);
        $this->assertCount(2, $pagina1->json('data'));

        $pagina2 = $this->actingAs($instructor)->getJson("/api/almacenamiento/fichas/{$ficha->id}/documentos?per_page=2&page=2")
            ->assertOk();
        $this->assertCount(1, $pagina2->json('data'));
    }

    public function test_un_lote_de_mas_de_cien_documentos_se_rechaza(): void
    {
        $instructor = $this->instructorDe($this->ficha('2896411'));
        $ids = range(1, 101);

        $this->actingAs($instructor)->postJson('/api/almacenamiento/documentos/borrar', ['ids' => $ids])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ids');
    }

    public function test_los_ids_deben_ser_numeros(): void
    {
        $instructor = $this->instructorDe($this->ficha('2896411'));

        $this->actingAs($instructor)->postJson('/api/almacenamiento/documentos/borrar', ['ids' => ['abc']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ids.0');
    }

    public function test_el_mismo_id_repetido_se_borra_una_sola_vez(): void
    {
        $ficha = $this->ficha('2896411');
        [$doc] = $this->conAdicionales($ficha, 1);
        $instructor = $this->instructorDe($ficha);

        $respuesta = $this->actingAs($instructor)
            ->postJson('/api/almacenamiento/documentos/borrar', ['ids' => [$doc->id, $doc->id]])
            ->assertOk();

        $this->assertSame([$doc->id], $respuesta->json('data.borrados'));
        $this->assertSame([], $respuesta->json('data.omitidos'));
    }

    public function test_un_instructor_sin_fichas_ve_un_resumen_vacio_y_no_falla(): void
    {
        $this->ficha('2896411');
        $instructor = $this->instructorDe();

        $respuesta = $this->actingAs($instructor)->getJson('/api/almacenamiento/resumen')->assertOk();

        $this->assertSame([], $respuesta->json('data.fichas'));
        $this->assertSame(0, $respuesta->json('data.totales.documentos'));
        $this->assertSame(0, $respuesta->json('data.totales.bytes'));
    }

    public function test_los_totales_suman_todas_las_fichas_del_instructor(): void
    {
        $a = $this->ficha('2896411');
        $b = $this->ficha('2896412');
        $this->conAdicionales($a, 2); // 10 + 20 = 30 bytes
        $this->conAdicionales($b, 1); // 10 bytes
        $instructor = $this->instructorDe($a, $b);

        $respuesta = $this->actingAs($instructor)->getJson('/api/almacenamiento/resumen')->assertOk();

        $this->assertSame(3, $respuesta->json('data.totales.documentos'));
        $this->assertSame(40, $respuesta->json('data.totales.bytes'));
    }

    public function test_una_ficha_sin_documentos_responde_lista_vacia(): void
    {
        $ficha = $this->ficha('2896411');
        $instructor = $this->instructorDe($ficha);

        $this->actingAs($instructor)->getJson("/api/almacenamiento/fichas/{$ficha->id}/documentos")
            ->assertOk()
            ->assertJsonPath('meta.total', 0)
            ->assertJsonCount(0, 'data');
    }

    public function test_una_ficha_que_no_existe_responde_404(): void
    {
        $instructor = $this->instructorDe($this->ficha('2896411'));

        $this->actingAs($instructor)->getJson('/api/almacenamiento/fichas/999999/documentos')->assertNotFound();
    }

    public function test_el_super_admin_lista_los_documentos_de_cualquier_ficha(): void
    {
        $ajena = $this->ficha('2896412');
        $this->conAdicionales($ajena, 1);
        $admin = $this->usuario(Role::SuperAdmin);

        $this->actingAs($admin)->getJson("/api/almacenamiento/fichas/{$ajena->id}/documentos")
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
    }

    public function test_un_documento_opcional_se_puede_borrar_aunque_sea_requerido(): void
    {
        $ficha = $this->ficha('2896411');
        $convocatoria = $this->convocatoria($ficha);
        $hoja = $this->parametroId('tipo_documento', 'hoja_de_vida');
        $convocatoria->documentos()->attach($hoja, ['obligatorio' => false, 'ponderacion' => null]);
        $aspirante = $this->usuario(Role::Aspirante, null, ['ficha_id' => $ficha->id]);
        $postulacion = $this->postulacion($convocatoria, $aspirante);
        $ruta = "fichas/2896411/usuarios/{$aspirante->id}/postulaciones/{$postulacion->id}/documentos/hoja.pdf";
        Storage::disk($this->disco)->put($ruta, 'pdf');
        $documento = PostulacionDocumento::query()->create([
            'postulacion_id' => $postulacion->id,
            'parametro_id' => $hoja,
            'nombre_original' => 'hoja.pdf',
            'ruta' => $ruta,
            'disco' => $this->disco,
            'mime' => 'application/pdf',
            'tamano_bytes' => 3,
        ]);
        $instructor = $this->instructorDe($ficha);

        $fila = collect($this->actingAs($instructor)->getJson("/api/almacenamiento/fichas/{$ficha->id}/documentos")->json('data'))
            ->firstWhere('id', $documento->id);
        $this->assertFalse($fila['obligatorio']);
        $this->assertTrue($fila['borrable']);

        $this->actingAs($instructor)->postJson('/api/almacenamiento/documentos/borrar', ['ids' => [$documento->id]])
            ->assertOk()
            ->assertJsonPath('data.borrados.0', $documento->id);
        Storage::disk($this->disco)->assertMissing($ruta);
    }

    public function test_cada_borrado_queda_en_el_registro_con_quien_lo_hizo(): void
    {
        Log::spy();
        $ficha = $this->ficha('2896411');
        [$doc] = $this->conAdicionales($ficha, 1);
        $instructor = $this->instructorDe($ficha);

        $this->actingAs($instructor)->postJson('/api/almacenamiento/documentos/borrar', ['ids' => [$doc->id]])->assertOk();

        Log::shouldHaveReceived('info')->withArgs(function (string $mensaje, array $contexto) use ($instructor, $doc) {
            return $mensaje === 'Documentos borrados desde Almacenamiento.'
                && $contexto['actor_id'] === $instructor->id
                && $contexto['documentos'] === [$doc->id];
        })->once();
    }

    public function test_un_borrado_sin_nada_borrado_no_deja_registro(): void
    {
        Log::spy();
        $instructor = $this->instructorDe($this->ficha('2896411'));

        $this->actingAs($instructor)->postJson('/api/almacenamiento/documentos/borrar', ['ids' => [999999]])->assertOk();

        Log::shouldNotHaveReceived('info');
    }

    public function test_sin_sesion_no_se_puede_consultar_ni_borrar(): void
    {
        $this->getJson('/api/almacenamiento/resumen')->assertUnauthorized();
        $this->postJson('/api/almacenamiento/documentos/borrar', ['ids' => [1]])->assertUnauthorized();
    }

    public function test_el_aspirante_no_lista_documentos_de_una_ficha(): void
    {
        $ficha = $this->ficha('2896411');
        $aspirante = $this->usuario(Role::Aspirante, null, ['ficha_id' => $ficha->id]);

        $this->actingAs($aspirante)->getJson("/api/almacenamiento/fichas/{$ficha->id}/documentos")->assertForbidden();
    }
}
