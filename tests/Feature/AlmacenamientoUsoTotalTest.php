<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Ficha;
use App\Models\PostulacionDocumento;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\EvaluacionAyudas;
use Tests\TestCase;

/** Porcentaje de uso total del almacenamiento: lo ven el super admin y el instructor; el detalle de la base, solo el super admin. */
class AlmacenamientoUsoTotalTest extends TestCase
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

    /** Capacidad en bytes expresada en MB (la configuración trabaja en MB). */
    private function limiteEnBytes(int $bytes): float
    {
        return $bytes / 1048576;
    }

    /** Un adicional de 100 bytes en una ficha con postulación, para tener algo que medir. */
    private function documentoDeCienBytes(Ficha $ficha): PostulacionDocumento
    {
        $aspirante = $this->usuario(Role::Aspirante, null, ['ficha_id' => $ficha->id]);
        $postulacion = $this->postulacion($this->convocatoria($ficha), $aspirante);
        $ruta = "fichas/{$ficha->codigo}/usuarios/{$aspirante->id}/postulaciones/{$postulacion->id}/adicionales/a.pdf";
        Storage::disk($this->disco)->put($ruta, str_repeat('x', 100));

        return PostulacionDocumento::query()->create([
            'postulacion_id' => $postulacion->id,
            'parametro_id' => null,
            'titulo' => 'Certificado',
            'nombre_original' => 'a.pdf',
            'ruta' => $ruta,
            'disco' => $this->disco,
            'mime' => 'application/pdf',
            'tamano_bytes' => 100,
        ]);
    }

    private function instructorDe(Ficha $ficha): User
    {
        $instructor = $this->usuario(Role::Instructor);
        $ficha->instructores()->attach($instructor->id);

        return $instructor;
    }

    public function test_el_porcentaje_de_documentos_es_el_uso_frente_a_la_capacidad(): void
    {
        // Capacidad de documentos: 200 bytes. Hay 100 bytes en el disco: 50 %.
        config(['almacenamiento.limite_documentos_mb' => $this->limiteEnBytes(200)]);
        $ficha = $this->ficha('2896411');
        $this->documentoDeCienBytes($ficha);

        $respuesta = $this->actingAs($this->instructorDe($ficha))->getJson('/api/almacenamiento/resumen')->assertOk();

        $this->assertEquals(50.0, $respuesta->json('data.uso.documentos.porcentaje'));
        $this->assertSame(100, $respuesta->json('data.uso.documentos.usado_bytes'));
        $this->assertSame(200, $respuesta->json('data.uso.documentos.limite_bytes'));
    }

    public function test_el_instructor_ve_el_total_pero_no_el_detalle_de_la_base(): void
    {
        $ficha = $this->ficha('2896411');
        $this->documentoDeCienBytes($ficha);

        $respuesta = $this->actingAs($this->instructorDe($ficha))->getJson('/api/almacenamiento/resumen')->assertOk();

        $this->assertIsNumeric($respuesta->json('data.uso.porcentaje'));
        $this->assertArrayNotHasKey('base', $respuesta->json('data.uso'));
        $this->assertArrayNotHasKey('base', $respuesta->json('data'));
    }

    public function test_el_super_admin_ve_el_total_y_el_detalle_de_la_base(): void
    {
        $ficha = $this->ficha('2896411');
        $this->documentoDeCienBytes($ficha);
        $admin = $this->usuario(Role::SuperAdmin);

        $respuesta = $this->actingAs($admin)->getJson('/api/almacenamiento/resumen')->assertOk();

        $this->assertIsNumeric($respuesta->json('data.uso.porcentaje'));
        $this->assertArrayHasKey('base', $respuesta->json('data.uso'));
        $this->assertIsNumeric($respuesta->json('data.uso.base.porcentaje'));
    }

    public function test_el_total_combina_base_y_documentos_contra_la_capacidad_de_ambos(): void
    {
        config([
            'almacenamiento.limite_base_mb' => 1,
            'almacenamiento.limite_documentos_mb' => $this->limiteEnBytes(200),
        ]);
        $ficha = $this->ficha('2896411');
        $this->documentoDeCienBytes($ficha);
        $admin = $this->usuario(Role::SuperAdmin);

        $uso = $this->actingAs($admin)->getJson('/api/almacenamiento/resumen')->assertOk()->json('data.uso');

        $usado = $uso['base']['usado_bytes'] + 100;
        $limite = 1048576 + 200;
        $this->assertEquals(round($usado / $limite * 100, 1), $uso['porcentaje']);
        $this->assertSame($limite, $uso['limite_bytes']);
    }

    public function test_sin_documentos_el_porcentaje_de_documentos_es_cero_y_no_falla(): void
    {
        config(['almacenamiento.limite_documentos_mb' => $this->limiteEnBytes(200)]);
        $instructor = $this->instructorDe($this->ficha('2896411'));

        $respuesta = $this->actingAs($instructor)->getJson('/api/almacenamiento/resumen')->assertOk();

        $this->assertEquals(0.0, $respuesta->json('data.uso.documentos.porcentaje'));
        $this->assertIsNumeric($respuesta->json('data.uso.porcentaje'));
    }

    public function test_al_borrar_el_total_de_documentos_se_actualiza(): void
    {
        config(['almacenamiento.limite_documentos_mb' => $this->limiteEnBytes(200)]);
        $ficha = $this->ficha('2896411');
        $documento = $this->documentoDeCienBytes($ficha);
        $instructor = $this->instructorDe($ficha);

        $this->assertEquals(50.0, $this->actingAs($instructor)->getJson('/api/almacenamiento/resumen')->json('data.uso.documentos.porcentaje'));

        $this->actingAs($instructor)->postJson('/api/almacenamiento/documentos/borrar', ['ids' => [$documento->id]])->assertOk();

        $this->assertEquals(0.0, $this->actingAs($instructor)->getJson('/api/almacenamiento/resumen')->json('data.uso.documentos.porcentaje'));
    }
}
