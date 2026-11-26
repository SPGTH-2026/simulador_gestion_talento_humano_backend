<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Ficha;
use App\Models\PostulacionDocumento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\EvaluacionAyudas;
use Tests\TestCase;

class AlmacenamientoTest extends TestCase
{
    use RefreshDatabase;
    use EvaluacionAyudas;

    private string $disco = 'local';

    protected function setUp(): void
    {
        parent::setUp();
        // Mi setUp reemplaza el del ayudante EvaluacionAyudas, que es el que carga el catálogo: lo cargo aquí.
        $this->seed(\Database\Seeders\ParametroSeeder::class);
        config(['filesystems.documentos_disk' => $this->disco]);
        Storage::fake($this->disco);
    }

    /** Un documento adicional de la postulación, con su archivo real en el disco falso. */
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

    /** Una postulación de la ficha con un documento obligatorio (la hoja de vida) y un adicional. */
    private function escenario(Ficha $ficha): array
    {
        $convocatoria = $this->convocatoria($ficha);
        $hoja = $this->parametroId('tipo_documento', 'hoja_de_vida');
        $convocatoria->documentos()->attach($hoja, ['obligatorio' => true, 'ponderacion' => 100]);

        $aspirante = $this->usuario(Role::Aspirante, null, ['ficha_id' => $ficha->id]);
        $postulacion = $this->postulacion($convocatoria, $aspirante);

        $obligatorio = PostulacionDocumento::query()->create([
            'postulacion_id' => $postulacion->id,
            'parametro_id' => $hoja,
            'nombre_original' => 'hoja.pdf',
            'ruta' => "fichas/{$ficha->codigo}/usuarios/{$aspirante->id}/postulaciones/{$postulacion->id}/documentos/hoja.pdf",
            'disco' => $this->disco,
            'mime' => 'application/pdf',
            'tamano_bytes' => 10,
        ]);
        Storage::disk($this->disco)->put($obligatorio->ruta, str_repeat('h', 10));

        $extra = $this->adicional($postulacion, $ficha, 'diploma.pdf', 25);

        return [$postulacion, $obligatorio, $extra];
    }

    public function test_el_aprendiz_general_ve_el_almacenamiento_de_su_ficha_pero_no_borra(): void
    {
        $miFicha = $this->ficha('2896411');
        $ajena = $this->ficha('2896412');
        $general = $this->usuario(Role::Aprendiz, 'general', ['ficha_id' => $miFicha->id]);
        $this->escenario($miFicha);
        [, , $extraAjeno] = $this->escenario($ajena);

        $respuesta = $this->actingAs($general)->getJson('/api/almacenamiento/resumen')->assertOk();
        $this->assertSame(['2896411'], collect($respuesta->json('data.fichas'))->pluck('codigo')->all());
        $this->assertArrayNotHasKey('base', $respuesta->json('data'));

        $this->actingAs($general)->getJson("/api/almacenamiento/fichas/{$miFicha->id}/documentos")->assertOk();
        $this->actingAs($general)->getJson("/api/almacenamiento/fichas/{$ajena->id}/documentos")->assertNotFound();

        $this->actingAs($general)->postJson('/api/almacenamiento/documentos/borrar', ['ids' => [$extraAjeno->id]])
            ->assertForbidden();
        $this->assertDatabaseHas('postulacion_documentos', ['id' => $extraAjeno->id]);
    }

    public function test_aspirante_y_aprendiz_no_ven_almacenamiento(): void
    {
        $ficha = $this->ficha('2896411');
        $aprendiz = $this->usuario(Role::Aprendiz, 'evaluador', ['ficha_id' => $ficha->id]);
        $aspirante = $this->usuario(Role::Aspirante, null, ['ficha_id' => $ficha->id]);

        $this->actingAs($aspirante)->getJson('/api/almacenamiento/resumen')->assertForbidden();
        $this->actingAs($aprendiz)->getJson('/api/almacenamiento/resumen')->assertForbidden();
    }

    public function test_instructor_solo_ve_las_fichas_que_administra(): void
    {
        $miFicha = $this->ficha('2896411');
        $ajena = $this->ficha('2896412');
        $instructor = $this->usuario(Role::Instructor);
        $miFicha->instructores()->attach($instructor->id);

        $this->escenario($miFicha);
        $this->escenario($ajena);

        $respuesta = $this->actingAs($instructor)->getJson('/api/almacenamiento/resumen')->assertOk();

        $codigos = collect($respuesta->json('data.fichas'))->pluck('codigo')->all();
        $this->assertSame(['2896411'], $codigos);
        $this->assertArrayNotHasKey('base', $respuesta->json('data'));
    }

    public function test_super_admin_ve_todas_las_fichas_y_el_tamano_de_la_base(): void
    {
        $this->escenario($this->ficha('2896411'));
        $this->escenario($this->ficha('2896412'));
        $admin = $this->usuario(Role::SuperAdmin);

        $respuesta = $this->actingAs($admin)->getJson('/api/almacenamiento/resumen')->assertOk();

        $this->assertCount(2, $respuesta->json('data.fichas'));
        $this->assertSame(2 * 2, $respuesta->json('data.totales.documentos'));
        $this->assertSame(2 * (10 + 25), $respuesta->json('data.totales.bytes'));
        $this->assertIsInt($respuesta->json('data.base.bytes'));
    }

    public function test_el_tamano_por_ficha_cuenta_los_archivos_de_su_carpeta(): void
    {
        $ficha = $this->ficha('2896411');
        $this->escenario($ficha);
        $admin = $this->usuario(Role::SuperAdmin);

        $fila = collect($this->actingAs($admin)->getJson('/api/almacenamiento/resumen')->json('data.fichas'))
            ->firstWhere('codigo', '2896411');

        $this->assertSame(35, $fila['almacenamiento']['bytes']);
        $this->assertSame(2, $fila['almacenamiento']['objetos']);
    }

