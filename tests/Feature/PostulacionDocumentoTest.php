<?php

namespace App\Tests\Feature;

use App\Enums\Role;
use App\Models\Convocatoria;
use App\Models\ConvocatoriaSkill;
use App\Models\Ficha;
use App\Models\Parametro;
use App\Models\Postulacion;
use App\Models\PostulacionDocumento;
use App\Models\User;
use Database\Seeders\ParametroSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Paso 4: documentos de la postulación.
 *
 * Cubre GET/POST/DELETE de /api/postulaciones/{postulacion}/documentos y la
 * lectura de revisores en /api/revision/...: aislamiento del dueño (404),
 * editabilidad por ventana y estado, PDF <= 3 MB, adicionales con tope, regla
 * de la libreta militar por sexo y descarga autorizada.
 */
class PostulacionDocumentoTest extends TestCase
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
            'codigo' => sprintf('CONV-DOC-%03d', ++self::$codigoSecuencia),
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

    private function crearPostulacion(Convocatoria $convocatoria, User $usuario, array $overrides = []): Postulacion
    {
        return Postulacion::create($overrides + [
            'convocatoria_id' => $convocatoria->id,
            'user_id' => $usuario->id,
            'estado_id' => $this->parametroId('estado_postulacion', 'postulada'),
            'fecha_postulacion' => now(),
        ]);
    }

    private function pdf(string $nombre = 'documento.pdf'): UploadedFile
    {
        return UploadedFile::fake()->create($nombre, 100, 'application/pdf');
    }

    // ── Estado (dueño) ─────────────────────────────────────────────────

    public function test_dueno_ve_el_estado_con_documentos_pendientes(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->conDocumentos($this->convocatoriaAbierta($ficha, $instructor), [
            'hoja_de_vida' => true,
            'certificados_academicos' => false,
        ]);
        $postulacion = $this->crearPostulacion($convocatoria, $aspirante);

        $respuesta = $this->actingAs($aspirante)
            ->getJson("/api/postulaciones/{$postulacion->id}/documentos")
            ->assertOk();

        $respuesta->assertJsonPath('data.editable', true);
        $respuesta->assertJsonPath('data.completo', false);
        $respuesta->assertJsonPath('data.requiere_sexo', false);
        $respuesta->assertJsonPath('data.adicionales', []);
        $respuesta->assertJsonPath('data.skills', []);

        $documentos = collect($respuesta->json('data.documentos'))
            ->keyBy(fn (array $d) => $d['parametro']['codigo']);

        $this->assertSame('pendiente', $documentos['hoja_de_vida']['estado']);
        $this->assertTrue($documentos['hoja_de_vida']['obligatorio']);
        $this->assertSame('pendiente', $documentos['certificados_academicos']['estado']);
        $this->assertFalse($documentos['certificados_academicos']['obligatorio']);
    }

    public function test_postulacion_ajena_devuelve_404(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspiranteA = $this->aspirante($ficha);
        $aspiranteB = $this->aspirante($ficha);
        $convocatoria = $this->convocatoriaAbierta($ficha, $instructor);
        $postulacionB = $this->crearPostulacion($convocatoria, $aspiranteB);

        $this->actingAs($aspiranteA)
            ->getJson("/api/postulaciones/{$postulacionB->id}/documentos")
            ->assertNotFound()
            ->assertJsonPath('message', 'Postulación no encontrada.');
    }

    public function test_postulacion_inexistente_devuelve_404(): void
    {
        $aspirante = $this->aspirante($this->ficha());

        $this->actingAs($aspirante)
            ->getJson('/api/postulaciones/999999/documentos')
            ->assertNotFound()
            ->assertJsonPath('message', 'Postulación no encontrada.');
    }

    // ── Subir / reemplazar ─────────────────────────────────────────────

    public function test_sube_un_documento_pdf_devuelve_201(): void
    {
        [$aspirante, $postulacion] = $this->baseConDocumentos(['hoja_de_vida' => true]);

        $this->actingAs($aspirante)
            ->withHeaders(['Accept' => 'application/json'])
            ->post("/api/postulaciones/{$postulacion->id}/documentos", [
                'codigo' => 'hoja_de_vida',
                'archivo' => $this->pdf('mi-hoja.pdf'),
            ])
            ->assertCreated();

        $documento = PostulacionDocumento::sole();
        $this->assertSame('mi-hoja.pdf', $documento->nombre_original);
        $this->assertSame('local', $documento->disco);
        $this->assertSame('application/pdf', $documento->mime);
        Storage::disk('local')->assertExists($documento->ruta);
    }

    public function test_reemplazar_el_mismo_tipo_actualiza_la_fila_y_borra_el_archivo_viejo(): void
    {
        [$aspirante, $postulacion] = $this->baseConDocumentos(['hoja_de_vida' => true]);
        $url = "/api/postulaciones/{$postulacion->id}/documentos";

        $this->actingAs($aspirante)->withHeaders(['Accept' => 'application/json'])
            ->post($url, ['codigo' => 'hoja_de_vida', 'archivo' => $this->pdf('v1.pdf')])
            ->assertCreated();

        $rutaVieja = PostulacionDocumento::sole()->ruta;

        $this->actingAs($aspirante)->withHeaders(['Accept' => 'application/json'])
            ->post($url, ['codigo' => 'hoja_de_vida', 'archivo' => $this->pdf('v2.pdf')])
            ->assertOk();

        $this->assertSame(1, PostulacionDocumento::count());
        $this->assertSame('v2.pdf', PostulacionDocumento::sole()->nombre_original);
        Storage::disk('local')->assertMissing($rutaVieja);
    }

    public function test_documento_que_no_es_de_la_convocatoria_devuelve_422(): void
    {
        [$aspirante, $postulacion] = $this->baseConDocumentos(['hoja_de_vida' => true]);

        $this->actingAs($aspirante)->withHeaders(['Accept' => 'application/json'])
            ->post("/api/postulaciones/{$postulacion->id}/documentos", [
                'codigo' => 'tarjeta_profesional',
                'archivo' => $this->pdf(),
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.codigo.0', 'Ese documento no hace parte de esta convocatoria.');

        $this->assertDatabaseCount('postulacion_documentos', 0);
    }

    public function test_archivo_no_pdf_devuelve_422(): void
    {
        [$aspirante, $postulacion] = $this->baseConDocumentos(['hoja_de_vida' => true]);

        $this->actingAs($aspirante)->withHeaders(['Accept' => 'application/json'])
            ->post("/api/postulaciones/{$postulacion->id}/documentos", [
                'codigo' => 'hoja_de_vida',
                'archivo' => UploadedFile::fake()->create('notas.txt', 10, 'text/plain'),
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.archivo.0', 'El archivo debe ser un PDF.');
    }

    public function test_archivo_mayor_a_5_mb_devuelve_422(): void
    {
        [$aspirante, $postulacion] = $this->baseConDocumentos(['hoja_de_vida' => true]);

        $this->actingAs($aspirante)->withHeaders(['Accept' => 'application/json'])
            ->post("/api/postulaciones/{$postulacion->id}/documentos", [
                'codigo' => 'hoja_de_vida',
                'archivo' => UploadedFile::fake()->create('grande.pdf', 6000, 'application/pdf'),
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.archivo.0', 'El archivo no debe superar los 3 MB.');
    }

    public function test_sin_archivo_devuelve_422(): void
    {
        [$aspirante, $postulacion] = $this->baseConDocumentos(['hoja_de_vida' => true]);

        $this->actingAs($aspirante)->withHeaders(['Accept' => 'application/json'])
            ->post("/api/postulaciones/{$postulacion->id}/documentos", ['codigo' => 'hoja_de_vida'])
            ->assertStatus(422)
            ->assertJsonPath('errors.archivo.0', 'El archivo es obligatorio.');
    }

    public function test_postulada_editable_despues_del_cierre_si_no_empezo_evaluacion(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->conDocumentos($this->crearConvocatoria($ficha, $instructor, [
            'estado_id' => $this->parametroId('estado_convocatoria', 'publicada'),
            'fecha_apertura' => now('America/Bogota')->subDays(10)->toDateString(),
            'fecha_cierre' => now('America/Bogota')->subDay()->toDateString(),
            // fecha_evaluacion null = no ha empezado evaluación
        ]), ['hoja_de_vida' => true]);
        $postulacion = $this->crearPostulacion($convocatoria, $aspirante);

        $url = "/api/postulaciones/{$postulacion->id}/documentos";

        $this->actingAs($aspirante)
            ->getJson($url)
            ->assertOk()
            ->assertJsonPath('data.editable', true)
            ->assertJsonPath('data.ventana', 'cerrada')
            ->assertJsonPath('data.motivo_edicion', null);

        $this->actingAs($aspirante)->withHeaders(['Accept' => 'application/json'])
            ->post($url, ['codigo' => 'hoja_de_vida', 'archivo' => $this->pdf()])
            ->assertCreated();
    }

    public function test_no_se_puede_subir_si_evaluacion_ya_empezo(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->conDocumentos($this->crearConvocatoria($ficha, $instructor, [
            'estado_id' => $this->parametroId('estado_convocatoria', 'publicada'),
            'fecha_apertura' => now('America/Bogota')->subDays(10)->toDateString(),
            'fecha_cierre' => now('America/Bogota')->subDay()->toDateString(),
            'fecha_evaluacion' => now('America/Bogota')->subDay()->toDateString(),
        ]), ['hoja_de_vida' => true]);
        $postulacion = $this->crearPostulacion($convocatoria, $aspirante);

        $url = "/api/postulaciones/{$postulacion->id}/documentos";

        $mensajeEsperado = 'La evaluación ya comenzó el '.$convocatoria->fecha_evaluacion->toDateString().'. Los documentos quedaron solo para consulta.';

        $this->actingAs($aspirante)
            ->getJson($url)
            ->assertOk()
            ->assertJsonPath('data.editable', false)
            ->assertJsonPath('data.motivo_edicion', $mensajeEsperado);

        $this->actingAs($aspirante)->withHeaders(['Accept' => 'application/json'])
            ->post($url, ['codigo' => 'hoja_de_vida', 'archivo' => $this->pdf()])
            ->assertStatus(422)
            ->assertJsonPath('errors.documentos.0', $mensajeEsperado);
    }

    public function test_no_se_puede_subir_con_convocatoria_en_proceso(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->conDocumentos($this->crearConvocatoria($ficha, $instructor, [
            'estado_id' => $this->parametroId('estado_convocatoria', 'en_proceso'),
            'fecha_apertura' => now('America/Bogota')->subDays(10)->toDateString(),
            'fecha_cierre' => now('America/Bogota')->subDay()->toDateString(),
        ]), ['hoja_de_vida' => true]);
        $postulacion = $this->crearPostulacion($convocatoria, $aspirante);

        $url = "/api/postulaciones/{$postulacion->id}/documentos";

        $this->actingAs($aspirante)->withHeaders(['Accept' => 'application/json'])
            ->post($url, ['codigo' => 'hoja_de_vida', 'archivo' => $this->pdf()])
            ->assertNotFound();
    }

    public function test_borrador_no_editable_despues_del_cierre(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->conDocumentos($this->crearConvocatoria($ficha, $instructor, [
            'estado_id' => $this->parametroId('estado_convocatoria', 'publicada'),
            'fecha_apertura' => now('America/Bogota')->subDays(10)->toDateString(),
            'fecha_cierre' => now('America/Bogota')->subDay()->toDateString(),
        ]), ['hoja_de_vida' => true]);
        // crearPostulacion por defecto crea postulada; creamos borrador manual
        $postulacion = Postulacion::create([
            'convocatoria_id' => $convocatoria->id,
            'user_id' => $aspirante->id,
            'estado_id' => $this->parametroId('estado_postulacion', 'borrador'),
            'fecha_postulacion' => now(),
        ]);

        $url = "/api/postulaciones/{$postulacion->id}/documentos";

        $this->actingAs($aspirante)
            ->getJson($url)
            ->assertOk()
            ->assertJsonPath('data.editable', false)
            ->assertJsonPath('data.motivo_edicion', 'La convocatoria ya cerró el '.$convocatoria->fecha_cierre->toDateString().'. Los borradores no se editan fuera de la ventana.');

        $this->actingAs($aspirante)->withHeaders(['Accept' => 'application/json'])
            ->post($url, ['codigo' => 'hoja_de_vida', 'archivo' => $this->pdf()])
            ->assertStatus(422);
    }

    public function test_no_se_puede_subir_antes_de_apertura(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->conDocumentos($this->crearConvocatoria($ficha, $instructor, [
            'estado_id' => $this->parametroId('estado_convocatoria', 'publicada'),
            'fecha_apertura' => now('America/Bogota')->addDays(5)->toDateString(),
            'fecha_cierre' => now('America/Bogota')->addDays(10)->toDateString(),
        ]), ['hoja_de_vida' => true]);
        $postulacion = $this->crearPostulacion($convocatoria, $aspirante);

        $url = "/api/postulaciones/{$postulacion->id}/documentos";

        $this->actingAs($aspirante)
            ->getJson($url)
            ->assertOk()
            ->assertJsonPath('data.editable', false)
            ->assertJsonPath('data.motivo_edicion', 'La convocatoria aún no está abierta. Abre el '.$convocatoria->fecha_apertura->toDateString().'.');

        $this->actingAs($aspirante)->withHeaders(['Accept' => 'application/json'])
            ->post($url, ['codigo' => 'hoja_de_vida', 'archivo' => $this->pdf()])
            ->assertStatus(422);
    }

    public function test_aspirante_sin_sesion_devuelve_401(): void
    {
        $this->postJson('/api/postulaciones/1/documentos')->assertUnauthorized();
    }

    // ── Adicionales ────────────────────────────────────────────────────

    public function test_sube_un_documento_adicional_devuelve_201(): void
    {
        [$aspirante, $postulacion] = $this->baseConDocumentos([]);

        $this->actingAs($aspirante)->withHeaders(['Accept' => 'application/json'])
            ->post("/api/postulaciones/{$postulacion->id}/documentos/adicionales", [
                'titulo' => 'Certificado de inglés',
                'nota' => 'Nivel B2',
                'archivo' => $this->pdf('ingles.pdf'),
            ])
            ->assertCreated()
            ->assertJsonPath('data.titulo', 'Certificado de inglés')
            ->assertJsonPath('data.nota', 'Nivel B2');

        $documento = PostulacionDocumento::sole();
        $this->assertNull($documento->parametro_id);
    }

    public function test_adicional_sin_titulo_devuelve_422(): void
    {
        [$aspirante, $postulacion] = $this->baseConDocumentos([]);

        $this->actingAs($aspirante)->withHeaders(['Accept' => 'application/json'])
            ->post("/api/postulaciones/{$postulacion->id}/documentos/adicionales", [
                'archivo' => $this->pdf(),
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.titulo.0', 'El título del documento adicional es obligatorio.');
    }

    public function test_tope_de_5_adicionales_devuelve_422(): void
    {
        [$aspirante, $postulacion] = $this->baseConDocumentos([]);
        $url = "/api/postulaciones/{$postulacion->id}/documentos/adicionales";

        for ($i = 1; $i <= 5; $i++) {
            $this->actingAs($aspirante)->withHeaders(['Accept' => 'application/json'])
                ->post($url, ['titulo' => "Adicional {$i}", 'archivo' => $this->pdf("a{$i}.pdf")])
                ->assertCreated();
        }

        $this->actingAs($aspirante)->withHeaders(['Accept' => 'application/json'])
            ->post($url, ['titulo' => 'Adicional 6', 'archivo' => $this->pdf('a6.pdf')])
            ->assertStatus(422)
            ->assertJsonPath('errors.titulo.0', 'No puedes agregar más de 5 documentos adicionales.');

        $this->assertDatabaseCount('postulacion_documentos', 5);
    }

    // ── Borrar y descargar ─────────────────────────────────────────────

    public function test_borra_un_documento_y_su_archivo(): void
    {
        [$aspirante, $postulacion] = $this->baseConDocumentos(['hoja_de_vida' => true]);

        $this->actingAs($aspirante)->withHeaders(['Accept' => 'application/json'])
            ->post("/api/postulaciones/{$postulacion->id}/documentos", [
                'codigo' => 'hoja_de_vida', 'archivo' => $this->pdf(),
            ])->assertCreated();

        $documento = PostulacionDocumento::sole();

        $this->actingAs($aspirante)
            ->deleteJson("/api/postulaciones/{$postulacion->id}/documentos/{$documento->id}")
            ->assertNoContent();

        Storage::disk('local')->assertMissing($documento->ruta);
        $this->assertDatabaseCount('postulacion_documentos', 0);
    }

    public function test_descarga_autorizada_del_dueno(): void
    {
        [$aspirante, $postulacion] = $this->baseConDocumentos(['hoja_de_vida' => true]);

        $this->actingAs($aspirante)->withHeaders(['Accept' => 'application/json'])
            ->post("/api/postulaciones/{$postulacion->id}/documentos", [
                'codigo' => 'hoja_de_vida', 'archivo' => $this->pdf('hoja.pdf'),
            ])->assertCreated();

        $documento = PostulacionDocumento::sole();

        $this->actingAs($aspirante)
            ->get("/api/postulaciones/{$postulacion->id}/documentos/{$documento->id}/archivo")
            ->assertOk();
    }

    // ── Visor PDF (?inline=1) ─────────────────────────────────────────

    public function test_el_dueno_lo_pide_en_linea_para_el_visor_y_sigue_descargandose_sin_inline(): void
    {
        [$aspirante, $postulacion] = $this->baseConDocumentos(['hoja_de_vida' => true]);

        $this->actingAs($aspirante)->withHeaders(['Accept' => 'application/json'])
            ->post("/api/postulaciones/{$postulacion->id}/documentos", [
                'codigo' => 'hoja_de_vida', 'archivo' => $this->pdf('hoja.pdf'),
            ])->assertCreated();

        $documento = PostulacionDocumento::sole();

        // El visor (pestaña del navegador) pide inline=1...
        $enLinea = $this->actingAs($aspirante)
            ->get("/api/postulaciones/{$postulacion->id}/documentos/{$documento->id}/archivo?inline=1")
            ->assertOk();
        $this->assertStringStartsWith('inline;', (string) $enLinea->headers->get('content-disposition'));

        // ...y sin el parámetro la descarga clásica no cambia.
        $descarga = $this->actingAs($aspirante)
            ->get("/api/postulaciones/{$postulacion->id}/documentos/{$documento->id}/archivo")
            ->assertOk();
        $this->assertStringStartsWith('attachment;', (string) $descarga->headers->get('content-disposition'));
    }

    public function test_el_revisor_tambien_puede_abrir_el_archivo_en_linea(): void
    {
        [$aspirante, $postulacion] = $this->baseConDocumentos(['hoja_de_vida' => true]);

        $this->actingAs($aspirante)->withHeaders(['Accept' => 'application/json'])
            ->post("/api/postulaciones/{$postulacion->id}/documentos", [
                'codigo' => 'hoja_de_vida', 'archivo' => $this->pdf(),
            ])->assertCreated();

        $documento = PostulacionDocumento::sole();
        $instructor = $this->instructor($aspirante->ficha);

        $enLinea = $this->actingAs($instructor)
            ->get("/api/revision/postulaciones/{$postulacion->id}/documentos/{$documento->id}/archivo?inline=1")
            ->assertOk();

        $this->assertStringStartsWith('inline;', (string) $enLinea->headers->get('content-disposition'));
    }

    public function test_no_puede_descargar_de_una_postulacion_ajena(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspiranteA = $this->aspirante($ficha);
        $aspiranteB = $this->aspirante($ficha);
        $convocatoria = $this->conDocumentos($this->convocatoriaAbierta($ficha, $instructor), ['hoja_de_vida' => true]);
        $postulacionB = $this->crearPostulacion($convocatoria, $aspiranteB);

        $this->actingAs($aspiranteB)->withHeaders(['Accept' => 'application/json'])
            ->post("/api/postulaciones/{$postulacionB->id}/documentos", [
                'codigo' => 'hoja_de_vida', 'archivo' => $this->pdf(),
            ])->assertCreated();

        $documento = PostulacionDocumento::sole();

        $this->actingAs($aspiranteA)
            ->get("/api/postulaciones/{$postulacionB->id}/documentos/{$documento->id}/archivo")
            ->assertNotFound();

        $this->actingAs($aspiranteA)
            ->deleteJson("/api/postulaciones/{$postulacionB->id}/documentos/{$documento->id}")
            ->assertNotFound();
    }

    // ── Regla de la libreta militar por sexo ───────────────────────────

    public function test_libreta_no_aplica_a_mujeres_y_bloquea_su_carga(): void
    {
        [$aspirante, $postulacion] = $this->baseConDocumentos(['libreta_militar' => true]);
        $this->actingAs($aspirante)->putJson('/api/perfil/sexo', ['sexo' => 'mujer'])->assertOk();

        $respuesta = $this->actingAs($aspirante)
            ->getJson("/api/postulaciones/{$postulacion->id}/documentos")
            ->assertOk();

        // El sexo ya está registrado: no hay que volver a pedirlo, y la libreta
        // (único obligatorio) queda en no_aplica, así que el paso está completo.
        $respuesta->assertJsonPath('data.requiere_sexo', false);
        $respuesta->assertJsonPath('data.sexo.codigo', 'mujer');
        $respuesta->assertJsonPath('data.completo', true);

        $documentos = collect($respuesta->json('data.documentos'))
            ->keyBy(fn (array $d) => $d['parametro']['codigo']);
        $this->assertSame('no_aplica', $documentos['libreta_militar']['estado']);

        $this->actingAs($aspirante)->withHeaders(['Accept' => 'application/json'])
            ->post("/api/postulaciones/{$postulacion->id}/documentos", [
                'codigo' => 'libreta_militar', 'archivo' => $this->pdf(),
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.codigo.0', 'La libreta militar no aplica: tu perfil está registrado como femenino.');
    }

    public function test_libreta_exige_sexo_registrado(): void
    {
        [$aspirante, $postulacion] = $this->baseConDocumentos(['libreta_militar' => true]);

        $this->actingAs($aspirante)->withHeaders(['Accept' => 'application/json'])
            ->post("/api/postulaciones/{$postulacion->id}/documentos", [
                'codigo' => 'libreta_militar', 'archivo' => $this->pdf(),
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.codigo.0', 'Antes de subir la libreta militar debes indicar tu sexo.');
    }

    public function test_hombre_con_sexo_registrado_puede_subir_la_libreta(): void
    {
        [$aspirante, $postulacion] = $this->baseConDocumentos(['libreta_militar' => true]);
        $this->actingAs($aspirante)->putJson('/api/perfil/sexo', ['sexo' => 'hombre'])->assertOk();

        $this->actingAs($aspirante)->withHeaders(['Accept' => 'application/json'])
            ->post("/api/postulaciones/{$postulacion->id}/documentos", [
                'codigo' => 'libreta_militar', 'archivo' => $this->pdf('libreta.pdf'),
            ])
            ->assertCreated();
    }

    public function test_estado_sin_sexo_pide_sexo_y_deja_la_libreta_pendiente(): void
    {
        [$aspirante, $postulacion] = $this->baseConDocumentos(['libreta_militar' => true]);

        $respuesta = $this->actingAs($aspirante)
            ->getJson("/api/postulaciones/{$postulacion->id}/documentos")
            ->assertOk();

        $respuesta->assertJsonPath('data.requiere_sexo', true);
        $respuesta->assertJsonPath('data.sexo', null);
        $respuesta->assertJsonPath('data.completo', false);

        $documentos = collect($respuesta->json('data.documentos'))
            ->keyBy(fn (array $d) => $d['parametro']['codigo']);
        $this->assertSame('pendiente', $documentos['libreta_militar']['estado']);
    }

    public function test_cambiar_el_sexo_no_borra_la_libreta_y_la_deja_no_aplica(): void
    {
        [$aspirante, $postulacion] = $this->baseConDocumentos(['libreta_militar' => true]);
        $this->actingAs($aspirante)->putJson('/api/perfil/sexo', ['sexo' => 'hombre'])->assertOk();

        $this->actingAs($aspirante)->withHeaders(['Accept' => 'application/json'])
            ->post("/api/postulaciones/{$postulacion->id}/documentos", [
                'codigo' => 'libreta_militar', 'archivo' => $this->pdf('libreta.pdf'),
            ])->assertCreated();

        $documento = PostulacionDocumento::sole();
        Storage::disk('local')->assertExists($documento->ruta);

        // El aspirante corrige su sexo a mujer: la fila y su archivo se conservan,
        // pero la libreta pasa a no_aplica (ya no se exige).
        $this->actingAs($aspirante)->putJson('/api/perfil/sexo', ['sexo' => 'mujer'])->assertOk();

        $respuesta = $this->actingAs($aspirante)
            ->getJson("/api/postulaciones/{$postulacion->id}/documentos")
            ->assertOk();

        $respuesta->assertJsonPath('data.sexo.codigo', 'mujer');
        $respuesta->assertJsonPath('data.completo', true);

        $documentos = collect($respuesta->json('data.documentos'))
            ->keyBy(fn (array $d) => $d['parametro']['codigo']);
        $this->assertSame('no_aplica', $documentos['libreta_militar']['estado']);
        $this->assertNotNull($documentos['libreta_militar']['archivo']);

        $this->assertDatabaseCount('postulacion_documentos', 1);
        Storage::disk('local')->assertExists($documento->ruta);
    }

    // ── Completitud ────────────────────────────────────────────────────

    public function test_completo_cuando_todos_los_obligatorios_estan_subidos(): void
    {
        [$aspirante, $postulacion] = $this->baseConDocumentos([
            'hoja_de_vida' => true,
            'documento_identidad' => true,
            'tarjeta_profesional' => false,
        ]);
        $url = "/api/postulaciones/{$postulacion->id}/documentos";

        $this->actingAs($aspirante)->getJson($url)->assertJsonPath('data.completo', false);

        foreach (['hoja_de_vida', 'documento_identidad'] as $codigo) {
            $this->actingAs($aspirante)->withHeaders(['Accept' => 'application/json'])
                ->post($url, ['codigo' => $codigo, 'archivo' => $this->pdf("{$codigo}.pdf")])
                ->assertCreated();
        }

        // El opcional no obligatorio ('tarjeta_profesional') sigue pendiente y no
        // impide que quede completo.
        $this->actingAs($aspirante)->getJson($url)->assertJsonPath('data.completo', true);
    }

    public function test_descargar_un_archivo_ausente_devuelve_404(): void
    {
        [$aspirante, $postulacion] = $this->baseConDocumentos(['hoja_de_vida' => true]);

        $this->actingAs($aspirante)->withHeaders(['Accept' => 'application/json'])
            ->post("/api/postulaciones/{$postulacion->id}/documentos", [
                'codigo' => 'hoja_de_vida', 'archivo' => $this->pdf('hoja.pdf'),
            ])->assertCreated();

        $documento = PostulacionDocumento::sole();
        Storage::disk('local')->delete($documento->ruta);

        $this->actingAs($aspirante)
            ->getJson("/api/postulaciones/{$postulacion->id}/documentos/{$documento->id}/archivo")
            ->assertNotFound()
            ->assertJsonPath('message', 'Documento no encontrado.');
    }

    // ── Contrato para el frontend ──────────────────────────────────────

    public function test_estado_incluye_convocatoria_y_skills_de_la_convocatoria(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->conDocumentos(
            $this->convocatoriaAbierta($ficha, $instructor, ['nombre' => 'Analista de datos']),
            ['hoja_de_vida' => true],
        );
        $skillA = ConvocatoriaSkill::create(['convocatoria_id' => $convocatoria->id, 'nombre' => 'Excel avanzado']);
        $skillB = ConvocatoriaSkill::create(['convocatoria_id' => $convocatoria->id, 'nombre' => 'Power BI']);
        $postulacion = $this->crearPostulacion($convocatoria, $aspirante);

        $respuesta = $this->actingAs($aspirante)
            ->getJson("/api/postulaciones/{$postulacion->id}/documentos")
            ->assertOk();

        $respuesta->assertJsonPath('data.convocatoria.id', $convocatoria->id);
        $respuesta->assertJsonPath('data.convocatoria.nombre', 'Analista de datos');
        $respuesta->assertJsonPath('data.convocatoria.codigo', $convocatoria->codigo);

        // Los ids son los de convocatoria_skills: los mismos que acepta
        // convocatoria_skill_id en POST /skills.
        $respuesta->assertJsonPath('data.skills_convocatoria', [
            ['id' => $skillA->id, 'nombre' => 'Excel avanzado'],
            ['id' => $skillB->id, 'nombre' => 'Power BI'],
        ]);
    }

    public function test_estado_sin_skills_de_la_convocatoria_devuelve_lista_vacia(): void
    {
        [$aspirante, $postulacion] = $this->baseConDocumentos(['hoja_de_vida' => true]);

        $this->actingAs($aspirante)
            ->getJson("/api/postulaciones/{$postulacion->id}/documentos")
            ->assertOk()
            ->assertJsonPath('data.skills_convocatoria', []);
    }

    public function test_requiere_sexo_false_sin_libreta_aunque_no_haya_sexo(): void
    {
        // Convocatoria SIN libreta militar y sin sexo registrado: no se pide sexo.
        [$aspirante, $postulacion] = $this->baseConDocumentos(['hoja_de_vida' => true]);

        $this->assertDatabaseMissing('perfiles_aspirante', ['user_id' => $aspirante->id]);

        $this->actingAs($aspirante)
            ->getJson("/api/postulaciones/{$postulacion->id}/documentos")
            ->assertOk()
            ->assertJsonPath('data.requiere_sexo', false)
            ->assertJsonPath('data.sexo', null);
    }

    // ── Revisores (documentos:ver) ─────────────────────────────────────

    public function test_instructor_de_la_misma_ficha_puede_leer(): void
    {
        [$aspirante, $postulacion] = $this->baseConDocumentos(['hoja_de_vida' => true]);
        $instructor = $this->instructor($aspirante->ficha);

        $this->actingAs($instructor)
            ->getJson("/api/revision/postulaciones/{$postulacion->id}/documentos")
            ->assertOk()
            ->assertJsonPath('data.editable', false)
            ->assertJsonPath('data.postulacion.id', $postulacion->id);
    }

    public function test_evaluador_aprendiz_tambien_puede_leer(): void
    {
        [$aspirante, $postulacion] = $this->baseConDocumentos([]);
        $evaluador = $this->usuario(Role::Aprendiz, ['subrole' => 'evaluador', 'ficha_id' => $aspirante->ficha_id]);

        $this->actingAs($evaluador)
            ->getJson("/api/revision/postulaciones/{$postulacion->id}/documentos")
            ->assertOk();
    }

    public function test_instructor_de_otra_ficha_recibe_404(): void
    {
        [$aspirante, $postulacion] = $this->baseConDocumentos([]);
        $otraFicha = $this->ficha('9999999');
        $instructor = $this->instructor($otraFicha);

        $this->actingAs($instructor)
            ->getJson("/api/revision/postulaciones/{$postulacion->id}/documentos")
            ->assertNotFound();
    }

    public function test_aspirante_no_puede_usar_el_endpoint_de_revision(): void
    {
        [$aspirante, $postulacion] = $this->baseConDocumentos([]);

        // El aspirante no tiene 'documentos:ver': 403 en el middleware.
        $this->actingAs($aspirante)
            ->getJson("/api/revision/postulaciones/{$postulacion->id}/documentos")
            ->assertForbidden();
    }

    public function test_seleccionador_no_puede_usar_el_endpoint_de_revision(): void
    {
        [$aspirante, $postulacion] = $this->baseConDocumentos([]);
        $seleccionador = $this->usuario(Role::Aprendiz, ['subrole' => 'seleccionador', 'ficha_id' => $aspirante->ficha_id]);

        $this->actingAs($seleccionador)
            ->getJson("/api/revision/postulaciones/{$postulacion->id}/documentos")
            ->assertForbidden();
    }

    public function test_revision_incluye_convocatoria_y_skills(): void
    {
        [$aspirante, $postulacion] = $this->baseConDocumentos(['hoja_de_vida' => true]);
        $convocatoria = $postulacion->convocatoria;
        $skill = ConvocatoriaSkill::create(['convocatoria_id' => $convocatoria->id, 'nombre' => 'SAP HR']);

        $instructor = $this->instructor($aspirante->ficha);

        $respuesta = $this->actingAs($instructor)
            ->getJson("/api/revision/postulaciones/{$postulacion->id}/documentos")
            ->assertOk();

        $respuesta->assertJsonPath('data.convocatoria.id', $convocatoria->id);
        $respuesta->assertJsonPath('data.convocatoria.nombre', $convocatoria->nombre);
        $respuesta->assertJsonPath('data.convocatoria.codigo', $convocatoria->codigo);
        $respuesta->assertJsonPath('data.skills_convocatoria', [
            ['id' => $skill->id, 'nombre' => 'SAP HR'],
        ]);
    }

    public function test_evaluador_de_otra_ficha_recibe_404(): void
    {
        [$aspirante, $postulacion] = $this->baseConDocumentos([]);
        $otraFicha = $this->ficha('9999999');
        $evaluador = $this->usuario(Role::Aprendiz, ['subrole' => 'evaluador', 'ficha_id' => $otraFicha->id]);

        $this->assertNotContains($aspirante->ficha_id, $evaluador->fichaIds());

        $this->actingAs($evaluador)
            ->getJson("/api/revision/postulaciones/{$postulacion->id}/documentos")
            ->assertNotFound();
    }

    public function test_aspirante_con_ficha_id_no_entra_a_revision(): void
    {
        [$aspirante, $postulacion] = $this->baseConDocumentos([]);

        // Su ficha coincide con la de la postulación, pero no tiene
        // 'documentos:ver': el middleware responde 403 antes del alcance.
        $this->assertNotNull($aspirante->ficha_id);
        $this->assertContains($aspirante->ficha_id, $aspirante->fichaIds());

        $this->actingAs($aspirante)
            ->getJson("/api/revision/postulaciones/{$postulacion->id}/documentos")
            ->assertForbidden();
    }

    /**
     * Crea ficha + instructor + aspirante + convocatoria abierta con documentos
     * y una postulación, y devuelve [aspirante, postulacion].
     *
     * @param  array<string, bool>  $documentos
     * @return array{0: User, 1: Postulacion}
     */
    private function baseConDocumentos(array $documentos, array $postulacionOverrides = []): array
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->conDocumentos($this->convocatoriaAbierta($ficha, $instructor), $documentos);
        $postulacion = $this->crearPostulacion($convocatoria, $aspirante, $postulacionOverrides);

        return [$aspirante, $postulacion];
    }
}
