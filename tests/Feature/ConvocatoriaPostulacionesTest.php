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
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Paso 4: `mi_postulacion` y `postulantes` en las respuestas de convocatorias.
 *
 * Reglas cubiertas:
 *  - `mi_postulacion` (null o {id, estado}) SOLO al rol Aspirante; incluye la
 *    postulacion retirada. `postulantes` (conteo de postuladas) SOLO a quien
 *    puede `convocatorias:gestionar` (instructor, super admin y el aprendiz con
 *    ese permiso). La clave que no corresponde NO aparece en el JSON.
 *  - El aspirante nunca recibe `postulantes` ni la postulacion de otro aspirante.
 *  - El conteo no suma las retiradas.
 *  - Sin N+1: el numero de consultas del GET no crece con el numero de filas.
 */
class ConvocatoriaPostulacionesTest extends TestCase
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

    private function superAdmin(): User
    {
        return $this->usuario(Role::SuperAdmin);
    }

    /** Aprendiz con `convocatorias:gestionar` (subrole general): recibe el conteo. */
    private function aprendizGestor(Ficha $ficha): User
    {
        return $this->usuario(Role::Aprendiz, ['subrole' => 'general', 'ficha_id' => $ficha->id]);
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

    /** Publicada y con la ventana abierta hoy (America/Bogota): visible al aspirante. */
    private function convocatoriaAbierta(Ficha $ficha, User $creador, array $overrides = []): Convocatoria
    {
        return $this->crearConvocatoria($ficha, $creador, $overrides + [
            'estado_id' => $this->parametroId('estado_convocatoria', 'publicada'),
            'fecha_apertura' => now('America/Bogota')->subDay()->toDateString(),
            'fecha_cierre' => now('America/Bogota')->addDays(10)->toDateString(),
        ]);
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

    // ── Listado: aspirante ─────────────────────────────────────────────

    public function test_listado_aspirante_sin_postulacion_recibe_mi_postulacion_null(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $this->convocatoriaAbierta($ficha, $instructor);
        $aspirante = $this->aspirante($ficha);

        $respuesta = $this->actingAs($aspirante)->getJson('/api/convocatorias')->assertOk();

        $this->assertSame(1, $respuesta->json('meta.total'));
        $this->assertNull($respuesta->json('data.0.mi_postulacion'));
    }

    public function test_listado_aspirante_postulado_recibe_id_y_estado_postulada(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->convocatoriaAbierta($ficha, $instructor);
        $aspirante = $this->aspirante($ficha);
        $propia = $this->postular($convocatoria, $aspirante);

        $respuesta = $this->actingAs($aspirante)->getJson('/api/convocatorias')->assertOk();

        $this->assertSame($propia->id, $respuesta->json('data.0.mi_postulacion.id'));
        $this->assertSame('postulada', $respuesta->json('data.0.mi_postulacion.estado.codigo'));
        $this->assertSame('Postulada', $respuesta->json('data.0.mi_postulacion.estado.nombre'));
        // El estado de mi_postulacion va por codigo/nombre (sin id).
        $this->assertSame(['codigo', 'nombre'], array_keys($respuesta->json('data.0.mi_postulacion.estado')));
    }

    public function test_listado_aspirante_con_postulacion_retirada_recibe_estado_retirada(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->convocatoriaAbierta($ficha, $instructor);
        $aspirante = $this->aspirante($ficha);
        $retirada = $this->retirar($convocatoria, $aspirante);

        $respuesta = $this->actingAs($aspirante)->getJson('/api/convocatorias')->assertOk();

        $this->assertSame($retirada->id, $respuesta->json('data.0.mi_postulacion.id'));
        $this->assertSame('retirada', $respuesta->json('data.0.mi_postulacion.estado.codigo'));
        $this->assertSame('Retirada', $respuesta->json('data.0.mi_postulacion.estado.nombre'));
    }

    public function test_listado_aspirante_no_recibe_la_clave_postulantes(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->convocatoriaAbierta($ficha, $instructor);
        $aspirante = $this->aspirante($ficha);
        $this->postular($convocatoria, $aspirante);

        $respuesta = $this->actingAs($aspirante)->getJson('/api/convocatorias')->assertOk();

        $this->assertArrayHasKey('mi_postulacion', $respuesta->json('data.0'));
        $this->assertArrayNotHasKey('postulantes', $respuesta->json('data.0'));
    }

    public function test_mi_postulacion_muestra_solo_la_del_autenticado(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspiranteA = $this->aspirante($ficha);
        $aspiranteB = $this->aspirante($ficha);

        $compartida = $this->convocatoriaAbierta($ficha, $instructor);
        $soloDeB = $this->convocatoriaAbierta($ficha, $instructor);

        $propiaDeA = $this->postular($compartida, $aspiranteA);
        $this->postular($compartida, $aspiranteB);
        $this->postular($soloDeB, $aspiranteB);

        $data = $this->actingAs($aspiranteA)->getJson('/api/convocatorias')->assertOk()->json('data');

        $porConvocatoria = collect($data)->keyBy('id');

        // En la compartida ve la SUYA, no la de B.
        $this->assertSame($propiaDeA->id, $porConvocatoria[$compartida->id]['mi_postulacion']['id']);
        // En la que solo postulo B, A no ve nada.
        $this->assertNull($porConvocatoria[$soloDeB->id]['mi_postulacion']);
    }

    public function test_listado_aspirante_no_dispara_n_mas_1(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);

        $convocatorias = [];
        for ($i = 0; $i < 10; $i++) {
            $convocatorias[] = $this->convocatoriaAbierta($ficha, $instructor);
        }

        $this->postular($convocatorias[0], $aspirante);
        $this->postular($convocatorias[1], $aspirante);

        DB::enableQueryLog();
        $this->actingAs($aspirante)->getJson('/api/convocatorias?per_page=100')->assertOk();
        $conDos = count(DB::getQueryLog());
        DB::disableQueryLog();

        for ($i = 2; $i < 10; $i++) {
            $this->postular($convocatorias[$i], $aspirante);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($aspirante)->getJson('/api/convocatorias?per_page=100')->assertOk();
        $conDiez = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame($conDos, $conDiez);
    }

    // ── Listado: gestores ──────────────────────────────────────────────

    public function test_listado_instructor_recibe_postulantes_y_no_mi_postulacion(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->convocatoriaAbierta($ficha, $instructor);

        $this->postular($convocatoria, $this->aspirante($ficha));
        $this->postular($convocatoria, $this->aspirante($ficha));

        $item = $this->actingAs($instructor)->getJson('/api/convocatorias')->assertOk()->json('data.0');

        $this->assertSame(2, $item['postulantes']);
        $this->assertArrayNotHasKey('mi_postulacion', $item);
    }

    public function test_listado_super_admin_recibe_postulantes_y_no_mi_postulacion(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->convocatoriaAbierta($ficha, $instructor);

        $this->postular($convocatoria, $this->aspirante($ficha));

        $item = $this->actingAs($this->superAdmin())->getJson('/api/convocatorias')->assertOk()->json('data.0');

        $this->assertSame(1, $item['postulantes']);
        $this->assertArrayNotHasKey('mi_postulacion', $item);
    }

    public function test_listado_aprendiz_gestor_recibe_postulantes_y_no_mi_postulacion(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->convocatoriaAbierta($ficha, $instructor);

        $this->postular($convocatoria, $this->aspirante($ficha));

        $item = $this->actingAs($this->aprendizGestor($ficha))->getJson('/api/convocatorias')->assertOk()->json('data.0');

        $this->assertSame(1, $item['postulantes']);
        $this->assertArrayNotHasKey('mi_postulacion', $item);
    }

    public function test_postulantes_cuenta_solo_estado_postulada(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->convocatoriaAbierta($ficha, $instructor);

        $this->postular($convocatoria, $this->aspirante($ficha));
        $this->postular($convocatoria, $this->aspirante($ficha));
        $this->retirar($convocatoria, $this->aspirante($ficha));

        $item = $this->actingAs($instructor)->getJson('/api/convocatorias')->assertOk()->json('data.0');

        // Dos postuladas + una retirada: la retirada NO suma.
        $this->assertSame(2, $item['postulantes']);
    }

    public function test_listado_gestor_no_dispara_n_mas_1(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);

        $convocatorias = [];
        for ($i = 0; $i < 10; $i++) {
            $convocatorias[] = $this->convocatoriaAbierta($ficha, $instructor);
        }

        $this->postular($convocatorias[0], $this->aspirante($ficha));
        $this->postular($convocatorias[1], $this->aspirante($ficha));

        DB::enableQueryLog();
        $this->actingAs($instructor)->getJson('/api/convocatorias?per_page=100')->assertOk();
        $conDos = count(DB::getQueryLog());
        DB::disableQueryLog();

        for ($i = 2; $i < 10; $i++) {
            $this->postular($convocatorias[$i], $this->aspirante($ficha));
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($instructor)->getJson('/api/convocatorias?per_page=100')->assertOk();
        $conDiez = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame($conDos, $conDiez);
    }

    // ── Detalle ────────────────────────────────────────────────────────

    public function test_detalle_aspirante_incluye_mi_postulacion_y_no_postulantes(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->convocatoriaAbierta($ficha, $instructor);
        $aspirante = $this->aspirante($ficha);
        $propia = $this->postular($convocatoria, $aspirante);

        $data = $this->actingAs($aspirante)
            ->getJson('/api/convocatorias/'.$convocatoria->id)
            ->assertOk()
            ->json('data');

        $this->assertSame($propia->id, $data['mi_postulacion']['id']);
        $this->assertSame('postulada', $data['mi_postulacion']['estado']['codigo']);
        $this->assertArrayNotHasKey('postulantes', $data);
    }

    public function test_detalle_aspirante_sin_postulacion_recibe_mi_postulacion_null(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->convocatoriaAbierta($ficha, $instructor);

        $data = $this->actingAs($this->aspirante($ficha))
            ->getJson('/api/convocatorias/'.$convocatoria->id)
            ->assertOk()
            ->json('data');

        $this->assertArrayHasKey('mi_postulacion', $data);
        $this->assertNull($data['mi_postulacion']);
        $this->assertArrayNotHasKey('postulantes', $data);
    }

    public function test_detalle_gestor_incluye_postulantes_y_no_mi_postulacion(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->convocatoriaAbierta($ficha, $instructor);

        $this->postular($convocatoria, $this->aspirante($ficha));
        $this->postular($convocatoria, $this->aspirante($ficha));
        $this->retirar($convocatoria, $this->aspirante($ficha));

        $data = $this->actingAs($instructor)
            ->getJson('/api/convocatorias/'.$convocatoria->id)
            ->assertOk()
            ->json('data');

        $this->assertSame(2, $data['postulantes']);
        $this->assertArrayNotHasKey('mi_postulacion', $data);
    }

    // ── Store / Update / Estado ────────────────────────────────────────

    public function test_crear_convocatoria_devuelve_postulantes_en_cero(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);

        $respuesta = $this->actingAs($instructor)
            ->postJson('/api/convocatorias', ['nombre' => 'Nueva'])
            ->assertCreated();

        $this->assertSame(0, $respuesta->json('data.postulantes'));
        $this->assertArrayNotHasKey('mi_postulacion', $respuesta->json('data'));
    }

    public function test_editar_convocatoria_devuelve_postulantes(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->convocatoriaAbierta($ficha, $instructor);

        $this->postular($convocatoria, $this->aspirante($ficha));
        $this->postular($convocatoria, $this->aspirante($ficha));

        $respuesta = $this->actingAs($instructor)
            ->patchJson('/api/convocatorias/'.$convocatoria->id, ['nombre' => 'Editada'])
            ->assertOk();

        $this->assertSame(2, $respuesta->json('data.postulantes'));
        $this->assertArrayNotHasKey('mi_postulacion', $respuesta->json('data'));
    }

    public function test_cambio_de_estado_devuelve_postulantes(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->convocatoriaAbierta($ficha, $instructor);

        $this->postular($convocatoria, $this->aspirante($ficha));

        $respuesta = $this->actingAs($instructor)
            ->patchJson('/api/convocatorias/'.$convocatoria->id.'/estado', ['estado' => 'en_proceso'])
            ->assertOk();

        $this->assertSame(1, $respuesta->json('data.postulantes'));
        $this->assertArrayNotHasKey('mi_postulacion', $respuesta->json('data'));
    }
}
