<?php

namespace App\Tests\Feature;

use App\Enums\Role;
use App\Models\Convocatoria;
use App\Models\Ficha;
use App\Models\Parametro;
use App\Models\Postulacion;
use App\Models\User;
use Database\Seeders\ParametroSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Flujo "documentos antes de postularse".
 *
 * La postulación nace en BORRADOR: el aspirante completa sexo, documentos y
 * skills, y solo enviar() la pasa a 'postulada' validando los obligatorios. Un
 * borrador NO cuenta como postulante (conteos, listado de revisión y regla H).
 *
 * Cubre POST .../postulaciones (crear/retomar/reabrir), POST .../enviar
 * (obligatorios, libreta por sexo, ventana, carreras), editabilidad del
 * borrador, revisión 404, mi_postulacion, GET /postulaciones y postulantes.
 */
class PostulacionBorradorTest extends TestCase
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
            'subrole' => null,
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

    private function revisor(Ficha $ficha): User
    {
        return $this->usuario(Role::Aprendiz, ['subrole' => 'revisor_documental', 'ficha_id' => $ficha->id]);
    }

    private function parametroId(string $tema, string $codigo): int
    {
        return (int) Parametro::porTemaYCodigo($tema, $codigo)->id;
    }

    private function crearConvocatoria(Ficha $ficha, User $creador, array $overrides = []): Convocatoria
    {
        return Convocatoria::unguarded(fn () => Convocatoria::create($overrides + [
            'ficha_id' => $ficha->id,
            'codigo' => sprintf('CONV-BOR-%03d', ++self::$codigoSecuencia),
            'nombre' => 'Convocatoria de prueba',
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

    private function crearPostulacion(Convocatoria $convocatoria, User $usuario, array $overrides = []): Postulacion
    {
        return Postulacion::unguarded(fn () => Postulacion::create($overrides + [
            'convocatoria_id' => $convocatoria->id,
            'user_id' => $usuario->id,
            'estado_id' => $this->parametroId('estado_postulacion', 'borrador'),
            'fecha_postulacion' => now()->subDays(3),
        ]));
    }

    private function pdf(string $nombre = 'documento.pdf'): UploadedFile
    {
        return UploadedFile::fake()->create($nombre, 100, 'application/pdf');
    }

    private function subirDocumento(User $aspirante, Postulacion $postulacion, string $codigo): void
    {
        $this->actingAs($aspirante)
            ->withHeaders(['Accept' => 'application/json'])
            ->post("/api/postulaciones/{$postulacion->id}/documentos", [
                'codigo' => $codigo,
                'archivo' => $this->pdf($codigo.'.pdf'),
            ])
            ->assertSuccessful();
    }

    // ── POST: crear / retomar borrador ─────────────────────────────────

    public function test_el_post_crea_el_borrador_con_201(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->convocatoriaAbierta($ficha, $instructor);

        $respuesta = $this->actingAs($aspirante)
            ->postJson("/api/convocatorias/{$convocatoria->id}/postulaciones")
            ->assertCreated();

        $this->assertSame('borrador', $respuesta->json('data.estado.codigo'));
    }

    public function test_un_segundo_post_devuelve_200_y_el_mismo_borrador(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->convocatoriaAbierta($ficha, $instructor);

        $primera = $this->actingAs($aspirante)
            ->postJson("/api/convocatorias/{$convocatoria->id}/postulaciones")
            ->assertCreated();

        $segunda = $this->actingAs($aspirante)
            ->postJson("/api/convocatorias/{$convocatoria->id}/postulaciones")
            ->assertOk();

        $this->assertSame($primera->json('data.id'), $segunda->json('data.id'));
        $this->assertSame('borrador', $segunda->json('data.estado.codigo'));
        $this->assertSame(1, Postulacion::count());
    }

    // ── envío ──────────────────────────────────────────────────────────

    public function test_enviar_sin_documentos_obligatorios_pasa_a_postulada(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        // Obligatorio opcional: no bloquea el envío. Sin obligatorios, envía.
        $convocatoria = $this->convocatoriaAbierta($ficha, $instructor);
        $postulacion = $this->crearPostulacion($convocatoria, $aspirante);

        $respuesta = $this->actingAs($aspirante)
            ->postJson("/api/postulaciones/{$postulacion->id}/enviar")
            ->assertOk();

        $this->assertSame('postulada', $respuesta->json('data.estado.codigo'));
        $this->assertSame(
            $this->parametroId('estado_postulacion', 'postulada'),
            $postulacion->fresh()->estado_id,
        );

        // La fecha real de postulación se fija al enviar (no la de creación).
        $this->assertTrue($postulacion->fresh()->fecha_postulacion->greaterThan(now()->subSeconds(30)));
    }

    public function test_enviar_con_obligatorio_pendiente_devuelve_422_con_faltantes(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->convocatoriaAbierta($ficha, $instructor);
        $convocatoria->documentos()->attach($this->parametroId('tipo_documento', 'hoja_de_vida'), ['obligatorio' => true]);
        $convocatoria->documentos()->attach($this->parametroId('tipo_documento', 'certificados_academicos'), ['obligatorio' => false]);
        $postulacion = $this->crearPostulacion($convocatoria, $aspirante);

        $respuesta = $this->actingAs($aspirante)
            ->postJson("/api/postulaciones/{$postulacion->id}/enviar")
            ->assertStatus(422);

        $this->assertSame('hoja_de_vida', $respuesta->json('faltantes.0.codigo'));
        $this->assertSame('Hoja de vida', $respuesta->json('faltantes.0.nombre'));
        $this->assertStringContainsString('Hoja de vida', $respuesta->json('errors.documentos.0'));
        // Sigue en borrador.
        $this->assertSame($this->parametroId('estado_postulacion', 'borrador'), $postulacion->fresh()->estado_id);
    }

    public function test_el_opcional_pendiente_no_bloquea_el_envio(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->convocatoriaAbierta($ficha, $instructor);
        $convocatoria->documentos()->attach($this->parametroId('tipo_documento', 'certificados_academicos'), ['obligatorio' => false]);
        $postulacion = $this->crearPostulacion($convocatoria, $aspirante);

        $this->actingAs($aspirante)
            ->postJson("/api/postulaciones/{$postulacion->id}/enviar")
            ->assertOk()
            ->assertJsonPath('data.estado.codigo', 'postulada');
    }

    public function test_enviar_con_los_obligatorios_subidos_pasa_a_postulada(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->convocatoriaAbierta($ficha, $instructor);
        $convocatoria->documentos()->attach($this->parametroId('tipo_documento', 'hoja_de_vida'), ['obligatorio' => true]);
        $postulacion = $this->crearPostulacion($convocatoria, $aspirante);

        $this->subirDocumento($aspirante, $postulacion, 'hoja_de_vida');

        $this->actingAs($aspirante)
            ->postJson("/api/postulaciones/{$postulacion->id}/enviar")
            ->assertOk()
            ->assertJsonPath('data.estado.codigo', 'postulada');
    }

    public function test_sin_sexo_y_libreta_pedida_el_faltante_explica_indicar_el_sexo(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->convocatoriaAbierta($ficha, $instructor);
        $convocatoria->documentos()->attach($this->parametroId('tipo_documento', 'libreta_militar'), ['obligatorio' => true]);
        $postulacion = $this->crearPostulacion($convocatoria, $aspirante);

        $respuesta = $this->actingAs($aspirante)
            ->postJson("/api/postulaciones/{$postulacion->id}/enviar")
            ->assertStatus(422);

        $this->assertSame('libreta_militar', $respuesta->json('faltantes.0.codigo'));
        $this->assertSame(
            'Indica tu sexo para saber si aplica la libreta militar.',
            $respuesta->json('faltantes.0.motivo'),
        );
        $this->assertStringContainsString(
            'Indica tu sexo para saber si aplica la libreta militar.',
            $respuesta->json('errors.documentos.0'),
        );
    }

    public function test_mujer_con_libreta_pedida_envia_sin_subirla(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->convocatoriaAbierta($ficha, $instructor);
        $convocatoria->documentos()->attach($this->parametroId('tipo_documento', 'libreta_militar'), ['obligatorio' => true]);
        $postulacion = $this->crearPostulacion($convocatoria, $aspirante);

        $this->actingAs($aspirante)->putJson('/api/perfil/sexo', ['sexo' => 'mujer'])->assertOk();

        $this->actingAs($aspirante)
            ->postJson("/api/postulaciones/{$postulacion->id}/enviar")
            ->assertOk()
            ->assertJsonPath('data.estado.codigo', 'postulada');
    }

    public function test_hombre_con_libreta_pedida_debe_subirla(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->convocatoriaAbierta($ficha, $instructor);
        $convocatoria->documentos()->attach($this->parametroId('tipo_documento', 'libreta_militar'), ['obligatorio' => true]);
        $postulacion = $this->crearPostulacion($convocatoria, $aspirante);

        $this->actingAs($aspirante)->putJson('/api/perfil/sexo', ['sexo' => 'hombre'])->assertOk();

        $respuesta = $this->actingAs($aspirante)
            ->postJson("/api/postulaciones/{$postulacion->id}/enviar")
            ->assertStatus(422);

        $this->assertSame('libreta_militar', $respuesta->json('faltantes.0.codigo'));
        // Con sexo registrado ya no se pide indicarlo.
        $this->assertNull($respuesta->json('faltantes.0.motivo'));

        $this->subirDocumento($aspirante, $postulacion, 'libreta_militar');

        $this->actingAs($aspirante)
            ->postJson("/api/postulaciones/{$postulacion->id}/enviar")
            ->assertOk()
            ->assertJsonPath('data.estado.codigo', 'postulada');
    }

    public function test_enviar_una_postulada_devuelve_422(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->convocatoriaAbierta($ficha, $instructor);
        $postulacion = $this->crearPostulacion($convocatoria, $aspirante, [
            'estado_id' => $this->parametroId('estado_postulacion', 'postulada'),
        ]);

        $this->actingAs($aspirante)
            ->postJson("/api/postulaciones/{$postulacion->id}/enviar")
            ->assertStatus(422)
            ->assertJsonPath('errors.postulacion.0', 'Solo se puede enviar una postulación en borrador.');
    }

    public function test_enviar_una_postulacion_ajena_devuelve_404(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspiranteA = $this->aspirante($ficha);
        $aspiranteB = $this->aspirante($ficha);
        $convocatoria = $this->convocatoriaAbierta($ficha, $instructor);
        $postulacionB = $this->crearPostulacion($convocatoria, $aspiranteB);

        $this->actingAs($aspiranteA)
            ->postJson("/api/postulaciones/{$postulacionB->id}/enviar")
            ->assertNotFound()
            ->assertJsonPath('message', 'Postulación no encontrada.');
    }

    public function test_enviar_con_la_ventana_cerrada_devuelve_422(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->crearConvocatoria($ficha, $instructor, [
            'estado_id' => $this->parametroId('estado_convocatoria', 'publicada'),
            'fecha_apertura' => now('America/Bogota')->subDays(10)->toDateString(),
            'fecha_cierre' => now('America/Bogota')->subDay()->toDateString(),
        ]);
        $postulacion = $this->crearPostulacion($convocatoria, $aspirante);

        $this->actingAs($aspirante)
            ->postJson("/api/postulaciones/{$postulacion->id}/enviar")
            ->assertStatus(422)
            ->assertJsonPath(
                'errors.postulacion.0',
                'La convocatoria ya cerró el '.$convocatoria->fecha_cierre->toDateString().'.',
            );
    }

    public function test_enviar_con_la_convocatoria_no_publicada_devuelve_404(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->crearConvocatoria($ficha, $instructor); // borrador
        $postulacion = $this->crearPostulacion($convocatoria, $aspirante);

        $this->actingAs($aspirante)
            ->postJson("/api/postulaciones/{$postulacion->id}/enviar")
            ->assertNotFound()
            ->assertJsonPath('message', 'Convocatoria no encontrada.');
    }

    public function test_carrera_en_el_envio_devuelve_422(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->convocatoriaAbierta($ficha, $instructor);
        $postulacion = $this->crearPostulacion($convocatoria, $aspirante);
        $postuladaId = $this->parametroId('estado_postulacion', 'postulada');

        // Otra petición envía/gana la carrera antes del UPDATE condicionado.
        $evento = 'eloquent.retrieved: '.Postulacion::class;
        Postulacion::retrieved(fn () => DB::table('postulaciones')->where('id', $postulacion->id)->update([
            'estado_id' => $postuladaId,
            'updated_at' => now(),
        ]));

        try {
            $this->actingAs($aspirante)
                ->postJson("/api/postulaciones/{$postulacion->id}/enviar")
                ->assertStatus(422)
                ->assertJsonPath('errors.postulacion.0', 'No se pudo enviar la postulación: su estado cambió. Inténtalo de nuevo.');
        } finally {
            Event::forget($evento);
        }
    }

    // ── El borrador se puede completar (documentos, skills, estado) ─────

    public function test_el_borrador_es_editable_y_su_get_lo_refleja(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->convocatoriaAbierta($ficha, $instructor);
        $convocatoria->documentos()->attach($this->parametroId('tipo_documento', 'hoja_de_vida'), ['obligatorio' => true]);
        $postulacion = $this->crearPostulacion($convocatoria, $aspirante);

        $this->actingAs($aspirante)
            ->getJson("/api/postulaciones/{$postulacion->id}/documentos")
            ->assertOk()
            ->assertJsonPath('data.editable', true)
            ->assertJsonPath('data.completo', false)
            ->assertJsonPath('data.postulacion.estado.codigo', 'borrador');

        $this->subirDocumento($aspirante, $postulacion, 'hoja_de_vida');

        $this->actingAs($aspirante)
            ->getJson("/api/postulaciones/{$postulacion->id}/documentos")
            ->assertOk()
            ->assertJsonPath('data.completo', true);
    }

    public function test_un_borrador_no_entra_a_revision(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $revisor = $this->revisor($ficha);
        $convocatoria = $this->convocatoriaAbierta($ficha, $instructor);
        $postulacion = $this->crearPostulacion($convocatoria, $aspirante);

        $this->actingAs($revisor)
            ->getJson("/api/revision/postulaciones/{$postulacion->id}/documentos")
            ->assertNotFound()
            ->assertJsonPath('message', 'Postulación no encontrada.');
    }

    // ── Visibilidad: listado propio, mi_postulacion y conteos ──────────

    public function test_el_listado_de_mis_postulaciones_incluye_el_borrador(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->convocatoriaAbierta($ficha, $instructor);
        $postulacion = $this->crearPostulacion($convocatoria, $aspirante);

        $this->actingAs($aspirante)
            ->getJson('/api/postulaciones')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $postulacion->id)
            ->assertJsonPath('data.0.estado.codigo', 'borrador');
    }

    public function test_mi_postulacion_expone_el_borrador(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->convocatoriaAbierta($ficha, $instructor);
        $postulacion = $this->crearPostulacion($convocatoria, $aspirante);

        $this->actingAs($aspirante)
            ->getJson("/api/convocatorias/{$convocatoria->id}")
            ->assertOk()
            ->assertJsonPath('data.mi_postulacion.id', $postulacion->id)
            ->assertJsonPath('data.mi_postulacion.estado.codigo', 'borrador');
    }

    public function test_el_borrador_no_cuenta_como_postulante(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->convocatoriaAbierta($ficha, $instructor);
        $postulacion = $this->crearPostulacion($convocatoria, $aspirante);

        $this->actingAs($instructor)
            ->getJson("/api/convocatorias/{$convocatoria->id}")
            ->assertOk()
            ->assertJsonPath('data.postulantes', 0);

        // Tras enviar, sí cuenta.
        $this->actingAs($aspirante)
            ->postJson("/api/postulaciones/{$postulacion->id}/enviar")
            ->assertOk();

        $this->actingAs($instructor)
            ->getJson("/api/convocatorias/{$convocatoria->id}")
            ->assertOk()
            ->assertJsonPath('data.postulantes', 1);
    }
}
