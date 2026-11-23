<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Convocatoria;
use App\Models\Ficha;
use App\Models\Parametro;
use App\Models\Postulacion;
use App\Models\PostulacionDocumento;
use App\Models\PostulacionSkill;
use App\Models\User;
use Database\Seeders\ParametroSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * documentos:reubicar copia los archivos a la estructura por ficha/usuario/postulación. Estas pruebas cubren que no toca
 * nada sin --ejecutar, que copia sin borrar el original, que verifica el tamaño y que se puede repetir sin duplicar.
 */
class ReubicarDocumentosTest extends TestCase
{
    use RefreshDatabase;

    private static int $secuencia = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ParametroSeeder::class);
        Storage::fake('local');
    }

    private function parametroId(string $tema, string $codigo): int
    {
        return (int) Parametro::porTemaYCodigo($tema, $codigo)->id;
    }

    /** @return array{0: User, 1: Postulacion, 2: Ficha} */
    private function postulacion(): array
    {
        $ficha = Ficha::create(['codigo' => '4'.str_pad((string) ++self::$secuencia, 6, '0', STR_PAD_LEFT), 'nombre_programa' => 'Programa']);
        $instructor = User::factory()->create(['role' => Role::Instructor, 'active' => true, 'email_verified_at' => now()]);
        $aspirante = User::factory()->create([
            'role' => Role::Aspirante, 'active' => true, 'email_verified_at' => now(), 'ficha_id' => $ficha->id,
        ]);

        $convocatoria = Convocatoria::unguarded(fn () => Convocatoria::create([
            'ficha_id' => $ficha->id,
            'codigo' => sprintf('CONV-REU-%03d', self::$secuencia),
            'nombre' => 'Convocatoria',
            'vacantes' => 1,
            'tipo_convocatoria_id' => $this->parametroId('tipo_convocatoria', 'externo'),
            'tipo_empleo_id' => $this->parametroId('tipo_empleo', 'tiempo_completo'),
            'tipo_contrato_id' => $this->parametroId('tipo_contrato', 'termino_fijo'),
            'modalidad_id' => $this->parametroId('modalidad', 'presencial'),
            'estado_id' => $this->parametroId('estado_convocatoria', 'publicada'),
            'creado_por' => $instructor->id,
        ]));

        $postulacion = Postulacion::create([
            'convocatoria_id' => $convocatoria->id,
            'user_id' => $aspirante->id,
            'estado_id' => $this->parametroId('estado_postulacion', 'postulada'),
            'fecha_postulacion' => now(),
        ]);

        return [$aspirante, $postulacion, $ficha];
    }

    private function adicional(Postulacion $postulacion, string $ruta, string $contenido): PostulacionDocumento
    {
        Storage::disk('local')->put($ruta, $contenido);

        return PostulacionDocumento::create([
            'postulacion_id' => $postulacion->id,
            'parametro_id' => null,
            'titulo' => 'Certificado',
            'nombre_original' => 'certificado.pdf',
            'ruta' => $ruta,
            'disco' => 'local',
            'mime' => 'application/pdf',
            'tamano_bytes' => strlen($contenido),
        ]);
    }

    public function test_sin_ejecutar_no_cambia_nada(): void
    {
        [, $postulacion, $ficha] = $this->postulacion();
        $documento = $this->adicional($postulacion, "postulaciones/{$postulacion->id}/adicionales/a.pdf", 'contenido');

        $this->artisan('documentos:reubicar')->assertSuccessful();

        $this->assertSame("postulaciones/{$postulacion->id}/adicionales/a.pdf", $documento->fresh()->ruta);
        Storage::disk('local')->assertMissing("fichas/{$ficha->id}/usuarios/{$postulacion->user_id}/postulaciones/{$postulacion->id}/adicionales/a.pdf");
    }

    public function test_con_ejecutar_copia_verifica_y_actualiza_la_ruta_sin_borrar_el_original(): void
    {
        [$aspirante, $postulacion, $ficha] = $this->postulacion();
        $viejo = "postulaciones/{$postulacion->id}/adicionales/a.pdf";
        $documento = $this->adicional($postulacion, $viejo, 'contenido real');

        $this->artisan('documentos:reubicar', ['--ejecutar' => true])->assertSuccessful();

        $nueva = "fichas/{$ficha->id}/usuarios/{$aspirante->id}/postulaciones/{$postulacion->id}/adicionales/a.pdf";
        $this->assertSame($nueva, $documento->fresh()->ruta);
        Storage::disk('local')->assertExists($nueva);
        Storage::disk('local')->assertExists($viejo);
        $this->assertSame('contenido real', Storage::disk('local')->get($nueva));
    }

    public function test_los_documentos_requeridos_van_a_su_carpeta(): void
    {
        [$aspirante, $postulacion, $ficha] = $this->postulacion();
        $ruta = "postulaciones/{$postulacion->id}/documentos/hoja.pdf";
        Storage::disk('local')->put($ruta, 'hoja');
        $documento = PostulacionDocumento::create([
            'postulacion_id' => $postulacion->id,
            'parametro_id' => $this->parametroId('tipo_documento', 'hoja_de_vida'),
            'nombre_original' => 'hoja.pdf',
            'ruta' => $ruta,
            'disco' => 'local',
            'mime' => 'application/pdf',
            'tamano_bytes' => 4,
        ]);

        $this->artisan('documentos:reubicar', ['--ejecutar' => true])->assertSuccessful();

        $this->assertStringStartsWith(
            "fichas/{$ficha->id}/usuarios/{$aspirante->id}/postulaciones/{$postulacion->id}/documentos/",
            $documento->fresh()->ruta,
        );
    }

    public function test_repetir_el_comando_no_duplica_ni_vuelve_a_copiar(): void
    {
        [, $postulacion] = $this->postulacion();
        $this->adicional($postulacion, "postulaciones/{$postulacion->id}/adicionales/a.pdf", 'x');

        $this->artisan('documentos:reubicar', ['--ejecutar' => true])->assertSuccessful();
        $rutaTrasPrimera = PostulacionDocumento::sole()->ruta;

        $this->artisan('documentos:reubicar', ['--ejecutar' => true])->assertSuccessful();

        $this->assertSame($rutaTrasPrimera, PostulacionDocumento::sole()->ruta);
    }

    public function test_un_archivo_que_falta_se_reporta_y_queda_como_esta(): void
    {
        [, $postulacion] = $this->postulacion();
        $ruta = "postulaciones/{$postulacion->id}/adicionales/fantasma.pdf";
        $documento = PostulacionDocumento::create([
            'postulacion_id' => $postulacion->id,
            'parametro_id' => null,
            'titulo' => 'Perdido',
            'nombre_original' => 'fantasma.pdf',
            'ruta' => $ruta,
            'disco' => 'local',
            'mime' => 'application/pdf',
            'tamano_bytes' => 1,
        ]);

        $this->artisan('documentos:reubicar', ['--ejecutar' => true])->assertSuccessful();

        $this->assertSame($ruta, $documento->fresh()->ruta);
    }

    public function test_el_certificado_de_una_skill_tambien_se_reubica(): void
    {
        [$aspirante, $postulacion, $ficha] = $this->postulacion();
        $ruta = "postulaciones/{$postulacion->id}/skills/cert.pdf";
        Storage::disk('local')->put($ruta, 'cert');
        $skill = PostulacionSkill::create([
            'postulacion_id' => $postulacion->id,
            'nombre' => 'Excel',
            'certificado_nombre_original' => 'cert.pdf',
            'certificado_ruta' => $ruta,
            'certificado_disco' => 'local',
            'certificado_mime' => 'application/pdf',
            'certificado_tamano_bytes' => 4,
        ]);

        $this->artisan('documentos:reubicar', ['--ejecutar' => true])->assertSuccessful();

        $this->assertStringStartsWith(
            "fichas/{$ficha->id}/usuarios/{$aspirante->id}/postulaciones/{$postulacion->id}/skills/",
            $skill->fresh()->certificado_ruta,
        );
    }
}