    public function test_instructor_no_puede_listar_documentos_de_una_ficha_ajena(): void
    {
        $miFicha = $this->ficha('2896411');
        $ajena = $this->ficha('2896412');
        $instructor = $this->usuario(Role::Instructor);
        $miFicha->instructores()->attach($instructor->id);
        $this->escenario($ajena);

        $this->actingAs($instructor)->getJson("/api/almacenamiento/fichas/{$ajena->id}/documentos")->assertNotFound();
    }

    public function test_listado_marca_que_se_puede_borrar_y_que_no(): void
    {
        $ficha = $this->ficha('2896411');
        $instructor = $this->usuario(Role::Instructor);
        $ficha->instructores()->attach($instructor->id);
        [, $obligatorio, $extra] = $this->escenario($ficha);

        $respuesta = $this->actingAs($instructor)->getJson("/api/almacenamiento/fichas/{$ficha->id}/documentos")
            ->assertOk()
            ->assertJsonPath('ficha.codigo', '2896411');
        $filas = collect($respuesta->json('data'));

        $this->assertFalse($filas->firstWhere('id', $obligatorio->id)['borrable']);
        $this->assertTrue($filas->firstWhere('id', $obligatorio->id)['obligatorio']);
        $this->assertTrue($filas->firstWhere('id', $extra->id)['borrable']);
        $this->assertSame('Certificado diploma.pdf', $filas->firstWhere('id', $extra->id)['titulo']);
    }

    public function test_borrar_un_adicional_quita_el_registro_y_el_archivo(): void
    {
        $ficha = $this->ficha('2896411');
        $instructor = $this->usuario(Role::Instructor);
        $ficha->instructores()->attach($instructor->id);
        [, , $extra] = $this->escenario($ficha);
        $this->assertSame(35, $this->actingAs($instructor)->getJson('/api/almacenamiento/resumen')
            ->json('data.totales.bytes'));

        $respuesta = $this->actingAs($instructor)->postJson('/api/almacenamiento/documentos/borrar', ['ids' => [$extra->id]])
            ->assertOk();

        $this->assertSame([$extra->id], $respuesta->json('data.borrados'));
        $this->assertSame([], $respuesta->json('data.omitidos'));
        $this->assertDatabaseMissing('postulacion_documentos', ['id' => $extra->id]);
        Storage::disk($this->disco)->assertMissing($extra->ruta);

        // La medida de la ficha se actualiza al borrar: ya no cuenta el adicional.
        $this->assertSame(10, $this->actingAs($instructor)->getJson('/api/almacenamiento/resumen')
            ->json('data.totales.bytes'));
    }

    public function test_un_obligatorio_se_omite_con_su_motivo_y_los_demas_se_borran(): void
    {
        $ficha = $this->ficha('2896411');
        $instructor = $this->usuario(Role::Instructor);
        $ficha->instructores()->attach($instructor->id);
        [, $obligatorio, $extra] = $this->escenario($ficha);

        $respuesta = $this->actingAs($instructor)
            ->postJson('/api/almacenamiento/documentos/borrar', ['ids' => [$obligatorio->id, $extra->id]])
            ->assertOk();

        $this->assertSame([$extra->id], $respuesta->json('data.borrados'));
        $this->assertSame($obligatorio->id, $respuesta->json('data.omitidos.0.id'));
        $this->assertStringContainsString('obligatorio', $respuesta->json('data.omitidos.0.motivo'));
        $this->assertDatabaseHas('postulacion_documentos', ['id' => $obligatorio->id]);
    }

    public function test_un_documento_de_otra_ficha_no_se_borra_ni_se_revela(): void
    {
        $miFicha = $this->ficha('2896411');
        $instructor = $this->usuario(Role::Instructor);
        $miFicha->instructores()->attach($instructor->id);

        $ajena = $this->ficha('2896412');
        [, , $extraAjeno] = $this->escenario($ajena);

        $respuesta = $this->actingAs($instructor)
            ->postJson('/api/almacenamiento/documentos/borrar', ['ids' => [$extraAjeno->id, 999999]])
            ->assertOk();

        $this->assertSame([], $respuesta->json('data.borrados'));
        // El documento ajeno y el inexistente dan el mismo mensaje: no se revela cuál existe.
        $motivos = collect($respuesta->json('data.omitidos'))->pluck('motivo')->unique()->values()->all();
        $this->assertSame(['Ese documento no existe en tus fichas.'], $motivos);
        $this->assertDatabaseHas('postulacion_documentos', ['id' => $extraAjeno->id]);
        Storage::disk($this->disco)->assertExists($extraAjeno->ruta);
    }

    public function test_borrar_exige_una_lista_de_ids(): void
    {
        $instructor = $this->usuario(Role::Instructor);

        $this->actingAs($instructor)->postJson('/api/almacenamiento/documentos/borrar', ['ids' => []])
            ->assertUnprocessable();
    }

    public function test_aprendiz_no_puede_borrar_documentos(): void
    {
        $ficha = $this->ficha('2896411');
        $aprendiz = $this->usuario(Role::Aprendiz, 'gestor_convocatorias', ['ficha_id' => $ficha->id]);
        [, , $extra] = $this->escenario($ficha);

        $this->actingAs($aprendiz)->postJson('/api/almacenamiento/documentos/borrar', ['ids' => [$extra->id]])
            ->assertForbidden();
        $this->assertDatabaseHas('postulacion_documentos', ['id' => $extra->id]);
    }
}
