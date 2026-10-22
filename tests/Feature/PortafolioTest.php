<?php

namespace App\Tests\Feature;

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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Portafolio documental (módulo Portafolios del front).
 *
 *  - GET /api/usuarios?role=aspirante&con_postulaciones=1: el listado debe
 *    traer el conteo de postulaciones (antes el front filtraba por un campo
 *    que no existía y la lista salía SIEMPRE vacía).
 *  - GET /api/portafolios/{user}: detalle con el resumen de cada postulación,
 *    con alcance por ficha (fuera de alcance -> 404, sin revelar existencia).
 */
class PortafolioTest extends TestCase
{
    use RefreshDatabase;

    private static int $codigoSecuencia = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ParametroSeeder::class);
        Storage::fake('local');
    }

    // ── Helpers ────────────────────────────────────────────────────────

    private function ficha(string $codigo = '3173334'): Ficha
    {
        return Ficha::create(['codigo' => $codigo, 'nombre_programa' => 'Análisis y Desarrollo de Software']);
    }

    private function usuario(Role $role, array $extra = []): User
    {
        return User::factory()->create($extra + [
            'role' => $role,
            'active' => true,
            'email_verified_at' => now(),
        ]);
    }

    private function superAdmin(): User
    {
        return $this->usuario(Role::SuperAdmin);
    }

    private function instructor(Ficha ...$fichas): User
    {
        $instructor = $this->usuario(Role::Instructor);
        foreach ($fichas as $ficha) {
            $instructor->fichas()->attach($ficha->id);
        }

        return $instructor->fresh();
    }

    private function aspirante(Ficha $ficha): User
    {
        return $this->usuario(Role::Aspirante, ['ficha_id' => $ficha->id]);
    }

    private function parametroId(string $tema, string $codigo): int
    {
        $parametro = Parametro::porTemaYCodigo($tema, $codigo);
        $this->assertNotNull($parametro, "Falta el parámetro {$tema}.{$codigo}");

        return (int) $parametro->id;
    }

    private function crearConvocatoria(Ficha $ficha, User $creador, array $overrides = []): Convocatoria
    {
        return Convocatoria::unguarded(fn () => Convocatoria::create($overrides + [
            'ficha_id' => $ficha->id,
            'codigo' => sprintf('CONV-PRT-%03d', ++self::$codigoSecuencia),
            'nombre' => 'Convocatoria base',
            'vacantes' => 1,
            'tipo_convocatoria_id' => $this->parametroId('tipo_convocatoria', 'externo'),
            'tipo_empleo_id' => $this->parametroId('tipo_empleo', 'tiempo_completo'),
            'tipo_contrato_id' => $this->parametroId('tipo_contrato', 'termino_fijo'),
            'modalidad_id' => $this->parametroId('modalidad', 'presencial'),
            'estado_id' => $this->parametroId('estado_convocatoria', 'publicada'),
            'fecha_apertura' => now('America/Bogota')->subDay()->toDateString(),
            'fecha_cierre' => now('America/Bogota')->addDay()->toDateString(),
            'creado_por' => $creador->id,
        ]));
    }

    private function conDocumentos(Convocatoria $convocatoria, array $documentos): Convocatoria
    {
        foreach ($documentos as $codigo => $obligatorio) {
            $convocatoria->documentos()->attach(
                $this->parametroId('tipo_documento', $codigo),
                ['obligatorio' => $obligatorio],
            );
        }

        return $convocatoria->fresh();
    }

    private function crearPostulacion(Convocatoria $convocatoria, User $usuario, array $overrides = []): Postulacion
    {
        return Postulacion::create($overrides + [
            'convocatoria_id' => $convocatoria->id,
            'user_id' => $usuario->id,
            'estado_id' => $this->parametroId('estado_postulacion', 'postulada'),
            'fecha_postulacion' => now(),
        ]);
    }

    private function subir(Postulacion $postulacion, string $codigo): PostulacionDocumento
    {
        return PostulacionDocumento::create([
            'postulacion_id' => $postulacion->id,
            'parametro_id' => $this->parametroId('tipo_documento', $codigo),
            'ruta' => 'documentos/prueba.pdf',
            'disco' => 'local',
            'nombre_original' => "{$codigo}.pdf",
            'mime' => 'application/pdf',
            'tamano_bytes' => 1024,
        ]);
    }

    // ── Listado (GET /api/usuarios?role=aspirante&con_postulaciones=1) ─

    public function test_listado_trae_el_conteo_de_postulaciones(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $otro = $this->aspirante($ficha);
        $convocatoria = $this->crearConvocatoria($ficha, $instructor);
        $this->crearPostulacion($convocatoria, $aspirante);

        $respuesta = $this->actingAs($this->superAdmin())
            ->getJson('/api/usuarios?role=aspirante')
            ->assertOk();

        $porId = collect($respuesta->json('data'))->keyBy('id');

        $this->assertSame(1, $porId[$aspirante->id]['postulaciones_count']);
        $this->assertSame(0, $porId[$otro->id]['postulaciones_count']);
        // La tabla del portafolio pinta la última actividad con esto.
        $this->assertArrayHasKey('updated_at', $porId[$aspirante->id]);
    }

    public function test_con_postulaciones_1_filtra_a_quien_no_se_ha_postulado(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $otro = $this->aspirante($ficha);
        $convocatoria = $this->crearConvocatoria($ficha, $instructor);
        $this->crearPostulacion($convocatoria, $aspirante);

        $respuesta = $this->actingAs($this->superAdmin())
            ->getJson('/api/usuarios?role=aspirante&con_postulaciones=1')
            ->assertOk();

        $ids = collect($respuesta->json('data'))->pluck('id');

        $this->assertTrue($ids->contains($aspirante->id));
        $this->assertFalse($ids->contains($otro->id));
    }

    public function test_el_listado_no_filtra_por_ficha_para_el_super_admin(): void
    {
        $fichaA = $this->ficha('3173334');
        $fichaB = $this->ficha('2661234');
        $instructorA = $this->instructor($fichaA);
        $instructorB = $this->instructor($fichaB);
        $aspiranteA = $this->aspirante($fichaA);
        $aspiranteB = $this->aspirante($fichaB);
        $this->crearPostulacion($this->crearConvocatoria($fichaA, $instructorA), $aspiranteA);
        $this->crearPostulacion($this->crearConvocatoria($fichaB, $instructorB), $aspiranteB);

        $respuesta = $this->actingAs($this->superAdmin())
            ->getJson('/api/usuarios?role=aspirante&con_postulaciones=1')
            ->assertOk();

        $ids = collect($respuesta->json('data'))->pluck('id');

        $this->assertTrue($ids->contains($aspiranteA->id));
        $this->assertTrue($ids->contains($aspiranteB->id));
    }

    // ── Detalle (GET /api/portafolios/{user}) ──────────────────────────

    public function test_super_admin_abre_el_portafolio_con_el_resumen_de_cada_postulacion(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->conDocumentos($this->crearConvocatoria($ficha, $instructor), [
            'hoja_de_vida' => true,
            'certificados_academicos' => true,
        ]);
        $postulacion = $this->crearPostulacion($convocatoria, $aspirante);
        $this->subir($postulacion, 'hoja_de_vida');

        $respuesta = $this->actingAs($this->superAdmin())
            ->getJson("/api/portafolios/{$aspirante->id}")
            ->assertOk();

        $respuesta->assertJsonPath('data.usuario.id', $aspirante->id)
            ->assertJsonPath('data.usuario.ficha.codigo', $ficha->codigo);

        $p = $respuesta->json('data.postulaciones.0');

        $this->assertSame($postulacion->id, $p['id']);
        $this->assertSame('postulada', $p['estado']['codigo']);
        $this->assertSame($convocatoria->codigo, $p['convocatoria']['codigo']);
        $this->assertTrue($p['puede_ver_documentos']);
        // Dos obligatorios y solo uno subido -> incompleto.
        $this->assertFalse($p['completo']);
        $this->assertSame(2, $p['documentos']['requeridos']);
        $this->assertSame(1, $p['documentos']['subidos']);
        $this->assertSame(0, $p['documentos']['adicionales']);
        $this->assertSame(0, $p['skills']['total']);
        $this->assertSame(0, $p['skills']['con_certificado']);
    }

    public function test_el_resumen_cuenta_adicionales_y_certificados(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->crearConvocatoria($ficha, $instructor);
        $postulacion = $this->crearPostulacion($convocatoria, $aspirante);

        PostulacionDocumento::create([
            'postulacion_id' => $postulacion->id,
            'parametro_id' => null,
            'titulo' => 'Certificado de curso',
            'ruta' => 'documentos/adicional.pdf',
            'disco' => 'local',
            'nombre_original' => 'adicional.pdf',
            'mime' => 'application/pdf',
            'tamano_bytes' => 512,
        ]);

        PostulacionSkill::create([
            'postulacion_id' => $postulacion->id,
            'nombre' => 'Power BI',
            'certificado_nombre_original' => 'certificado.pdf',
            'certificado_ruta' => 'skills/certificado.pdf',
            'certificado_disco' => 'local',
            'certificado_mime' => 'application/pdf',
            'certificado_tamano_bytes' => 256,
        ]);
        PostulacionSkill::create(['postulacion_id' => $postulacion->id, 'nombre' => 'Excel']);

        $p = $this->actingAs($this->superAdmin())
            ->getJson("/api/portafolios/{$aspirante->id}")
            ->assertOk()
            ->json('data.postulaciones.0');

        $this->assertSame(1, $p['documentos']['adicionales']);
        $this->assertSame(2, $p['skills']['total']);
        $this->assertSame(1, $p['skills']['con_certificado']);
    }

    public function test_un_borrador_no_se_marca_como_documentos_disponibles(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->crearConvocatoria($ficha, $instructor);
        $this->crearPostulacion($convocatoria, $aspirante, [
            'estado_id' => $this->parametroId('estado_postulacion', 'borrador'),
        ]);

        $p = $this->actingAs($this->superAdmin())
            ->getJson("/api/portafolios/{$aspirante->id}")
            ->assertOk()
            ->json('data.postulaciones.0');

        $this->assertFalse($p['puede_ver_documentos']);
    }

    public function test_un_aspirante_sin_permiso_no_puede_abrir_portafolios(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $otro = $this->aspirante($ficha);
        $this->crearPostulacion($this->crearConvocatoria($ficha, $instructor), $aspirante);

        $this->actingAs($otro)
            ->getJson("/api/portafolios/{$aspirante->id}")
            ->assertForbidden();
    }

    public function test_un_usuario_sin_ficha_no_es_visible_para_el_instructor(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $sinFicha = $this->usuario(Role::Aspirante);
        $this->crearPostulacion($this->crearConvocatoria($ficha, $instructor), $sinFicha);

        $this->actingAs($instructor)
            ->getJson("/api/portafolios/{$sinFicha->id}")
            ->assertNotFound()
            ->assertJsonPath('message', 'Portafolio no encontrado.');
    }

    public function test_un_instructor_no_abre_el_portafolio_de_otra_ficha(): void
    {
        $fichaA = $this->ficha('3173334');
        $fichaB = $this->ficha('2661234');
        $instructorA = $this->instructor($fichaA);
        $instructorB = $this->instructor($fichaB);
        $aspiranteB = $this->aspirante($fichaB);
        $this->crearPostulacion($this->crearConvocatoria($fichaB, $instructorB), $aspiranteB);

        $this->actingAs($instructorA)
            ->getJson("/api/portafolios/{$aspiranteB->id}")
            ->assertNotFound()
            ->assertJsonPath('message', 'Portafolio no encontrado.');
    }

    public function test_un_instructor_abre_el_portafolio_de_su_ficha(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->crearConvocatoria($ficha, $instructor);
        $this->crearPostulacion($convocatoria, $aspirante);

        $this->actingAs($instructor)
            ->getJson("/api/portafolios/{$aspirante->id}")
            ->assertOk()
            ->assertJsonPath('data.usuario.id', $aspirante->id);
    }

    public function test_un_portafolio_inexistente_devuelve_404(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);

        $this->actingAs($instructor)
            ->getJson('/api/portafolios/999')
            ->assertNotFound();
    }
}
