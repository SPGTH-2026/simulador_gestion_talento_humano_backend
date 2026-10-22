<?php

namespace App\Tests\Feature;

use App\Enums\Role;
use App\Models\Convocatoria;
use App\Models\ConvocatoriaSkill;
use App\Models\Ficha;
use App\Models\Parametro;
use App\Models\Postulacion;
use App\Models\PostulacionSkill;
use App\Models\User;
use Database\Seeders\ParametroSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Paso 4: skills declaradas en la postulación.
 *
 * REGLA VIGENTE: el aspirante SOLO puede marcar las skills que PIDE la
 * convocatoria (se envían con convocatoria_skill_id). Ni skills del catálogo
 * general ni nombres libres: ambos se rechazan con 422. Si la skill no es de
 * esta convocatoria -> 422.
 *
 * Cubre POST/DELETE de skills y el certificado OPCIONAL, el tope de 30, y las
 * mismas reglas de acceso que los documentos (dueño, ventana, revisores).
 *
 * Las filas ya declaradas antes del cambio (origen 'catalogo' o 'propia') NO
 * se borran: se conservan y se siguen leyendo. Eso cubren los tests que las
 * crean directamente en la base de datos.
 */
class PostulacionSkillTest extends TestCase
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
        return (int) Parametro::porTemaYCodigo($tema, $codigo)->id;
    }

    private function crearConvocatoria(Ficha $ficha, User $creador, array $overrides = []): Convocatoria
    {
        return Convocatoria::unguarded(fn () => Convocatoria::create($overrides + [
            'ficha_id' => $ficha->id,
            'codigo' => sprintf('CONV-SKL-%03d', ++self::$codigoSecuencia),
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

    private function crearPostulacion(Convocatoria $convocatoria, User $usuario, array $overrides = []): Postulacion
    {
        return Postulacion::create($overrides + [
            'convocatoria_id' => $convocatoria->id,
            'user_id' => $usuario->id,
            'estado_id' => $this->parametroId('estado_postulacion', 'postulada'),
            'fecha_postulacion' => now(),
        ]);
    }

    private function pdf(string $nombre = 'certificado.pdf'): UploadedFile
    {
        return UploadedFile::fake()->create($nombre, 100, 'application/pdf');
    }

    /** Skill que PIDE la convocatoria: es la única que el aspirante puede marcar. */
    private function skillDeConvocatoria(Convocatoria $convocatoria, string $nombre = 'Excel avanzado'): ConvocatoriaSkill
    {
        return ConvocatoriaSkill::create([
            'convocatoria_id' => $convocatoria->id,
            'nombre' => $nombre,
        ]);
    }

    /** @return array{0: User, 1: Postulacion, 2: Convocatoria} */
    private function base(): array
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->convocatoriaAbierta($ficha, $instructor);
        $postulacion = $this->crearPostulacion($convocatoria, $aspirante);

        return [$aspirante, $postulacion, $convocatoria];
    }

    // ── Declarar skills ────────────────────────────────────────────────

    public function test_declara_una_skill_propia_devuelve_422(): void
    {
        // Antes se podía declarar una skill libre; ahora solo las de la
        // convocatoria: un nombre propio se rechaza.
        [$aspirante, $postulacion] = $this->base();

        $this->actingAs($aspirante)
            ->postJson("/api/postulaciones/{$postulacion->id}/skills", ['nombre' => '  Liderazgo  '])
            ->assertStatus(422)
            ->assertJsonPath('errors.convocatoria_skill_id.0', 'Solo puedes marcar las skills que pide la convocatoria.');

        $this->assertDatabaseCount('postulacion_skills', 0);
    }

    public function test_declara_una_skill_de_la_convocatoria_devuelve_201(): void
    {
        [$aspirante, $postulacion, $convocatoria] = $this->base();
        $skill = $this->skillDeConvocatoria($convocatoria, 'Excel avanzado');

        $this->actingAs($aspirante)
            ->postJson("/api/postulaciones/{$postulacion->id}/skills", ['convocatoria_skill_id' => $skill->id])
            ->assertCreated()
            ->assertJsonPath('data.nombre', 'Excel avanzado')
            ->assertJsonPath('data.origen', 'convocatoria');

        $this->assertDatabaseHas('postulacion_skills', [
            'postulacion_id' => $postulacion->id,
            'convocatoria_skill_id' => $skill->id,
            'nombre' => 'Excel avanzado',
        ]);
    }

    public function test_skill_repetida_es_idempotente(): void
    {
        [$aspirante, $postulacion, $convocatoria] = $this->base();
        $skill = $this->skillDeConvocatoria($convocatoria, 'Excel');
        $url = "/api/postulaciones/{$postulacion->id}/skills";

        $this->actingAs($aspirante)->postJson($url, ['convocatoria_skill_id' => $skill->id])->assertCreated();
        $this->actingAs($aspirante)->postJson($url, ['convocatoria_skill_id' => $skill->id])->assertOk();

        $this->assertSame(1, PostulacionSkill::count());
    }

    public function test_skill_de_otra_convocatoria_devuelve_422(): void
    {
        [$aspirante, $postulacion, $convocatoria] = $this->base();
        $otra = $this->convocatoriaAbierta($convocatoria->ficha, $this->instructor($convocatoria->ficha));
        $ajena = ConvocatoriaSkill::create(['convocatoria_id' => $otra->id, 'nombre' => 'Power BI']);

        $this->actingAs($aspirante)
            ->postJson("/api/postulaciones/{$postulacion->id}/skills", ['convocatoria_skill_id' => $ajena->id])
            ->assertStatus(422)
            ->assertJsonPath('errors.convocatoria_skill_id.0', 'Esa skill no hace parte de la convocatoria.');

        $this->assertDatabaseCount('postulacion_skills', 0);
    }

    public function test_skill_sin_nombre_ni_id_devuelve_422(): void
    {
        [$aspirante, $postulacion] = $this->base();

        $this->actingAs($aspirante)
            ->postJson("/api/postulaciones/{$postulacion->id}/skills", [])
            ->assertStatus(422)
            ->assertJsonPath('errors.convocatoria_skill_id.0', 'Debes marcar una de las skills que pide la convocatoria.');
    }

    public function test_nombre_libre_se_rechaza_por_mas_de_100_caracteres(): void
    {
        // El límite de 100 caracteres se aplica al nombre de la skill de la
        // CONVOCATORIA (ver ConvocatoriaGestionTest); aquí se comprueba que un
        // nombre libre (del POST) no se acepta ni siquiera siendo larguísimo.
        [$aspirante, $postulacion] = $this->base();

        $this->actingAs($aspirante)
            ->postJson("/api/postulaciones/{$postulacion->id}/skills", ['nombre' => str_repeat('a', 101)])
            ->assertStatus(422)
            ->assertJsonPath('errors.convocatoria_skill_id.0', 'Solo puedes marcar las skills que pide la convocatoria.');
    }

    public function test_tope_de_30_skills_devuelve_422(): void
    {
        [$aspirante, $postulacion, $convocatoria] = $this->base();
        $url = "/api/postulaciones/{$postulacion->id}/skills";

        for ($i = 1; $i <= 30; $i++) {
            $skill = $this->skillDeConvocatoria($convocatoria, "Skill {$i}");
            $this->actingAs($aspirante)->postJson($url, ['convocatoria_skill_id' => $skill->id])->assertCreated();
        }

        $extra = $this->skillDeConvocatoria($convocatoria, 'Skill 31');

        $this->actingAs($aspirante)
            ->postJson($url, ['convocatoria_skill_id' => $extra->id])
            ->assertStatus(422)
            ->assertJsonPath('errors.convocatoria_skill_id.0', 'No puedes registrar más de 30 skills en tu postulación.');

        $this->assertSame(30, PostulacionSkill::count());
    }

    // ── Las formas antiguas (catálogo y nombre propio) se rechazan ──────

    public function test_declara_una_skill_del_catalogo_devuelve_422(): void
    {
        [$aspirante, $postulacion] = $this->base();
        $parametroId = $this->parametroId('skill_catalogo', 'excel');

        $this->actingAs($aspirante)
            ->postJson("/api/postulaciones/{$postulacion->id}/skills", ['parametro_id' => $parametroId])
            ->assertStatus(422)
            ->assertJsonPath('errors.convocatoria_skill_id.0', 'Solo puedes marcar las skills que pide la convocatoria.');

        $this->assertDatabaseCount('postulacion_skills', 0);
    }

    public function test_parametro_de_otro_tema_devuelve_422(): void
    {
        [$aspirante, $postulacion] = $this->base();
        $ajeno = $this->parametroId('sexo', 'hombre');

        $this->actingAs($aspirante)
            ->postJson("/api/postulaciones/{$postulacion->id}/skills", ['parametro_id' => $ajeno])
            ->assertStatus(422)
            ->assertJsonPath('errors.convocatoria_skill_id.0', 'Solo puedes marcar las skills que pide la convocatoria.');

        $this->assertDatabaseCount('postulacion_skills', 0);
    }

    public function test_parametro_inexistente_devuelve_422(): void
    {
        [$aspirante, $postulacion] = $this->base();

        $this->actingAs($aspirante)
            ->postJson("/api/postulaciones/{$postulacion->id}/skills", ['parametro_id' => 999999])
            ->assertStatus(422)
            ->assertJsonPath('errors.convocatoria_skill_id.0', 'Solo puedes marcar las skills que pide la convocatoria.');
    }

    public function test_enviar_dos_formas_devuelve_422(): void
    {
        [$aspirante, $postulacion, $convocatoria] = $this->base();
        $skill = $this->skillDeConvocatoria($convocatoria, 'Excel avanzado');
        $url = "/api/postulaciones/{$postulacion->id}/skills";

        // convocatoria_skill_id + nombre.
        $this->actingAs($aspirante)
            ->postJson($url, ['convocatoria_skill_id' => $skill->id, 'nombre' => 'Otra'])
            ->assertStatus(422)
            ->assertJsonPath('errors.convocatoria_skill_id.0', 'Solo puedes marcar las skills que pide la convocatoria.');

        // parametro_id + nombre.
        $this->actingAs($aspirante)
            ->postJson($url, ['parametro_id' => $this->parametroId('skill_catalogo', 'excel'), 'nombre' => 'Otra'])
            ->assertStatus(422)
            ->assertJsonPath('errors.convocatoria_skill_id.0', 'Solo puedes marcar las skills que pide la convocatoria.');

        // convocatoria_skill_id + parametro_id.
        $this->actingAs($aspirante)
            ->postJson($url, ['convocatoria_skill_id' => $skill->id, 'parametro_id' => $this->parametroId('skill_catalogo', 'excel')])
            ->assertStatus(422)
            ->assertJsonPath('errors.convocatoria_skill_id.0', 'Solo puedes marcar las skills que pide la convocatoria.');

        $this->assertDatabaseCount('postulacion_skills', 0);
    }

    public function test_skill_del_catalogo_repetida_por_nombre_devuelve_422(): void
    {
        [$aspirante, $postulacion] = $this->base();
        $url = "/api/postulaciones/{$postulacion->id}/skills";

        $this->actingAs($aspirante)
            ->postJson($url, ['parametro_id' => $this->parametroId('skill_catalogo', 'excel')])
            ->assertStatus(422);

        // La misma skill, ahora como texto libre: también se rechaza.
        $this->actingAs($aspirante)
            ->postJson($url, ['nombre' => 'excel'])
            ->assertStatus(422);

        $this->assertSame(0, PostulacionSkill::count());
    }

    public function test_skill_de_convocatoria_repetida_por_catalogo_devuelve_422(): void
    {
        [$aspirante, $postulacion, $convocatoria] = $this->base();
        $skill = $this->skillDeConvocatoria($convocatoria, 'Excel');
        $url = "/api/postulaciones/{$postulacion->id}/skills";

        $this->actingAs($aspirante)
            ->postJson($url, ['convocatoria_skill_id' => $skill->id])
            ->assertCreated()
            ->assertJsonPath('data.origen', 'convocatoria');

        // El catálogo tiene "Excel" (mismo nombre): ya no se puede declarar.
        $this->actingAs($aspirante)
            ->postJson($url, ['parametro_id' => $this->parametroId('skill_catalogo', 'excel')])
            ->assertStatus(422);

        $this->assertSame(1, PostulacionSkill::count());
    }

    public function test_certificado_opcional_sobre_una_skill_de_la_convocatoria(): void
    {
        [$aspirante, $postulacion, $convocatoria] = $this->base();
        $skill = $this->skillDeConvocatoria($convocatoria, 'Word');

        $this->actingAs($aspirante)
            ->postJson("/api/postulaciones/{$postulacion->id}/skills", ['convocatoria_skill_id' => $skill->id])
            ->assertCreated();

        $declarada = PostulacionSkill::sole();

        $this->actingAs($aspirante)->withHeaders(['Accept' => 'application/json'])
            ->post("/api/postulaciones/{$postulacion->id}/skills/{$declarada->id}/certificado", [
                'archivo' => $this->pdf('word.pdf'),
            ])
            ->assertOk()
            ->assertJsonPath('data.origen', 'convocatoria')
            ->assertJsonPath('data.certificado.nombre_original', 'word.pdf');
    }

    public function test_revision_muestra_el_origen_de_cada_skill(): void
    {
        [$aspirante, $postulacion, $convocatoria] = $this->base();
        $convocatoriaSkill = $this->skillDeConvocatoria($convocatoria, 'Power BI');
        $url = "/api/postulaciones/{$postulacion->id}/skills";

        $this->actingAs($aspirante)
            ->postJson($url, ['convocatoria_skill_id' => $convocatoriaSkill->id])
            ->assertCreated();

        // Las filas VIEJAS (las de antes del cambio) NO se borran: se conservan
        // con su origen y el revisor las sigue viendo.
        PostulacionSkill::create([
            'postulacion_id' => $postulacion->id,
            'parametro_id' => $this->parametroId('skill_catalogo', 'excel'),
            'nombre' => 'Excel',
        ]);
        PostulacionSkill::create([
            'postulacion_id' => $postulacion->id,
            'nombre' => 'Pintura',
        ]);

        $instructor = $this->instructor($aspirante->ficha);

        $skills = collect(
            $this->actingAs($instructor)
                ->getJson("/api/revision/postulaciones/{$postulacion->id}/documentos")
                ->assertOk()
                ->json('data.skills')
        )->keyBy('nombre');

        $this->assertSame('convocatoria', $skills['Power BI']['origen']);
        $this->assertSame('catalogo', $skills['Excel']['origen']);
        $this->assertSame('propia', $skills['Pintura']['origen']);

        // El dueño ve el mismo origen.
        $mias = collect(
            $this->actingAs($aspirante)
                ->getJson("/api/postulaciones/{$postulacion->id}/documentos")
                ->assertOk()
                ->json('data.skills')
        )->keyBy('nombre');

        $this->assertSame('catalogo', $mias['Excel']['origen']);
    }

    // ── Certificado opcional ───────────────────────────────────────────

    public function test_sube_y_quita_el_certificado_de_una_skill(): void
    {
        [$aspirante, $postulacion, $convocatoria] = $this->base();
        $convocatoriaSkill = $this->skillDeConvocatoria($convocatoria, 'Excel');

        $this->actingAs($aspirante)
            ->postJson("/api/postulaciones/{$postulacion->id}/skills", ['convocatoria_skill_id' => $convocatoriaSkill->id])
            ->assertCreated();

        $skill = PostulacionSkill::sole();
        $url = "/api/postulaciones/{$postulacion->id}/skills/{$skill->id}/certificado";

        $this->actingAs($aspirante)->withHeaders(['Accept' => 'application/json'])
            ->post($url, ['archivo' => $this->pdf('excel.pdf')])
            ->assertOk()
            ->assertJsonPath('data.certificado.nombre_original', 'excel.pdf');

        $ruta = PostulacionSkill::sole()->certificado_ruta;
        Storage::disk('local')->assertExists($ruta);

        $this->actingAs($aspirante)->deleteJson($url)->assertNoContent();

        Storage::disk('local')->assertMissing($ruta);
        $this->assertNull(PostulacionSkill::sole()->certificado_ruta);
    }

    public function test_borrar_una_skill_borra_su_certificado(): void
    {
        [$aspirante, $postulacion, $convocatoria] = $this->base();
        $convocatoriaSkill = $this->skillDeConvocatoria($convocatoria, 'Excel');

        $this->actingAs($aspirante)
            ->postJson("/api/postulaciones/{$postulacion->id}/skills", ['convocatoria_skill_id' => $convocatoriaSkill->id])
            ->assertCreated();
        $skill = PostulacionSkill::sole();

        $this->actingAs($aspirante)->withHeaders(['Accept' => 'application/json'])
            ->post("/api/postulaciones/{$postulacion->id}/skills/{$skill->id}/certificado", [
                'archivo' => $this->pdf(),
            ])->assertOk();

        $ruta = PostulacionSkill::sole()->certificado_ruta;

        $this->actingAs($aspirante)
            ->deleteJson("/api/postulaciones/{$postulacion->id}/skills/{$skill->id}")
            ->assertNoContent();

        Storage::disk('local')->assertMissing($ruta);
        $this->assertDatabaseCount('postulacion_skills', 0);
    }

    public function test_certificado_no_pdf_devuelve_422(): void
    {
        [$aspirante, $postulacion, $convocatoria] = $this->base();
        $convocatoriaSkill = $this->skillDeConvocatoria($convocatoria, 'Excel');

        $this->actingAs($aspirante)
            ->postJson("/api/postulaciones/{$postulacion->id}/skills", ['convocatoria_skill_id' => $convocatoriaSkill->id])
            ->assertCreated();
        $skill = PostulacionSkill::sole();

        $this->actingAs($aspirante)->withHeaders(['Accept' => 'application/json'])
            ->post("/api/postulaciones/{$postulacion->id}/skills/{$skill->id}/certificado", [
                'archivo' => UploadedFile::fake()->create('notas.txt', 10, 'text/plain'),
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.archivo.0', 'El archivo debe ser un PDF.');
    }

    // ── Acceso ─────────────────────────────────────────────────────────

    public function test_no_se_puede_declarar_skills_en_una_postulacion_ajena(): void
    {
        [$aspirante, $postulacion, $convocatoria] = $this->base();
        $skill = $this->skillDeConvocatoria($convocatoria);
        $otro = $this->aspirante($postulacion->convocatoria->ficha);

        $this->actingAs($otro)
            ->postJson("/api/postulaciones/{$postulacion->id}/skills", ['convocatoria_skill_id' => $skill->id])
            ->assertNotFound()
            ->assertJsonPath('message', 'Postulación no encontrada.');
    }

    public function test_no_se_puede_declarar_skills_en_postulacion_retirada(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->convocatoriaAbierta($ficha, $instructor);
        $postulacion = $this->crearPostulacion($convocatoria, $aspirante, [
            'estado_id' => $this->parametroId('estado_postulacion', 'retirada'),
            'fecha_retiro' => now(),
        ]);
        $skill = $this->skillDeConvocatoria($convocatoria);

        $this->actingAs($aspirante)
            ->postJson("/api/postulaciones/{$postulacion->id}/skills", ['convocatoria_skill_id' => $skill->id])
            ->assertStatus(422)
            ->assertJsonPath('errors.skills.0', 'Tu postulación está retirada. Reactívala para editar documentos.');
    }

    public function test_revisor_puede_descargar_el_certificado(): void
    {
        [$aspirante, $postulacion, $convocatoria] = $this->base();
        $convocatoriaSkill = $this->skillDeConvocatoria($convocatoria, 'Excel');

        $this->actingAs($aspirante)
            ->postJson("/api/postulaciones/{$postulacion->id}/skills", ['convocatoria_skill_id' => $convocatoriaSkill->id])
            ->assertCreated();
        $skill = PostulacionSkill::sole();

        $this->actingAs($aspirante)->withHeaders(['Accept' => 'application/json'])
            ->post("/api/postulaciones/{$postulacion->id}/skills/{$skill->id}/certificado", [
                'archivo' => $this->pdf('excel.pdf'),
            ])->assertOk();

        $instructor = $this->instructor($aspirante->ficha);

        $this->actingAs($instructor)
            ->get("/api/revision/postulaciones/{$postulacion->id}/skills/{$skill->id}/certificado/archivo")
            ->assertOk();

        // Un instructor de otra ficha no puede: 404.
        $otraFicha = $this->ficha('9999999');
        $ajeno = $this->instructor($otraFicha);

        $this->actingAs($ajeno)
            ->get("/api/revision/postulaciones/{$postulacion->id}/skills/{$skill->id}/certificado/archivo")
            ->assertNotFound();
    }

    // ── Visor PDF (?inline=1) ─────────────────────────────────────────

    public function test_el_certificado_lo_pide_en_linea_el_dueno_y_sigue_descargandose_sin_inline(): void
    {
        [$aspirante, $postulacion, $convocatoria] = $this->base();
        $convocatoriaSkill = $this->skillDeConvocatoria($convocatoria, 'Excel');
        $this->actingAs($aspirante)
            ->postJson("/api/postulaciones/{$postulacion->id}/skills", ['convocatoria_skill_id' => $convocatoriaSkill->id])
            ->assertCreated();
        $skill = PostulacionSkill::sole();

        $this->actingAs($aspirante)->withHeaders(['Accept' => 'application/json'])
            ->post("/api/postulaciones/{$postulacion->id}/skills/{$skill->id}/certificado", [
                'archivo' => $this->pdf('excel.pdf'),
            ])->assertOk();

        $enLinea = $this->actingAs($aspirante)
            ->get("/api/postulaciones/{$postulacion->id}/skills/{$skill->id}/certificado/archivo?inline=1")
            ->assertOk();
        $this->assertStringStartsWith('inline;', (string) $enLinea->headers->get('content-disposition'));

        $descarga = $this->actingAs($aspirante)
            ->get("/api/postulaciones/{$postulacion->id}/skills/{$skill->id}/certificado/archivo")
            ->assertOk();
        $this->assertStringStartsWith('attachment;', (string) $descarga->headers->get('content-disposition'));
    }

    public function test_el_revisor_tambien_puede_abrir_el_certificado_en_linea(): void
    {
        [$aspirante, $postulacion, $convocatoria] = $this->base();
        $convocatoriaSkill = $this->skillDeConvocatoria($convocatoria, 'Excel');
        $this->actingAs($aspirante)
            ->postJson("/api/postulaciones/{$postulacion->id}/skills", ['convocatoria_skill_id' => $convocatoriaSkill->id])
            ->assertCreated();
        $skill = PostulacionSkill::sole();

        $this->actingAs($aspirante)->withHeaders(['Accept' => 'application/json'])
            ->post("/api/postulaciones/{$postulacion->id}/skills/{$skill->id}/certificado", [
                'archivo' => $this->pdf('excel.pdf'),
            ])->assertOk();

        $instructor = $this->instructor($aspirante->ficha);

        $enLinea = $this->actingAs($instructor)
            ->get("/api/revision/postulaciones/{$postulacion->id}/skills/{$skill->id}/certificado/archivo?inline=1")
            ->assertOk();

        $this->assertStringStartsWith('inline;', (string) $enLinea->headers->get('content-disposition'));
    }

    public function test_descargar_un_certificado_ausente_devuelve_404(): void
    {
        [$aspirante, $postulacion, $convocatoria] = $this->base();
        $convocatoriaSkill = $this->skillDeConvocatoria($convocatoria, 'Excel');

        $this->actingAs($aspirante)
            ->postJson("/api/postulaciones/{$postulacion->id}/skills", ['convocatoria_skill_id' => $convocatoriaSkill->id])
            ->assertCreated();
        $skill = PostulacionSkill::sole();

        $this->actingAs($aspirante)->withHeaders(['Accept' => 'application/json'])
            ->post("/api/postulaciones/{$postulacion->id}/skills/{$skill->id}/certificado", [
                'archivo' => $this->pdf('excel.pdf'),
            ])->assertOk();

        Storage::disk('local')->delete(PostulacionSkill::sole()->certificado_ruta);

        $this->actingAs($aspirante)
            ->getJson("/api/postulaciones/{$postulacion->id}/skills/{$skill->id}/certificado/archivo")
            ->assertNotFound()
            ->assertJsonPath('message', 'Certificado no encontrado.');
    }

    public function test_borrar_la_skill_de_la_convocatoria_conserva_la_declarada(): void
    {
        [$aspirante, $postulacion, $convocatoria] = $this->base();
        $convocatoriaSkill = $this->skillDeConvocatoria($convocatoria, 'Excel avanzado');

        $this->actingAs($aspirante)
            ->postJson("/api/postulaciones/{$postulacion->id}/skills", [
                'convocatoria_skill_id' => $convocatoriaSkill->id,
            ])
            ->assertCreated();

        $skill = PostulacionSkill::sole();

        // La convocatoria borra su skill: la fila de la postulación se CONSERVA
        // (SET NULL) y mantiene el nombre ya copiado.
        $convocatoriaSkill->delete();

        $this->assertDatabaseHas('postulacion_skills', [
            'id' => $skill->id,
            'convocatoria_skill_id' => null,
            'nombre' => 'Excel avanzado',
        ]);
    }

    public function test_una_fila_heredada_sigue_legible_y_su_certificado_lo_abren_dueno_y_revisor(): void
    {
        [$aspirante, $postulacion] = $this->base();

        $delCatalogo = PostulacionSkill::create([
            'postulacion_id' => $postulacion->id,
            'convocatoria_skill_id' => null,
            'parametro_id' => $this->parametroId('skill_catalogo', 'excel'),
            'nombre' => 'Excel',
        ]);
        $propia = PostulacionSkill::create([
            'postulacion_id' => $postulacion->id,
            'convocatoria_skill_id' => null,
            'parametro_id' => null,
            'nombre' => 'Liderazgo previo',
        ]);

        $this->actingAs($aspirante)
            ->getJson("/api/postulaciones/{$postulacion->id}/documentos")
            ->assertOk()
            ->assertJsonFragment(['nombre' => 'Excel'])
            ->assertJsonFragment(['nombre' => 'Liderazgo previo']);

        $instructor = $this->instructor($aspirante->ficha);

        foreach ([$delCatalogo, $propia] as $skill) {
            $this->actingAs($aspirante)->withHeaders(['Accept' => 'application/json'])
                ->post("/api/postulaciones/{$postulacion->id}/skills/{$skill->id}/certificado", [
                    'archivo' => $this->pdf('heredada.pdf'),
                ])->assertOk();

            $this->actingAs($aspirante)
                ->get("/api/postulaciones/{$postulacion->id}/skills/{$skill->id}/certificado/archivo?inline=1")
                ->assertOk();

            $this->actingAs($instructor)
                ->get("/api/revision/postulaciones/{$postulacion->id}/skills/{$skill->id}/certificado/archivo?inline=1")
                ->assertOk();
        }
    }
}
