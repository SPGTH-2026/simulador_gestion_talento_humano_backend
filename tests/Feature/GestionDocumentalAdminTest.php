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
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Gestión documental del administrador (permiso 'documentos:gestionar').
 *
 * El super admin y el instructor pueden REEMPLAZAR y ELIMINAR documentos y
 * certificados de los aspirantes de sus fichas, incluso con la convocatoria
 * cerrada o la evaluación iniciada. El aspirante no ve estos endpoints y un
 * instructor de otra ficha recibe 404 (no se revela la postulación).
 */
class GestionDocumentalAdminTest extends TestCase
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
            'codigo' => sprintf('CONV-GEST-%03d', ++self::$codigoSecuencia),
            'nombre' => 'Convocatoria base',
            'vacantes' => 1,
            'tipo_convocatoria_id' => $this->parametroId('tipo_convocatoria', 'externo'),
            'tipo_empleo_id' => $this->parametroId('tipo_empleo', 'tiempo_completo'),
            'tipo_contrato_id' => $this->parametroId('tipo_contrato', 'termino_fijo'),
            'modalidad_id' => $this->parametroId('modalidad', 'presencial'),
            'estado_id' => $this->parametroId('estado_convocatoria', 'borrador'),
            'creado_por' => $creador->id,
        ]));
    }

    private function convocatoriaAbierta(Ficha $ficha, User $creador, array $overrides = []): Convocatoria
    {
        return $this->crearConvocatoria($ficha, $creador, $overrides + [
            'estado_id' => $this->parametroId('estado_convocatoria', 'publicada'),
            'fecha_apertura' => now('America/Bogota')->subDay()->toDateString(),
            'fecha_cierre' => now('America/Bogota')->addDay()->toDateString(),
        ]);
    }

    /** @param array<string, bool> $documentos codigo => obligatorio */
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

    private function crearPostulacion(Convocatoria $convocatoria, User $usuario, string $estado = 'postulada'): Postulacion
    {
        return Postulacion::create([
            'convocatoria_id' => $convocatoria->id,
            'user_id' => $usuario->id,
            'estado_id' => $this->parametroId('estado_postulacion', $estado),
            'fecha_postulacion' => now(),
        ]);
    }

    private function pdf(string $nombre = 'documento.pdf'): UploadedFile
    {
        return UploadedFile::fake()->create($nombre, 100, 'application/pdf');
    }

    /** @return array{0: User, 1: Postulacion, 2: User} [aspirante, postulacion, instructor] */
    private function base(array $documentos = ['hoja_de_vida' => true], array $convocatoriaOverrides = []): array
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->conDocumentos(
            $this->convocatoriaAbierta($ficha, $instructor, $convocatoriaOverrides),
            $documentos,
        );
        $postulacion = $this->crearPostulacion($convocatoria, $aspirante);

        return [$aspirante, $postulacion, $instructor];
    }

    // ── Reemplazo de documentos requeridos ─────────────────────────────

    public function test_el_instructor_reemplaza_un_documento_requerido_y_queda_auditado(): void
    {
        Log::spy();

        [$aspirante, $postulacion, $instructor] = $this->base();

        $respuesta = $this->actingAs($instructor)
            ->withHeaders(['Accept' => 'application/json'])
            ->post("/api/revision/postulaciones/{$postulacion->id}/documentos", [
                'codigo' => 'hoja_de_vida',
                'archivo' => $this->pdf('corregida.pdf'),
            ])
            ->assertCreated();

        $this->assertSame('corregida.pdf', $respuesta->json('data.nombre_original'));

        $documento = PostulacionDocumento::sole();
        $this->assertSame('corregida.pdf', $documento->nombre_original);
        Storage::disk('local')->assertExists($documento->ruta);

        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $mensaje, array $contexto) => $mensaje === 'Gestión documental del administrador.'
                && $contexto['accion'] === 'documento_requerido_reemplazado'
                && $contexto['actor_id'] === $instructor->id
                && $contexto['aspirante_id'] === $aspirante->id);
    }

    public function test_reemplazar_el_mismo_codigo_devuelve_200_y_no_duplica(): void
    {
        [$aspirante, $postulacion, $instructor] = $this->base();

        $this->actingAs($instructor)
            ->withHeaders(['Accept' => 'application/json'])
            ->post("/api/revision/postulaciones/{$postulacion->id}/documentos", [
                'codigo' => 'hoja_de_vida',
                'archivo' => $this->pdf('primera.pdf'),
            ])
            ->assertCreated();

        $anterior = PostulacionDocumento::sole()->ruta;

        $this->actingAs($instructor)
            ->withHeaders(['Accept' => 'application/json'])
            ->post("/api/revision/postulaciones/{$postulacion->id}/documentos", [
                'codigo' => 'hoja_de_vida',
                'archivo' => $this->pdf('segunda.pdf'),
            ])
            ->assertOk();

        $this->assertSame(1, PostulacionDocumento::count());
        $this->assertSame('segunda.pdf', PostulacionDocumento::sole()->nombre_original);
        Storage::disk('local')->assertMissing($anterior);
        Storage::disk('local')->assertExists(PostulacionDocumento::sole()->ruta);
    }

    public function test_el_super_admin_gestiona_documentos_de_cualquier_ficha(): void
    {
        [, $postulacion] = $this->base();
        $superAdmin = $this->usuario(Role::SuperAdmin);

        $this->actingAs($superAdmin)
            ->withHeaders(['Accept' => 'application/json'])
            ->post("/api/revision/postulaciones/{$postulacion->id}/documentos", [
                'codigo' => 'hoja_de_vida',
                'archivo' => $this->pdf('admin.pdf'),
            ])
            ->assertCreated();
    }

    public function test_la_gestion_corrige_una_postulacion_con_la_evaluacion_iniciada(): void
    {
        $ayer = now('America/Bogota')->subDay()->toDateString();
        $hace3 = now('America/Bogota')->subDays(3)->toDateString();

        // Convocatoria con el cierre y la evaluación ya pasados: el aspirante
        // quedó en solo consulta; el admin sí corrige, porque su tarea es dejar
        // el expediente en orden.
        [$aspirante, $postulacion, $instructor] = $this->base(
            ['hoja_de_vida' => true],
            ['fecha_cierre' => $hace3, 'fecha_evaluacion' => $ayer],
        );

        $this->actingAs($aspirante)
            ->withHeaders(['Accept' => 'application/json'])
            ->post("/api/postulaciones/{$postulacion->id}/documentos", [
                'codigo' => 'hoja_de_vida',
                'archivo' => $this->pdf('tarde.pdf'),
            ])
            ->assertStatus(422);

        $this->actingAs($instructor)
            ->withHeaders(['Accept' => 'application/json'])
            ->post("/api/revision/postulaciones/{$postulacion->id}/documentos", [
                'codigo' => 'hoja_de_vida',
                'archivo' => $this->pdf('corregida.pdf'),
            ])
            ->assertCreated();
    }

    public function test_la_gestion_permite_corregir_un_borrador(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->conDocumentos($this->convocatoriaAbierta($ficha, $instructor), [
            'hoja_de_vida' => true,
        ]);
        $postulacion = $this->crearPostulacion($convocatoria, $aspirante, 'borrador');

        $this->actingAs($instructor)
            ->withHeaders(['Accept' => 'application/json'])
            ->post("/api/revision/postulaciones/{$postulacion->id}/documentos", [
                'codigo' => 'hoja_de_vida',
                'archivo' => $this->pdf('borrador.pdf'),
            ])
            ->assertCreated();
    }

    // ── Alcance y permisos ─────────────────────────────────────────────

    public function test_instructor_de_otra_ficha_recibe_404(): void
    {
        [, $postulacion] = $this->base();
        $otroInstructor = $this->instructor($this->ficha('9999999'));

        $this->actingAs($otroInstructor)
            ->withHeaders(['Accept' => 'application/json'])
            ->post("/api/revision/postulaciones/{$postulacion->id}/documentos", [
                'codigo' => 'hoja_de_vida',
                'archivo' => $this->pdf(),
            ])
            ->assertNotFound();
    }

    public function test_el_aspirante_no_puede_usar_la_gestion(): void
    {
        [$aspirante, $postulacion] = $this->base();

        $this->actingAs($aspirante)
            ->withHeaders(['Accept' => 'application/json'])
            ->post("/api/revision/postulaciones/{$postulacion->id}/documentos", [
                'codigo' => 'hoja_de_vida',
                'archivo' => $this->pdf(),
            ])
            ->assertForbidden();
    }

    public function test_el_aprendiz_evaluador_solo_lee_no_gestiona(): void
    {
        [$aspirante, $postulacion] = $this->base();
        $evaluador = $this->usuario(Role::Aprendiz, [
            'subrole' => 'evaluador',
            'ficha_id' => $aspirante->ficha_id,
        ]);

        $this->actingAs($evaluador)
            ->withHeaders(['Accept' => 'application/json'])
            ->post("/api/revision/postulaciones/{$postulacion->id}/documentos", [
                'codigo' => 'hoja_de_vida',
                'archivo' => $this->pdf(),
            ])
            ->assertForbidden();
    }

    public function test_el_documento_debe_pertenecer_a_la_convocatoria(): void
    {
        [, $postulacion, $instructor] = $this->base();

        $this->actingAs($instructor)
            ->withHeaders(['Accept' => 'application/json'])
            ->post("/api/revision/postulaciones/{$postulacion->id}/documentos", [
                'codigo' => 'libreta_militar',
                'archivo' => $this->pdf(),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('codigo');
    }

    // ── Eliminación ────────────────────────────────────────────────────

    public function test_el_instructor_elimina_un_documento_y_su_archivo(): void
    {
        [$aspirante, $postulacion, $instructor] = $this->base();

        $this->actingAs($aspirante)
            ->withHeaders(['Accept' => 'application/json'])
            ->post("/api/postulaciones/{$postulacion->id}/documentos", [
                'codigo' => 'hoja_de_vida',
                'archivo' => $this->pdf('mala.pdf'),
            ])
            ->assertCreated();

        $documento = PostulacionDocumento::sole();
        $ruta = $documento->ruta;

        $this->actingAs($instructor)
            ->deleteJson("/api/revision/postulaciones/{$postulacion->id}/documentos/{$documento->id}")
            ->assertNoContent();

        $this->assertSame(0, PostulacionDocumento::count());
        Storage::disk('local')->assertMissing($ruta);
    }

    public function test_no_se_puede_borrar_un_documento_de_otra_postulacion(): void
    {
        [$aspirante, $postulacion, $instructor] = $this->base();

        $otraPostulacion = $this->crearPostulacion($postulacion->convocatoria, $this->aspirante($aspirante->ficha));

        $this->actingAs($aspirante)
            ->withHeaders(['Accept' => 'application/json'])
            ->post("/api/postulaciones/{$postulacion->id}/documentos", [
                'codigo' => 'hoja_de_vida',
                'archivo' => $this->pdf(),
            ]);

        $documento = PostulacionDocumento::sole();

        $this->actingAs($instructor)
            ->deleteJson("/api/revision/postulaciones/{$otraPostulacion->id}/documentos/{$documento->id}")
            ->assertNotFound();
    }

    // ── Adicionales ────────────────────────────────────────────────────

    public function test_el_admin_agrega_y_reemplaza_un_adicional(): void
    {
        [, $postulacion, $instructor] = $this->base();

        $creado = $this->actingAs($instructor)
            ->withHeaders(['Accept' => 'application/json'])
            ->post("/api/revision/postulaciones/{$postulacion->id}/documentos/adicionales", [
                'titulo' => 'Certificado extra',
                'nota' => 'Anexo del administrador',
                'archivo' => $this->pdf('extra.pdf'),
            ])
            ->assertCreated()
            ->json('data');

        $anterior = PostulacionDocumento::sole()->ruta;

        $this->actingAs($instructor)
            ->withHeaders(['Accept' => 'application/json'])
            ->post("/api/revision/postulaciones/{$postulacion->id}/documentos/adicionales", [
                'documento_id' => $creado['archivo']['id'],
                'titulo' => 'Certificado extra corregido',
                'archivo' => $this->pdf('extra-v2.pdf'),
            ])
            ->assertOk();

        $this->assertSame(1, PostulacionDocumento::count());

        $documento = PostulacionDocumento::sole();
        $this->assertSame('Certificado extra corregido', $documento->titulo);
        $this->assertSame('extra-v2.pdf', $documento->nombre_original);
        Storage::disk('local')->assertMissing($anterior);
        Storage::disk('local')->assertExists($documento->ruta);
    }

    public function test_reemplazar_un_adicional_inexistente_da_422(): void
    {
        [, $postulacion, $instructor] = $this->base();

        $this->actingAs($instructor)
            ->withHeaders(['Accept' => 'application/json'])
            ->post("/api/revision/postulaciones/{$postulacion->id}/documentos/adicionales", [
                'documento_id' => 999999,
                'titulo' => 'Fantasma',
                'archivo' => $this->pdf(),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('documento_id');
    }

    // ── Certificados de skills ─────────────────────────────────────────

    private function skill(Postulacion $postulacion, string $nombre = 'SAP HR'): PostulacionSkill
    {
        return PostulacionSkill::create([
            'postulacion_id' => $postulacion->id,
            'nombre' => $nombre,
        ]);
    }

    public function test_el_admin_reemplaza_y_quita_el_certificado_de_una_skill(): void
    {
        [$aspirante, $postulacion, $instructor] = $this->base();
        $skill = $this->skill($postulacion);

        $this->actingAs($instructor)
            ->withHeaders(['Accept' => 'application/json'])
            ->post("/api/revision/postulaciones/{$postulacion->id}/skills/{$skill->id}/certificado", [
                'archivo' => $this->pdf('certificado.pdf'),
            ])
            ->assertOk()
            ->assertJsonPath('data.certificado.nombre_original', 'certificado.pdf');

        $skill->refresh();
        $ruta = $skill->certificado_ruta;
        $this->assertNotNull($ruta);
        Storage::disk('local')->assertExists($ruta);

        $this->actingAs($instructor)
            ->deleteJson("/api/revision/postulaciones/{$postulacion->id}/skills/{$skill->id}/certificado")
            ->assertNoContent();

        $this->assertNull($skill->fresh()->certificado_ruta);
        Storage::disk('local')->assertMissing($ruta);
    }

    public function test_no_se_puede_tocar_el_certificado_de_una_skill_de_otra_postulacion(): void
    {
        [$aspirante, $postulacion, $instructor] = $this->base();
        $skill = $this->skill($postulacion);

        $otraPostulacion = $this->crearPostulacion(
            $postulacion->convocatoria,
            $this->aspirante($aspirante->ficha),
        );

        $this->actingAs($instructor)
            ->withHeaders(['Accept' => 'application/json'])
            ->post("/api/revision/postulaciones/{$otraPostulacion->id}/skills/{$skill->id}/certificado", [
                'archivo' => $this->pdf(),
            ])
            ->assertNotFound();
    }
}
