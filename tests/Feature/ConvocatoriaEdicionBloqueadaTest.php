<?php

namespace App\Tests\Feature;

use App\Enums\Role;
use App\Models\Convocatoria;
use App\Models\Ficha;
use App\Models\Parametro;
use App\Models\ParametroTema;
use App\Models\Postulacion;
use App\Models\User;
use Database\Seeders\ParametroSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Paso 5 (regla H): con AL MENOS UNA postulación y la convocatoria publicada,
 * ciertos campos quedan bloqueados salvo que el valor enviado sea idéntico.
 *
 * Bloqueados: cargo, area, vacantes, salario_min, salario_max, tipo_convocatoria,
 * tipo_empleo, tipo_contrato, modalidad, fecha_apertura, skills y documentos.
 * Siempre editables: nombre, textos descriptivos y fecha_evaluacion.
 * fecha_cierre: solo se puede ampliar (reducir da 422).
 *
 * Las comparaciones no se hacen por texto "tal cual": salarios por número, fechas
 * por día (America/Bogota), clasificaciones por id resuelto, skills por conjunto
 * (sin orden ni mayúsculas) y documentos por conjunto de ids.
 */
class ConvocatoriaEdicionBloqueadaTest extends TestCase
{
    use RefreshDatabase;

    private static int $codigoSecuencia = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ParametroSeeder::class);
    }

    // ── Helpers ────────────────────────────────────────────────────────

    private function ficha(string $codigo = '3173334'): Ficha
    {
        return Ficha::create([
            'codigo' => $codigo,
            'nombre_programa' => 'Análisis y Desarrollo de Software',
        ]);
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

    private function parametroId(string $tema, string $codigo): int
    {
        return (int) Parametro::porTemaYCodigo($tema, $codigo)->id;
    }

    /**
     * Documento EXTRA del catálogo: parámetro ACTIVO creado dentro de la prueba.
     * No está en los obligatorios ni en los opcionales del gestor, así que se
     * guarda con obligatorio = false.
     */
    private function documentoExtra(string $codigo = 'documento_adicional'): Parametro
    {
        $tema = ParametroTema::where('codigo', 'tipo_documento')->firstOrFail();

        return Parametro::create([
            'tema_id' => $tema->id,
            'codigo' => $codigo,
            'nombre' => 'Documento adicional',
            'orden' => 99,
            'activo' => true,
        ]);
    }

    private function crearConvocatoria(Ficha $ficha, User $creador, array $overrides = []): Convocatoria
    {
        return Convocatoria::unguarded(fn () => Convocatoria::create($overrides + [
            'ficha_id' => $ficha->id,
            'codigo' => sprintf('CONV-TEST-%03d', ++self::$codigoSecuencia),
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

    /** Publicada con la ventana abierta y todos los campos poblados. */
    private function publicable(Ficha $ficha, User $creador, array $overrides = []): Convocatoria
    {
        return $this->crearConvocatoria($ficha, $creador, $overrides + [
            'nombre' => 'Instructor de software',
            'cargo' => 'Instructor',
            'area' => 'Sistemas',
            'descripcion' => 'Descripción inicial',
            'vacantes' => 2,
            'salario_min' => 3500000,
            'salario_max' => 4200000,
            'funciones' => 'Funciones iniciales',
            'responsabilidades' => 'Responsabilidades iniciales',
            'perfil_requerido' => 'Perfil inicial',
            'formacion' => 'Ingeniería de sistemas',
            'experiencia' => '2 años',
            'competencias' => 'Trabajo en equipo',
            'conocimientos_especificos' => 'Laravel',
            'estado_id' => $this->parametroId('estado_convocatoria', 'publicada'),
            'fecha_apertura' => now('America/Bogota')->subDay()->toDateString(),
            'fecha_cierre' => now('America/Bogota')->addDays(10)->toDateString(),
            'fecha_evaluacion' => now('America/Bogota')->addDays(20)->toDateString(),
        ]);
    }

    private function conSkills(Convocatoria $convocatoria, array $skills): Convocatoria
    {
        foreach ($skills as $skill) {
            $convocatoria->skills()->create(['nombre' => $skill]);
        }

        return $convocatoria;
    }

    /** $documentos = ['codigo' => bool obligatorio, ...]. */
    private function conDocumentos(Convocatoria $convocatoria, array $documentos): Convocatoria
    {
        $sync = [];
        foreach ($documentos as $codigo => $obligatorio) {
            $sync[$this->parametroId('tipo_documento', $codigo)] = ['obligatorio' => $obligatorio];
        }

        $convocatoria->documentos()->sync($sync);

        return $convocatoria;
    }

    private function postular(Convocatoria $convocatoria, User $aspirante): Postulacion
    {
        return Postulacion::unguarded(fn () => Postulacion::create([
            'convocatoria_id' => $convocatoria->id,
            'user_id' => $aspirante->id,
            'estado_id' => $this->parametroId('estado_postulacion', 'postulada'),
            'fecha_postulacion' => now(),
        ]));
    }

    private function retirar(Convocatoria $convocatoria, User $aspirante): Postulacion
    {
        return Postulacion::unguarded(fn () => Postulacion::create([
            'convocatoria_id' => $convocatoria->id,
            'user_id' => $aspirante->id,
            'estado_id' => $this->parametroId('estado_postulacion', 'retirada'),
            'fecha_postulacion' => now()->subDay(),
            'fecha_retiro' => now(),
        ]));
    }

    private function borrador(Convocatoria $convocatoria, User $aspirante): Postulacion
    {
        return Postulacion::unguarded(fn () => Postulacion::create([
            'convocatoria_id' => $convocatoria->id,
            'user_id' => $aspirante->id,
            'estado_id' => $this->parametroId('estado_postulacion', 'borrador'),
            'fecha_postulacion' => now(),
        ]));
    }

    /** Mensaje exacto de campo bloqueado. */
    private function msg(string $campo): string
    {
        return "No se puede modificar {$campo} porque la convocatoria ya tiene postulaciones.";
    }

    /** Cuerpo completo a partir de los valores guardados (wizard reenvía todo). */
    private function payloadCompleto(Convocatoria $c): array
    {
        $c = $c->fresh();

        return [
            'nombre' => $c->nombre,
            'cargo' => $c->cargo,
            'area' => $c->area,
            'descripcion' => $c->descripcion,
            'vacantes' => (int) $c->vacantes,
            'salario_min' => (float) $c->salario_min,
            'salario_max' => (float) $c->salario_max,
            'funciones' => $c->funciones,
            'responsabilidades' => $c->responsabilidades,
            'perfil_requerido' => $c->perfil_requerido,
            'formacion' => $c->formacion,
            'experiencia' => $c->experiencia,
            'competencias' => $c->competencias,
            'conocimientos_especificos' => $c->conocimientos_especificos,
            'tipo_convocatoria' => $c->tipoConvocatoria->codigo,
            'tipo_empleo' => $c->tipoEmpleo->codigo,
            'tipo_contrato' => $c->tipoContrato->codigo,
            'modalidad' => $c->modalidad->codigo,
            'fecha_apertura' => $c->fecha_apertura->toDateString(),
            'fecha_cierre' => $c->fecha_cierre->toDateString(),
            'fecha_evaluacion' => $c->fecha_evaluacion->toDateString(),
            'skills' => $c->skills->pluck('nombre')->all(),
            'documentos' => $c->documentos->map(fn ($d) => [
                'codigo' => $d->codigo,
                'obligatorio' => (bool) $d->pivot->obligatorio,
            ])->values()->all(),
        ];
    }

    // ── La regla solo aplica a publicada ---------------------------------

    public function test_publicada_sin_postulaciones_permite_cambiar_campos_bloqueados(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->conDocumentos($this->publicable($ficha, $instructor), ['hoja_de_vida' => true]);

        $this->actingAs($instructor)->patchJson('/api/convocatorias/'.$convocatoria->id, [
            'cargo' => 'Coordinador',
            'area' => 'Administración',
            'vacantes' => 5,
            'salario_min' => 5000000,
            'salario_max' => 6000000,
            'tipo_convocatoria' => 'interno',
            'tipo_empleo' => 'medio_tiempo',
            'tipo_contrato' => 'termino_indefinido',
            'modalidad' => 'remoto',
            'fecha_apertura' => now('America/Bogota')->toDateString(),
            'skills' => ['Nueva'],
            'documentos' => [['codigo' => 'libreta_militar', 'obligatorio' => false]],
        ])->assertOk();

        $fresca = $convocatoria->fresh();
        $this->assertSame('Coordinador', $fresca->cargo);
        $this->assertSame(5, $fresca->vacantes);
        $this->assertSame('interno', $fresca->tipoConvocatoria->codigo);
        $this->assertSame('remoto', $fresca->modalidad->codigo);
        $this->assertSame(['Nueva'], $fresca->skills->pluck('nombre')->all());
        $libreta = $fresca->documentos->firstWhere('codigo', 'libreta_militar');
        $this->assertNotNull($libreta);
        // libreta_militar es opcional del gestor: al incluirla queda obligatoria.
        $this->assertTrue((bool) $libreta->pivot->obligatorio);
    }

    public function test_borrador_permite_cambiar_campos_bloqueados(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->crearConvocatoria($ficha, $instructor, ['cargo' => 'Instructor', 'vacantes' => 1]);

        $this->actingAs($instructor)
            ->patchJson('/api/convocatorias/'.$convocatoria->id, ['cargo' => 'Coordinador', 'vacantes' => 3])
            ->assertOk();

        $this->assertSame('Coordinador', $convocatoria->fresh()->cargo);
    }

    // ── regla H: el borrador NO cuenta como postulación ────────────────

    public function test_solo_borradores_no_bloquean_los_campos(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->publicable($ficha, $instructor);
        $this->borrador($convocatoria, $this->aspirante($ficha));

        $this->actingAs($instructor)
            ->patchJson('/api/convocatorias/'.$convocatoria->id, ['cargo' => 'Coordinador', 'vacantes' => 5])
            ->assertOk();

        $this->assertSame('Coordinador', $convocatoria->fresh()->cargo);
        $this->assertSame(5, $convocatoria->fresh()->vacantes);
    }

    public function test_un_borrador_junto_a_una_postulada_si_bloquea(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->publicable($ficha, $instructor);
        $this->borrador($convocatoria, $this->aspirante($ficha));
        $this->postular($convocatoria, $this->aspirante($ficha));

        $respuesta = $this->actingAs($instructor)
            ->patchJson('/api/convocatorias/'.$convocatoria->id, ['cargo' => 'Otro'])
            ->assertStatus(422);

        $this->assertSame([$this->msg('cargo')], $respuesta->json('errors.cargo'));
    }

    // ── Campos bloqueados con postulada ────────────────────────────────

    public function test_postulada_bloquea_cargo(): void
    {
        [$instructor, $convocatoria] = $this->publicadaConPostulada();

        $respuesta = $this->actingAs($instructor)
            ->patchJson('/api/convocatorias/'.$convocatoria->id, ['cargo' => 'Otro'])
            ->assertStatus(422);

        $this->assertSame([$this->msg('cargo')], $respuesta->json('errors.cargo'));
        $this->assertSame('Instructor', $convocatoria->fresh()->cargo);
    }

    public function test_postulada_bloquea_area(): void
    {
        [$instructor, $convocatoria] = $this->publicadaConPostulada();

        $this->actingAs($instructor)
            ->patchJson('/api/convocatorias/'.$convocatoria->id, ['area' => 'Otra'])
            ->assertStatus(422)
            ->assertJsonPath('errors.area.0', $this->msg('area'));
    }

    public function test_postulada_bloquea_vacantes(): void
    {
        [$instructor, $convocatoria] = $this->publicadaConPostulada();

        $this->actingAs($instructor)
            ->patchJson('/api/convocatorias/'.$convocatoria->id, ['vacantes' => 9])
            ->assertStatus(422)
            ->assertJsonPath('errors.vacantes.0', $this->msg('vacantes'));
    }

    public function test_postulada_bloquea_salario_min(): void
    {
        [$instructor, $convocatoria] = $this->publicadaConPostulada();

        $this->actingAs($instructor)
            ->patchJson('/api/convocatorias/'.$convocatoria->id, ['salario_min' => 100])
            ->assertStatus(422)
            ->assertJsonPath('errors.salario_min.0', $this->msg('salario_min'));
    }

    public function test_postulada_bloquea_salario_max(): void
    {
        [$instructor, $convocatoria] = $this->publicadaConPostulada();

        $this->actingAs($instructor)
            ->patchJson('/api/convocatorias/'.$convocatoria->id, ['salario_max' => 9999999])
            ->assertStatus(422)
            ->assertJsonPath('errors.salario_max.0', $this->msg('salario_max'));
    }

    public function test_postulada_bloquea_las_cuatro_clasificaciones(): void
    {
        [$instructor, $convocatoria] = $this->publicadaConPostulada();

        $respuesta = $this->actingAs($instructor)
            ->patchJson('/api/convocatorias/'.$convocatoria->id, [
                'tipo_convocatoria' => 'interno',
                'tipo_empleo' => 'por_horas',
                'tipo_contrato' => 'obra_labor',
                'modalidad' => 'hibrido',
            ])
            ->assertStatus(422);

        $this->assertSame([$this->msg('tipo_convocatoria')], $respuesta->json('errors.tipo_convocatoria'));
        $this->assertSame([$this->msg('tipo_empleo')], $respuesta->json('errors.tipo_empleo'));
        $this->assertSame([$this->msg('tipo_contrato')], $respuesta->json('errors.tipo_contrato'));
        $this->assertSame([$this->msg('modalidad')], $respuesta->json('errors.modalidad'));
    }

    public function test_bloqueo_de_clasificacion_por_id_reporta_clave_canonica(): void
    {
        [$instructor, $convocatoria] = $this->publicadaConPostulada();

        $respuesta = $this->actingAs($instructor)
            ->patchJson('/api/convocatorias/'.$convocatoria->id, [
                'tipo_convocatoria_id' => $this->parametroId('tipo_convocatoria', 'interno'),
            ])
            ->assertStatus(422);

        $this->assertSame([$this->msg('tipo_convocatoria')], $respuesta->json('errors.tipo_convocatoria'));
        $this->assertNull($respuesta->json('errors.tipo_convocatoria_id'));
    }

    public function test_postulada_bloquea_fecha_apertura(): void
    {
        [$instructor, $convocatoria] = $this->publicadaConPostulada();

        $this->actingAs($instructor)
            ->patchJson('/api/convocatorias/'.$convocatoria->id, [
                'fecha_apertura' => now('America/Bogota')->addDays(2)->toDateString(),
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.fecha_apertura.0', $this->msg('fecha_apertura'));
    }

    public function test_postulada_bloquea_skills(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->publicable($ficha, $instructor);
        $this->conSkills($convocatoria, ['PHP', 'Laravel']);
        $this->postular($convocatoria, $this->aspirante($ficha));

        $this->actingAs($instructor)
            ->patchJson('/api/convocatorias/'.$convocatoria->id, ['skills' => ['PHP', 'Laravel', 'MySQL']])
            ->assertStatus(422)
            ->assertJsonPath('errors.skills.0', $this->msg('skills'));
    }

    public function test_postulada_bloquea_documentos(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->publicable($ficha, $instructor);
        $this->conDocumentos($convocatoria, ['hoja_de_vida' => true]);
        $this->postular($convocatoria, $this->aspirante($ficha));

        $this->actingAs($instructor)
            ->patchJson('/api/convocatorias/'.$convocatoria->id, [
                'documentos' => [['codigo' => 'tarjeta_profesional', 'obligatorio' => true]],
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.documentos.0', $this->msg('documentos'));
    }

    public function test_obligatoriedad_enviada_se_ignora_y_no_bloquea(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->publicable($ficha, $instructor);
        $this->conDocumentos($convocatoria, ['hoja_de_vida' => false]);
        $this->postular($convocatoria, $this->aspirante($ficha));

        // Se envía obligatorio = true, pero ese valor se ignora (lo decide el
        // backend) y, con postulaciones, no se reinyectan los obligatorios: el
        // conjunto de ids no cambia, la edición no se bloquea y lo guardado se
        // respeta.
        $respuesta = $this->actingAs($instructor)
            ->patchJson('/api/convocatorias/'.$convocatoria->id, [
                'documentos' => [['codigo' => 'hoja_de_vida', 'obligatorio' => true]],
            ])
            ->assertOk();

        $this->assertFalse($respuesta->json('data.documentos.0.obligatorio'));
    }

    public function test_con_postulaciones_no_se_reinyectan_los_obligatorios(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->publicable($ficha, $instructor);
        // A propósito, solo un documento extra (no obligatorio), como una
        // convocatoria creada antes de esta regla.
        $this->documentoExtra('documento_adicional');
        $this->conDocumentos($convocatoria, ['documento_adicional' => false]);
        $this->postular($convocatoria, $this->aspirante($ficha));

        $respuesta = $this->actingAs($instructor)
            ->patchJson('/api/convocatorias/'.$convocatoria->id, [
                'documentos' => [['codigo' => 'documento_adicional', 'obligatorio' => false]],
            ])
            ->assertOk();

        // No se agregan los obligatorios: se respeta lo guardado.
        $documentos = $respuesta->json('data.documentos');
        $this->assertCount(1, $documentos);
        $this->assertSame('documento_adicional', $documentos[0]['codigo']);
    }

    public function test_retirada_tambien_bloquea(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->publicable($ficha, $instructor);
        $this->retirar($convocatoria, $this->aspirante($ficha));

        $this->actingAs($instructor)
            ->patchJson('/api/convocatorias/'.$convocatoria->id, ['cargo' => 'Otro'])
            ->assertStatus(422)
            ->assertJsonPath('errors.cargo.0', $this->msg('cargo'));
    }

    public function test_varios_campos_bloqueados_se_reportan_juntos(): void
    {
        [$instructor, $convocatoria] = $this->publicadaConPostulada();

        $respuesta = $this->actingAs($instructor)
            ->patchJson('/api/convocatorias/'.$convocatoria->id, [
                'cargo' => 'Otro',
                'area' => 'Otra',
                'vacantes' => 7,
                'salario_min' => 1,
            ])
            ->assertStatus(422);

        $errores = $respuesta->json('errors');
        $this->assertSame([$this->msg('cargo')], $errores['cargo']);
        $this->assertSame([$this->msg('area')], $errores['area']);
        $this->assertSame([$this->msg('vacantes')], $errores['vacantes']);
        $this->assertSame([$this->msg('salario_min')], $errores['salario_min']);
        $this->assertArrayNotHasKey('salario_max', $errores);
    }

    public function test_bloqueo_no_guarda_ningun_cambio(): void
    {
        [$instructor, $convocatoria] = $this->publicadaConPostulada(['cargo' => 'Instructor', 'vacantes' => 2]);

        $this->actingAs($instructor)
            ->patchJson('/api/convocatorias/'.$convocatoria->id, ['cargo' => 'Otro', 'nombre' => 'Nuevo nombre'])
            ->assertStatus(422);

        $fresca = $convocatoria->fresh();
        $this->assertSame('Instructor', $fresca->cargo);
        // El nombre (editable) tampoco se guarda: un 422 no persiste nada.
        $this->assertSame('Instructor de software', $fresca->nombre);
    }

    // ── Campos permitidos ──────────────────────────────────────────────

    public function test_nombre_textos_y_fecha_evaluacion_se_editan_con_postulaciones(): void
    {
        [$instructor, $convocatoria] = $this->publicadaConPostulada();

        $this->actingAs($instructor)
            ->patchJson('/api/convocatorias/'.$convocatoria->id, [
                'nombre' => 'Nombre editado',
                'descripcion' => 'Descripción editada',
                'funciones' => 'Funciones editadas',
                'responsabilidades' => 'Responsabilidades editadas',
                'perfil_requerido' => 'Perfil editado',
                'formacion' => 'Formación editada',
                'experiencia' => 'Experiencia editada',
                'competencias' => 'Competencias editadas',
                'conocimientos_especificos' => 'Conocimientos editados',
                'fecha_evaluacion' => now('America/Bogota')->addDays(30)->toDateString(),
            ])
            ->assertOk();

        $fresca = $convocatoria->fresh();
        $this->assertSame('Nombre editado', $fresca->nombre);
        $this->assertSame('Descripción editada', $fresca->descripcion);
        $this->assertSame(
            now('America/Bogota')->addDays(30)->toDateString(),
            $fresca->fecha_evaluacion->toDateString()
        );
    }

    public function test_reenviar_formulario_completo_con_mismos_valores_devuelve_200(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->publicable($ficha, $instructor);
        $this->conSkills($convocatoria, ['PHP', 'Laravel']);
        $this->documentoExtra('documento_adicional');
        $this->conDocumentos($convocatoria, ['hoja_de_vida' => true, 'documento_adicional' => false]);
        $this->postular($convocatoria, $this->aspirante($ficha));

        $payload = $this->payloadCompleto($convocatoria);
        // Mismo conjunto de skills, otro orden y otras mayúsculas: no debe bloquear.
        $payload['skills'] = array_reverse(array_map('mb_strtoupper', $payload['skills']));

        $this->actingAs($instructor)
            ->patchJson('/api/convocatorias/'.$convocatoria->id, $payload)
            ->assertOk();

        $fresca = $convocatoria->fresh();
        $this->assertSame('Instructor', $fresca->cargo);
        $this->assertSame(2, $fresca->vacantes);
    }

    public function test_reenvio_identico_no_reescribe_skills_ni_documentos(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->publicable($ficha, $instructor);
        $this->conSkills($convocatoria, ['PHP', 'Laravel']);
        $this->conDocumentos($convocatoria, ['hoja_de_vida' => true]);
        $this->postular($convocatoria, $this->aspirante($ficha));

        $skillsAntes = $convocatoria->skills()->orderBy('id')->pluck('id')->all();
        $documentosAntes = $convocatoria->documentos()->get()
            ->mapWithKeys(fn ($d) => [(int) $d->id => (bool) $d->pivot->obligatorio])
            ->all();

        $this->actingAs($instructor)
            ->patchJson('/api/convocatorias/'.$convocatoria->id, $this->payloadCompleto($convocatoria))
            ->assertOk();

        // Las filas de skills NO se borran/crean de nuevo: mismos ids.
        $skillIdsDespues = $convocatoria->fresh()->skills()->orderBy('id')->pluck('id')->all();
        $this->assertSame($skillsAntes, $skillIdsDespues);

        // El pivote de documentos conserva los mismos ids y obligatoriedad.
        $documentosDespues = $convocatoria->fresh()->documentos()->get()
            ->mapWithKeys(fn ($p) => [(int) $p->id => (bool) $p->pivot->obligatorio])
            ->all();
        $this->assertSame($documentosAntes, $documentosDespues);
    }

    // ── fecha_cierre: solo ampliar ─────────────────────────────────────

    public function test_fecha_cierre_ampliada_devuelve_200(): void
    {
        [$instructor, $convocatoria] = $this->publicadaConPostulada();

        $nueva = $convocatoria->fecha_cierre->copy()->addDays(5)->toDateString();

        $this->actingAs($instructor)
            ->patchJson('/api/convocatorias/'.$convocatoria->id, ['fecha_cierre' => $nueva])
            ->assertOk();

        $this->assertSame($nueva, $convocatoria->fresh()->fecha_cierre->toDateString());
    }

    public function test_fecha_cierre_igual_a_la_actual_devuelve_200(): void
    {
        [$instructor, $convocatoria] = $this->publicadaConPostulada();

        $this->actingAs($instructor)
            ->patchJson('/api/convocatorias/'.$convocatoria->id, [
                'fecha_cierre' => $convocatoria->fecha_cierre->toDateString(),
            ])
            ->assertOk();
    }

    public function test_fecha_cierre_reducida_responde_422(): void
    {
        [$instructor, $convocatoria] = $this->publicadaConPostulada();

        $reducida = $convocatoria->fecha_cierre->copy()->subDays(3)->toDateString();

        $this->actingAs($instructor)
            ->patchJson('/api/convocatorias/'.$convocatoria->id, ['fecha_cierre' => $reducida])
            ->assertStatus(422)
            ->assertJsonPath('errors.fecha_cierre.0', 'La fecha de cierre solo se puede ampliar.');
    }

    // ── Estados que siguen bloqueados ──────────────────────────────────

    public function test_en_proceso_sigue_respondiendo_422(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->publicable($ficha, $instructor);
        $convocatoria->forceFill(['estado_id' => $this->parametroId('estado_convocatoria', 'en_proceso')])->save();

        $this->actingAs($instructor)
            ->patchJson('/api/convocatorias/'.$convocatoria->id, ['nombre' => 'X'])
            ->assertStatus(422)
            ->assertJsonPath('errors.estado.0', 'No se puede editar una convocatoria en proceso.');
    }

    public function test_cerrada_sigue_respondiendo_422(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->publicable($ficha, $instructor);
        $convocatoria->forceFill(['estado_id' => $this->parametroId('estado_convocatoria', 'cerrada')])->save();

        $this->actingAs($instructor)
            ->patchJson('/api/convocatorias/'.$convocatoria->id, ['nombre' => 'X'])
            ->assertStatus(422)
            ->assertJsonPath('errors.estado.0', 'No se puede editar una convocatoria cerrada.');
    }

    // ── Utilidad ───────────────────────────────────────────────────────

    /** @return array{0: User, 1: Convocatoria} */
    private function publicadaConPostulada(array $overrides = []): array
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->publicable($ficha, $instructor, $overrides);
        $this->postular($convocatoria, $this->aspirante($ficha));

        return [$instructor, $convocatoria];
    }
}
