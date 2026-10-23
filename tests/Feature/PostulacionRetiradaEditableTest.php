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
 * Postulación RETIRADA: sigue siendo editable por su dueño mientras la
 * convocatoria esté publicada y dentro de la ventana (America/Bogota). Editar
 * no cambia el estado. Reactivar (retirada -> postulada) exige la ventana y los
 * obligatorios completos.
 */
class PostulacionRetiradaEditableTest extends TestCase
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
            'codigo' => sprintf('CONV-RET-%03d', ++self::$codigoSecuencia),
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

    private function convocatoriaCerrada(Ficha $ficha, User $creador): Convocatoria
    {
        return $this->crearConvocatoria($ficha, $creador, [
            'estado_id' => $this->parametroId('estado_convocatoria', 'publicada'),
            'fecha_apertura' => now('America/Bogota')->subDays(10)->toDateString(),
            'fecha_cierre' => now('America/Bogota')->subDay()->toDateString(),
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

    private function postulacionRetirada(Convocatoria $convocatoria, User $usuario): Postulacion
    {
        return Postulacion::create([
            'convocatoria_id' => $convocatoria->id,
            'user_id' => $usuario->id,
            'estado_id' => $this->parametroId('estado_postulacion', 'retirada'),
            'fecha_postulacion' => now(),
            'fecha_retiro' => now(),
        ]);
    }

    private function pdf(string $nombre = 'documento.pdf'): UploadedFile
    {
        return UploadedFile::fake()->create($nombre, 100, 'application/pdf');
    }

    private function estadoDe(Postulacion $postulacion): int
    {
        return (int) $postulacion->fresh()->estado_id;
    }

    // ── Edición dentro de la ventana ───────────────────────────────────

    public function test_retirada_sube_documento_requerido_dentro_de_la_ventana_sin_cambiar_estado(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->conDocumentos($this->convocatoriaAbierta($ficha, $instructor), ['hoja_de_vida' => true]);
        $postulacion = $this->postulacionRetirada($convocatoria, $aspirante);
        $antes = $this->estadoDe($postulacion);

        $this->actingAs($aspirante)
            ->postJson("/api/postulaciones/{$postulacion->id}/documentos", [
                'codigo' => 'hoja_de_vida',
                'archivo' => $this->pdf(),
            ])
            ->assertCreated();

        $this->assertSame($antes, $this->estadoDe($postulacion));
    }

    public function test_retirada_sube_y_elimina_adicional_dentro_de_la_ventana(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->convocatoriaAbierta($ficha, $instructor);
        $postulacion = $this->postulacionRetirada($convocatoria, $aspirante);

        $respuesta = $this->actingAs($aspirante)
            ->postJson("/api/postulaciones/{$postulacion->id}/documentos/adicionales", [
                'titulo' => 'Certificado extra',
                'archivo' => $this->pdf(),
            ])
            ->assertCreated();

        $documentoId = $respuesta->json('data.archivo.id');

        $this->actingAs($aspirante)
            ->deleteJson("/api/postulaciones/{$postulacion->id}/documentos/{$documentoId}")
            ->assertNoContent();

        $this->assertDatabaseMissing('postulacion_documentos', ['id' => $documentoId]);
    }

    public function test_retirada_declara_skill_dentro_de_la_ventana(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->convocatoriaAbierta($ficha, $instructor);
        $postulacion = $this->postulacionRetirada($convocatoria, $aspirante);
        $skill = ConvocatoriaSkill::create([
            'convocatoria_id' => $convocatoria->id,
            'nombre' => 'Excel avanzado',
        ]);

        $this->actingAs($aspirante)
            ->postJson("/api/postulaciones/{$postulacion->id}/skills", ['convocatoria_skill_id' => $skill->id])
            ->assertCreated();
    }

    public function test_retirada_cambia_el_sexo_sin_cambiar_estado(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->convocatoriaAbierta($ficha, $instructor);
        $postulacion = $this->postulacionRetirada($convocatoria, $aspirante);
        $antes = $this->estadoDe($postulacion);

        $this->actingAs($aspirante)
            ->putJson('/api/perfil/sexo', ['sexo' => 'mujer'])
            ->assertOk()
            ->assertJsonPath('data.sexo.codigo', 'mujer');

        $this->assertSame($antes, $this->estadoDe($postulacion));
    }

    // ── Fuera de la ventana ────────────────────────────────────────────

    public function test_retirada_con_convocatoria_cerrada_da_422_y_el_get_explica_por_que(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->convocatoriaCerrada($ficha, $instructor);
        $postulacion = $this->postulacionRetirada($convocatoria, $aspirante);
        $motivo = 'La convocatoria ya cerró el '.$convocatoria->fecha_cierre->toDateString()
            .'. Tu postulación retirada queda solo para consulta.';

        $this->actingAs($aspirante)
            ->postJson("/api/postulaciones/{$postulacion->id}/documentos", [
                'codigo' => 'hoja_de_vida',
                'archivo' => $this->pdf(),
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.documentos.0', $motivo);

        $this->actingAs($aspirante)
            ->getJson("/api/postulaciones/{$postulacion->id}/documentos")
            ->assertOk()
            ->assertJsonPath('data.editable', false)
            ->assertJsonPath('data.motivo_edicion', $motivo);
    }

    public function test_retirada_con_convocatoria_en_proceso_da_422(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->convocatoriaAbierta($ficha, $instructor, [
            'estado_id' => $this->parametroId('estado_convocatoria', 'en_proceso'),
        ]);
        $postulacion = $this->postulacionRetirada($convocatoria, $aspirante);

        $this->actingAs($aspirante)
            ->postJson("/api/postulaciones/{$postulacion->id}/documentos/adicionales", [
                'titulo' => 'Certificado extra',
                'archivo' => $this->pdf(),
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.documentos.0', 'La convocatoria no está publicada.');
    }

    public function test_retirada_ajena_devuelve_404(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $duenio = $this->aspirante($ficha);
        $otro = $this->aspirante($ficha);
        $convocatoria = $this->conDocumentos($this->convocatoriaAbierta($ficha, $instructor), ['hoja_de_vida' => true]);
        $postulacion = $this->postulacionRetirada($convocatoria, $duenio);

        $this->actingAs($otro)
            ->postJson("/api/postulaciones/{$postulacion->id}/documentos", [
                'codigo' => 'hoja_de_vida',
                'archivo' => $this->pdf(),
            ])
            ->assertNotFound()
            ->assertJsonPath('message', 'Postulación no encontrada.');
    }

    // ── Eliminar un obligatorio deja la postulación incompleta ─────────

    public function test_eliminar_obligatorio_en_retirada_deja_el_estado_incompleto(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->conDocumentos($this->convocatoriaAbierta($ficha, $instructor), ['hoja_de_vida' => true]);
        $postulacion = $this->postulacionRetirada($convocatoria, $aspirante);
        $antes = $this->estadoDe($postulacion);

        $subida = $this->actingAs($aspirante)
            ->postJson("/api/postulaciones/{$postulacion->id}/documentos", [
                'codigo' => 'hoja_de_vida',
                'archivo' => $this->pdf(),
            ])
            ->assertCreated();

        $this->actingAs($aspirante)
            ->deleteJson("/api/postulaciones/{$postulacion->id}/documentos/{$subida->json('data.id')}")
            ->assertNoContent();

        $this->actingAs($aspirante)
            ->getJson("/api/postulaciones/{$postulacion->id}/documentos")
            ->assertOk()
            ->assertJsonPath('data.completo', false);

        $this->assertSame($antes, $this->estadoDe($postulacion));
    }

    // ── Reactivación: "Volver a postularme" (retirada -> postulada) ────

    public function test_reactivar_sin_obligatorios_da_422_con_faltantes_y_sigue_retirada(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->conDocumentos($this->convocatoriaAbierta($ficha, $instructor), ['hoja_de_vida' => true]);
        $postulacion = $this->postulacionRetirada($convocatoria, $aspirante);
        $antes = $this->estadoDe($postulacion);

        $this->actingAs($aspirante)
            ->patchJson("/api/postulaciones/{$postulacion->id}/estado", ['estado' => 'postulada'])
            ->assertStatus(422)
            ->assertJsonPath('faltantes.0.codigo', 'hoja_de_vida');

        $this->assertSame($antes, $this->estadoDe($postulacion));
    }

    public function test_reactivar_con_obligatorios_completos_pasa_a_postulada(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->conDocumentos($this->convocatoriaAbierta($ficha, $instructor), ['hoja_de_vida' => true]);
        $postulacion = $this->postulacionRetirada($convocatoria, $aspirante);

        $this->actingAs($aspirante)
            ->postJson("/api/postulaciones/{$postulacion->id}/documentos", [
                'codigo' => 'hoja_de_vida',
                'archivo' => $this->pdf(),
            ])
            ->assertCreated();

        $this->actingAs($aspirante)
            ->patchJson("/api/postulaciones/{$postulacion->id}/estado", ['estado' => 'postulada'])
            ->assertOk()
            ->assertJsonPath('data.estado.codigo', 'postulada')
            ->assertJsonPath('data.fecha_retiro', null);
    }

    public function test_reactivar_fuera_de_la_ventana_da_422(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->convocatoriaCerrada($ficha, $instructor);
        $postulacion = $this->postulacionRetirada($convocatoria, $aspirante);

        $this->actingAs($aspirante)
            ->patchJson("/api/postulaciones/{$postulacion->id}/estado", ['estado' => 'postulada'])
            ->assertStatus(422)
            ->assertJsonPath(
                'errors.estado.0',
                'La convocatoria ya cerró el '.$convocatoria->fecha_cierre->toDateString().'.',
            );
    }

    public function test_postulada_directa_desde_borrador_sigue_redirigiendo_al_envio(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->convocatoriaAbierta($ficha, $instructor);
        $postulacion = Postulacion::create([
            'convocatoria_id' => $convocatoria->id,
            'user_id' => $aspirante->id,
            'estado_id' => $this->parametroId('estado_postulacion', 'borrador'),
            'fecha_postulacion' => now(),
        ]);

        $this->actingAs($aspirante)
            ->patchJson("/api/postulaciones/{$postulacion->id}/estado", ['estado' => 'postulada'])
            ->assertStatus(422)
            ->assertJsonPath('errors.estado.0', 'Para enviar tu postulación usa el endpoint de envío.');
    }
}
