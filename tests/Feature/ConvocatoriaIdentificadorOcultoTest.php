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
use Tests\TestCase;

/**
 * El identificador de la convocatoria (CONV-AAAA-NNN) es un dato de GESTIÓN.
 *
 * Lista blanca de quién lo ve:
 *  - instructor;
 *  - super admin;
 *  - aprendiz con el subrol 'gestor_convocatorias'.
 *
 * NO lo ven: el aspirante, el aprendiz con subrol 'general' (que también
 * tiene el permiso 'convocatorias:gestionar') ni el aprendiz con otros
 * subroles.
 *
 * Cubre el listado, el detalle, las postulaciones del自己和 el estado de
 * documentos, además del buscador (que para el resto no busca por código).
 */
class ConvocatoriaIdentificadorOcultoTest extends TestCase
{
    use RefreshDatabase;

    private static int $codigoSecuencia = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ParametroSeeder::class);
    }

    // ── Utilidades ─────────────────────────────────────────────────────

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

    /** Aprendiz con subrol: 'general', 'gestor_convocatorias', etc. */
    private function aprendiz(Ficha $ficha, string $subrol): User
    {
        return $this->usuario(Role::Aprendiz, [
            'subrole' => $subrol,
            'ficha_id' => $ficha->id,
        ]);
    }

    private function parametroId(string $tema, string $codigo): int
    {
        return (int) Parametro::porTemaYCodigo($tema, $codigo)->id;
    }

    private function publicada(Ficha $ficha, User $creador, array $overrides = []): Convocatoria
    {
        return Convocatoria::unguarded(fn () => Convocatoria::create($overrides + [
            'ficha_id' => $ficha->id,
            'codigo' => sprintf('CONV-ID-%03d', ++self::$codigoSecuencia),
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

    // ── Listado ────────────────────────────────────────────────────────

    public function test_el_listado_oculta_el_codigo_a_quien_no_gestiona(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->publicada($ficha, $instructor);

        // ElGestor (instructor) sí lo recibe.
        $delInstructor = $this->actingAs($instructor)->getJson('/api/convocatorias')->assertOk();
        $this->assertSame($convocatoria->codigo, $delInstructor->json('data.0.codigo'));

        // El Aspirante no.
        $delAspirante = $this->actingAs($this->aspirante($ficha))->getJson('/api/convocatorias')->assertOk();
        $this->assertNull($delAspirante->json('data.0.codigo'));
    }

    public function test_el_listado_oculta_el_codigo_al_aprendiz_general_pero_no_al_gestor(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->publicada($ficha, $instructor);

        // El aprendiz 'general' tiene 'convocatorias:gestionar' (ver
        // config/permissions.php) pero NO está en la lista blanca.
        $general = $this->aprendiz($ficha, 'general');
        $this->assertTrue($general->can('convocatorias:gestionar'));

        $delGeneral = $this->actingAs($general)->getJson('/api/convocatorias')->assertOk();
        $this->assertNull($delGeneral->json('data.0.codigo'));

        // El subrol 'gestor_convocatorias' sí está en la lista blanca.
        $gestor = $this->aprendiz($ficha, 'gestor_convocatorias');
        $this->assertTrue($gestor->can('convocatorias:gestionar'));

        $delGestor = $this->actingAs($gestor)->getJson('/api/convocatorias')->assertOk();
        $this->assertSame($convocatoria->codigo, $delGestor->json('data.0.codigo'));
    }

    public function test_el_listado_muestra_el_codigo_al_super_admin(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->publicada($ficha, $instructor);

        $respuesta = $this->actingAs($this->usuario(Role::SuperAdmin))
            ->getJson('/api/convocatorias')
            ->assertOk();

        $this->assertSame($convocatoria->codigo, $respuesta->json('data.0.codigo'));
    }

    // ── Detalle ────────────────────────────────────────────────────────

    public function test_el_detalle_oculta_el_codigo_a_quien_no_gestiona(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->publicada($ficha, $instructor);

        $delAspirante = $this->actingAs($this->aspirante($ficha))
            ->getJson('/api/convocatorias/'.$convocatoria->id)
            ->assertOk();
        $this->assertNull($delAspirante->json('data.codigo'));

        $delGeneral = $this->actingAs($this->aprendiz($ficha, 'general'))
            ->getJson('/api/convocatorias/'.$convocatoria->id)
            ->assertOk();
        $this->assertNull($delGeneral->json('data.codigo'));

        $delGestor = $this->actingAs($this->aprendiz($ficha, 'gestor_convocatorias'))
            ->getJson('/api/convocatorias/'.$convocatoria->id)
            ->assertOk();
        $this->assertSame($convocatoria->codigo, $delGestor->json('data.codigo'));

        $delInstructor = $this->actingAs($instructor)
            ->getJson('/api/convocatorias/'.$convocatoria->id)
            ->assertOk();
        $this->assertSame($convocatoria->codigo, $delInstructor->json('data.codigo'));
    }

    // ── Postulaciones (Mis postulaciones) ──────────────────────────────

    public function test_las_postulaciones_del_aspirante_no_incluyen_el_codigo(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->publicada($ficha, $instructor);
        $aspirante = $this->aspirante($ficha);

        Postulacion::create([
            'convocatoria_id' => $convocatoria->id,
            'user_id' => $aspirante->id,
            'estado_id' => $this->parametroId('estado_postulacion', 'postulada'),
            'fecha_postulacion' => now(),
        ]);

        $respuesta = $this->actingAs($aspirante)
            ->getJson('/api/postulaciones')
            ->assertOk();

        $this->assertNull($respuesta->json('data.0.convocatoria.codigo'));
        $this->assertSame($convocatoria->id, $respuesta->json('data.0.convocatoria.id'));
    }

    public function test_el_estado_de_documentos_del_dueno_no_incluye_el_codigo(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->publicada($ficha, $instructor);
        $aspirante = $this->aspirante($ficha);

        $postulacion = Postulacion::create([
            'convocatoria_id' => $convocatoria->id,
            'user_id' => $aspirante->id,
            'estado_id' => $this->parametroId('estado_postulacion', 'postulada'),
            'fecha_postulacion' => now(),
        ]);

        $delDueno = $this->actingAs($aspirante)
            ->getJson("/api/postulaciones/{$postulacion->id}/documentos")
            ->assertOk();
        $this->assertNull($delDueno->json('data.convocatoria.codigo'));

        // El revisor (lista blanca) sí lo recibe.
        $delRevisor = $this->actingAs($instructor)
            ->getJson("/api/revision/postulaciones/{$postulacion->id}/documentos")
            ->assertOk();
        $this->assertSame($convocatoria->codigo, $delRevisor->json('data.convocatoria.codigo'));
    }

    // ── Buscador ───────────────────────────────────────────────────────

    public function test_el_buscador_por_codigo_solo_funciona_para_la_lista_blanca(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->publicada($ficha, $instructor, ['nombre' => 'Nombre normal']);

        $delGestor = $this->actingAs($this->aprendiz($ficha, 'gestor_convocatorias'))
            ->getJson('/api/convocatorias?q='.$convocatoria->codigo)
            ->assertOk();
        $this->assertCount(1, $delGestor->json('data'));

        $delAspirante = $this->actingAs($this->aspirante($ficha))
            ->getJson('/api/convocatorias?q='.$convocatoria->codigo)
            ->assertOk();
        $this->assertSame([], $delAspirante->json('data'));

        $delGeneral = $this->actingAs($this->aprendiz($ficha, 'general'))
            ->getJson('/api/convocatorias?q='.$convocatoria->codigo)
            ->assertOk();
        $this->assertSame([], $delGeneral->json('data'));
    }
}
