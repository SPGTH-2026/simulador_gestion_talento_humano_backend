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
 * Solo el aspirante dueño escribe documentos, skills y certificados de su
 * postulación. El instructor y el super admin solo LEEN y DESCARGAN los de su
 * alcance por ficha: cualquier intento de escritura responde 403, 404 o 405
 * (la ruta de escritura no existe para ese rol o no es suya) y no cambia nada.
 */
class DocumentosSoloLecturaTest extends TestCase
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

    private function convocatoriaAbierta(Ficha $ficha, User $creador): Convocatoria
    {
        return Convocatoria::unguarded(fn () => Convocatoria::create([
            'ficha_id' => $ficha->id,
            'codigo' => sprintf('CONV-SOLO-%03d', ++self::$codigoSecuencia),
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
    private function base(): array
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->convocatoriaAbierta($ficha, $instructor);
        $convocatoria->documentos()->attach($this->parametroId('tipo_documento', 'hoja_de_vida'), ['obligatorio' => true]);
        $postulacion = $this->crearPostulacion($convocatoria, $aspirante);

        return [$aspirante, $postulacion, $instructor];
    }

    /** Sube un documento como el aspirante dueño (única vía de escritura válida). */
    private function subirComoAspirante(User $aspirante, Postulacion $postulacion): PostulacionDocumento
    {
        $this->actingAs($aspirante)
            ->withHeaders(['Accept' => 'application/json'])
            ->post("/api/postulaciones/{$postulacion->id}/documentos", [
                'codigo' => 'hoja_de_vida',
                'archivo' => $this->pdf('hoja.pdf'),
            ])
            ->assertCreated();

        return PostulacionDocumento::sole();
    }

    private function skill(Postulacion $postulacion): PostulacionSkill
    {
        return PostulacionSkill::create([
            'postulacion_id' => $postulacion->id,
            'nombre' => 'SAP HR',
        ]);
    }

    /** Un 403, 404 o 405 significa que la escritura fue rechazada. */
    private function assertRechazada($respuesta): void
    {
        $this->assertContains(
            $respuesta->getStatusCode(),
            [403, 404, 405],
            'La escritura debía rechazarse y respondió '.$respuesta->getStatusCode().'.',
        );
    }

    // ── Permisos ───────────────────────────────────────────────────────

    public function test_el_permiso_documentos_gestionar_ya_no_existe(): void
    {
        $this->assertNotContains('documentos:gestionar', $this->instructor($this->ficha())->permissions());
        $this->assertNotContains('documentos:gestionar', $this->usuario(Role::SuperAdmin)->permissions());
    }

    // ── Instructor y super admin: escritura rechazada ──────────────────

    public function test_el_instructor_no_puede_subir_ni_reemplazar_un_documento_del_aspirante(): void
    {
        [$aspirante, $postulacion, $instructor] = $this->base();
        $this->subirComoAspirante($aspirante, $postulacion);

        $this->assertRechazada($this->actingAs($instructor)
            ->withHeaders(['Accept' => 'application/json'])
            ->post("/api/postulaciones/{$postulacion->id}/documentos", [
                'codigo' => 'hoja_de_vida',
                'archivo' => $this->pdf('intruso.pdf'),
            ]));

        $this->assertRechazada($this->actingAs($instructor)
            ->withHeaders(['Accept' => 'application/json'])
            ->post("/api/revision/postulaciones/{$postulacion->id}/documentos", [
                'codigo' => 'hoja_de_vida',
                'archivo' => $this->pdf('intruso.pdf'),
            ]));

        $this->assertSame('hoja.pdf', PostulacionDocumento::sole()->nombre_original);
    }

    public function test_el_super_admin_no_puede_subir_ni_reemplazar_un_documento_del_aspirante(): void
    {
        [$aspirante, $postulacion] = $this->base();
        $this->subirComoAspirante($aspirante, $postulacion);
        $superAdmin = $this->usuario(Role::SuperAdmin);

        $this->assertRechazada($this->actingAs($superAdmin)
            ->withHeaders(['Accept' => 'application/json'])
            ->post("/api/postulaciones/{$postulacion->id}/documentos", [
                'codigo' => 'hoja_de_vida',
                'archivo' => $this->pdf('admin.pdf'),
            ]));

        $this->assertRechazada($this->actingAs($superAdmin)
            ->withHeaders(['Accept' => 'application/json'])
            ->post("/api/revision/postulaciones/{$postulacion->id}/documentos", [
                'codigo' => 'hoja_de_vida',
                'archivo' => $this->pdf('admin.pdf'),
            ]));

        $this->assertSame('hoja.pdf', PostulacionDocumento::sole()->nombre_original);
    }

    public function test_el_instructor_no_puede_agregar_ni_reemplazar_adicionales(): void
    {
        [, $postulacion, $instructor] = $this->base();

        $this->assertRechazada($this->actingAs($instructor)
            ->withHeaders(['Accept' => 'application/json'])
            ->post("/api/revision/postulaciones/{$postulacion->id}/documentos/adicionales", [
                'titulo' => 'Extra',
                'archivo' => $this->pdf(),
            ]));

        $this->assertRechazada($this->actingAs($instructor)
            ->withHeaders(['Accept' => 'application/json'])
            ->post("/api/postulaciones/{$postulacion->id}/documentos/adicionales", [
                'titulo' => 'Extra',
                'archivo' => $this->pdf(),
            ]));

        $this->assertSame(0, PostulacionDocumento::count());
    }

    public function test_el_instructor_no_puede_eliminar_un_documento_del_aspirante(): void
    {
        [$aspirante, $postulacion, $instructor] = $this->base();
        $documento = $this->subirComoAspirante($aspirante, $postulacion);
        $ruta = $documento->ruta;

        $this->assertRechazada($this->actingAs($instructor)
            ->deleteJson("/api/revision/postulaciones/{$postulacion->id}/documentos/{$documento->id}"));

        $this->assertRechazada($this->actingAs($instructor)
            ->deleteJson("/api/postulaciones/{$postulacion->id}/documentos/{$documento->id}"));

        $this->assertSame(1, PostulacionDocumento::count());
        Storage::disk('local')->assertExists($ruta);
    }

    public function test_el_super_admin_no_puede_eliminar_un_documento_del_aspirante(): void
    {
        [$aspirante, $postulacion] = $this->base();
        $documento = $this->subirComoAspirante($aspirante, $postulacion);
        $superAdmin = $this->usuario(Role::SuperAdmin);

        $this->assertRechazada($this->actingAs($superAdmin)
            ->deleteJson("/api/revision/postulaciones/{$postulacion->id}/documentos/{$documento->id}"));

        $this->assertSame(1, PostulacionDocumento::count());
    }

    public function test_el_instructor_no_puede_tocar_los_certificados_del_aspirante(): void
    {
        [, $postulacion, $instructor] = $this->base();
        $skill = $this->skill($postulacion);

        $this->assertRechazada($this->actingAs($instructor)
            ->withHeaders(['Accept' => 'application/json'])
            ->post("/api/revision/postulaciones/{$postulacion->id}/skills/{$skill->id}/certificado", [
                'archivo' => $this->pdf('certificado.pdf'),
            ]));

        $this->assertRechazada($this->actingAs($instructor)
            ->withHeaders(['Accept' => 'application/json'])
            ->post("/api/postulaciones/{$postulacion->id}/skills/{$skill->id}/certificado", [
                'archivo' => $this->pdf('certificado.pdf'),
            ]));

        $this->assertRechazada($this->actingAs($instructor)
            ->deleteJson("/api/revision/postulaciones/{$postulacion->id}/skills/{$skill->id}/certificado"));

        $this->assertNull($skill->fresh()->certificado_ruta);
    }

    public function test_el_super_admin_no_puede_tocar_los_certificados_del_aspirante(): void
    {
        [, $postulacion] = $this->base();
        $skill = $this->skill($postulacion);
        $superAdmin = $this->usuario(Role::SuperAdmin);

        $this->assertRechazada($this->actingAs($superAdmin)
            ->withHeaders(['Accept' => 'application/json'])
            ->post("/api/revision/postulaciones/{$postulacion->id}/skills/{$skill->id}/certificado", [
                'archivo' => $this->pdf('certificado.pdf'),
            ]));

        $this->assertNull($skill->fresh()->certificado_ruta);
    }

    public function test_el_instructor_no_puede_editar_skills_ni_estado_de_la_postulacion_del_aspirante(): void
    {
        [, $postulacion, $instructor] = $this->base();

        $this->assertRechazada($this->actingAs($instructor)
            ->withHeaders(['Accept' => 'application/json'])
            ->postJson("/api/postulaciones/{$postulacion->id}/skills", ['nombre' => 'PHP']));

        $this->assertRechazada($this->actingAs($instructor)
            ->withHeaders(['Accept' => 'application/json'])
            ->patchJson("/api/postulaciones/{$postulacion->id}/estado", ['estado' => 'retirada']));

        $this->assertRechazada($this->actingAs($instructor)
            ->withHeaders(['Accept' => 'application/json'])
            ->postJson("/api/postulaciones/{$postulacion->id}/enviar"));

        $this->assertSame('postulada', $postulacion->fresh()->estado->codigo);
        $this->assertSame(0, PostulacionSkill::count());
    }

    // ── Instructor y super admin: lectura y descarga sí permitidas ────

    public function test_el_instructor_de_la_ficha_ve_y_descarga_los_documentos(): void
    {
        [$aspirante, $postulacion, $instructor] = $this->base();
        $documento = $this->subirComoAspirante($aspirante, $postulacion);

        $this->actingAs($instructor)
            ->getJson("/api/revision/postulaciones/{$postulacion->id}/documentos")
            ->assertOk()
            ->assertJsonPath('data.documentos.0.archivo.nombre_original', 'hoja.pdf');

        $this->actingAs($instructor)
            ->get("/api/revision/postulaciones/{$postulacion->id}/documentos/{$documento->id}/archivo")
            ->assertOk();
    }

    public function test_el_super_admin_ve_y_descarga_los_documentos(): void
    {
        [$aspirante, $postulacion] = $this->base();
        $documento = $this->subirComoAspirante($aspirante, $postulacion);
        $superAdmin = $this->usuario(Role::SuperAdmin);

        $this->actingAs($superAdmin)
            ->getJson("/api/revision/postulaciones/{$postulacion->id}/documentos")
            ->assertOk();

        $this->actingAs($superAdmin)
            ->get("/api/revision/postulaciones/{$postulacion->id}/documentos/{$documento->id}/archivo")
            ->assertOk();
    }

    public function test_el_instructor_de_otra_ficha_no_ve_ni_descarga(): void
    {
        [$aspirante, $postulacion] = $this->base();
        $documento = $this->subirComoAspirante($aspirante, $postulacion);
        $otroInstructor = $this->instructor($this->ficha('9999999'));

        $this->actingAs($otroInstructor)
            ->getJson("/api/revision/postulaciones/{$postulacion->id}/documentos")
            ->assertNotFound();

        $this->actingAs($otroInstructor)
            ->get("/api/revision/postulaciones/{$postulacion->id}/documentos/{$documento->id}/archivo")
            ->assertNotFound();
    }

    // ── Aspirante dueño: escritura permitida ───────────────────────────

    public function test_el_aspirante_dueño_si_puede_subir_su_documento(): void
    {
        [$aspirante, $postulacion] = $this->base();

        $this->actingAs($aspirante)
            ->withHeaders(['Accept' => 'application/json'])
            ->post("/api/postulaciones/{$postulacion->id}/documentos", [
                'codigo' => 'hoja_de_vida',
                'archivo' => $this->pdf('mia.pdf'),
            ])
            ->assertCreated();

        $this->assertSame(1, PostulacionDocumento::count());
    }
}
