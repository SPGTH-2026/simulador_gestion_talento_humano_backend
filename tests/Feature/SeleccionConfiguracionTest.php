<?php

namespace App\Tests\Feature;

use App\Enums\Role;
use App\Models\Convocatoria;
use App\Models\Ficha;
use App\Models\Parametro;
use App\Models\SeleccionConfiguracion;
use App\Models\User;
use Database\Seeders\ParametroSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeleccionConfiguracionTest extends TestCase
{
    use RefreshDatabase;

    private static int $codigoSecuencia = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ParametroSeeder::class);
    }

    private function ficha(string $codigo): Ficha
    {
        return Ficha::create(['codigo' => $codigo, 'nombre_programa' => 'ADSO']);
    }

    private function usuario(Role $role, ?string $subrole = null, array $extra = []): User
    {
        return User::factory()->create($extra + [
            'role' => $role,
            'subrole' => $subrole,
            'active' => true,
            'email_verified_at' => now(),
        ]);
    }

    private function seleccionador(Ficha $ficha): User
    {
        return $this->usuario(Role::Aprendiz, 'seleccionador', ['ficha_id' => $ficha->id]);
    }

    private function parametroId(string $tema, string $codigo): int
    {
        return (int) Parametro::porTemaYCodigo($tema, $codigo)->id;
    }

    private function convocatoria(Ficha $ficha, string $estado = 'publicada'): Convocatoria
    {
        return Convocatoria::unguarded(fn () => Convocatoria::create([
            'ficha_id' => $ficha->id,
            'codigo' => sprintf('CONV-CFG-%03d', ++self::$codigoSecuencia),
            'nombre' => 'Convocatoria '.$ficha->codigo,
            'creado_por' => $this->usuario(Role::Instructor, null, ['ficha_id' => $ficha->id])->id,
            'estado_id' => $this->parametroId('estado_convocatoria', $estado),
            'tipo_convocatoria_id' => $this->parametroId('tipo_convocatoria', 'externo'),
            'tipo_empleo_id' => $this->parametroId('tipo_empleo', 'tiempo_completo'),
            'tipo_contrato_id' => $this->parametroId('tipo_contrato', 'termino_fijo'),
            'modalidad_id' => $this->parametroId('modalidad', 'presencial'),
            'vacantes' => 5,
        ]));
    }

    private function cambiarEstado(Convocatoria $convocatoria, string $estado): void
    {
        $convocatoria->forceFill(['estado_id' => $this->parametroId('estado_convocatoria', $estado)])->save();
    }

    private function url(Convocatoria $convocatoria): string
    {
        return "/api/seleccion/convocatorias/{$convocatoria->id}/configuracion";
    }

    private function guardar(User $actor, Convocatoria $convocatoria, array $cuerpo)
    {
        return $this->actingAs($actor)->putJson($this->url($convocatoria), $cuerpo);
    }

    // ── Acceso ─────────────────────────────────────────────────────────

    public function test_invitado_recibe_401(): void
    {
        $convocatoria = $this->convocatoria($this->ficha('2896411'));

        $this->getJson($this->url($convocatoria))->assertUnauthorized();
        $this->putJson($this->url($convocatoria), ['entrevista_activa' => true, 'prueba_tecnica_activa' => false])->assertUnauthorized();
    }

    public function test_sin_permisos_de_seleccion_recibe_403_y_no_guarda(): void
    {
        $ficha = $this->ficha('2896411');
        $convocatoria = $this->convocatoria($ficha);
        $sinPermiso = [
            $this->usuario(Role::Aprendiz, 'evaluador', ['ficha_id' => $ficha->id]),
            $this->usuario(Role::Aprendiz, 'gestor_convocatorias', ['ficha_id' => $ficha->id]),
            $this->usuario(Role::Aspirante, null, ['ficha_id' => $ficha->id]),
        ];

        foreach ($sinPermiso as $actor) {
            $this->actingAs($actor)->getJson($this->url($convocatoria))->assertForbidden();
            $this->guardar($actor, $convocatoria, ['entrevista_activa' => true, 'prueba_tecnica_activa' => false])->assertForbidden();
        }
        $this->assertSame(0, SeleccionConfiguracion::count());
    }

    public function test_la_autorizacion_se_resuelve_antes_que_la_existencia(): void
    {
        $evaluador = $this->usuario(Role::Aprendiz, 'evaluador', ['ficha_id' => $this->ficha('2896411')->id]);

        $this->actingAs($evaluador)->getJson('/api/seleccion/convocatorias/999999/configuracion')->assertForbidden();
    }

    public function test_correo_sin_verificar_recibe_403(): void
    {
        $ficha = $this->ficha('2896411');
        $convocatoria = $this->convocatoria($ficha);
        $sinVerificar = $this->usuario(Role::Aprendiz, 'seleccionador', ['ficha_id' => $ficha->id, 'email_verified_at' => null]);

        $this->actingAs($sinVerificar)->getJson($this->url($convocatoria))->assertForbidden();
        $this->guardar($sinVerificar, $convocatoria, ['entrevista_activa' => true, 'prueba_tecnica_activa' => false])->assertForbidden();
    }

    public function test_convocatoria_ajena_o_inexistente_responde_404(): void
    {
        $fichaA = $this->ficha('2896411');
        $ajena = $this->convocatoria($this->ficha('2631620'));
        $seleccionador = $this->seleccionador($fichaA);
        $cuerpo = ['entrevista_activa' => true, 'prueba_tecnica_activa' => false];

        $this->actingAs($seleccionador)->getJson($this->url($ajena))->assertNotFound();
        $this->guardar($seleccionador, $ajena, $cuerpo)->assertNotFound();
        $this->actingAs($seleccionador)->getJson('/api/seleccion/convocatorias/999999/configuracion')->assertNotFound();
        $this->actingAs($seleccionador)->putJson('/api/seleccion/convocatorias/999999/configuracion', $cuerpo)->assertNotFound();
        $this->assertSame(0, SeleccionConfiguracion::count());

        // Un instructor de otra ficha tampoco la ve; el superadmin sí, en cualquier ficha.
        $instructorAjeno = $this->usuario(Role::Instructor, null, ['ficha_id' => $fichaA->id]);
        $this->guardar($instructorAjeno, $ajena, $cuerpo)->assertNotFound();
        $this->guardar($this->usuario(Role::SuperAdmin), $ajena, $cuerpo)->assertOk();
    }

    public function test_pueden_configurar_seleccionador_aprendiz_general_instructor_y_superadmin(): void
    {
        $ficha = $this->ficha('2896411');
        $convocatoria = $this->convocatoria($ficha);
        $cuerpo = ['entrevista_activa' => true, 'prueba_tecnica_activa' => false];

        $actores = [
            $this->seleccionador($ficha),
            $this->usuario(Role::Aprendiz, 'general', ['ficha_id' => $ficha->id]),
            $this->usuario(Role::Instructor, null, ['ficha_id' => $ficha->id]),
            $this->usuario(Role::SuperAdmin),
        ];

        foreach ($actores as $actor) {
            $this->guardar($actor, $convocatoria, $cuerpo)->assertOk();
            $this->actingAs($actor)->getJson($this->url($convocatoria))->assertOk()->assertJsonPath('data.opcion', 'solo_entrevista');
        }
        $this->assertSame(1, SeleccionConfiguracion::count());
    }

    // ── Pendiente de configurar vs sin actividades ─────────────────────

    public function test_se_distingue_pendiente_de_configurar_de_sin_actividades(): void
    {
        $ficha = $this->ficha('2896411');
        $convocatoria = $this->convocatoria($ficha);
        $seleccionador = $this->seleccionador($ficha);

        $this->actingAs($seleccionador)->getJson($this->url($convocatoria))->assertOk()
            ->assertJsonPath('data.configurada', false)
            ->assertJsonPath('data.opcion', null)
            ->assertJsonPath('data.entrevista', ['activa' => false, 'peso' => null])
            ->assertJsonPath('data.prueba_tecnica', ['activa' => false, 'peso' => null])
            ->assertJsonPath('data.editable', true);

        $this->guardar($seleccionador, $convocatoria, ['entrevista_activa' => false, 'prueba_tecnica_activa' => false])->assertOk();

        $this->actingAs($seleccionador)->getJson($this->url($convocatoria))->assertOk()
            ->assertJsonPath('data.configurada', true)
            ->assertJsonPath('data.opcion', 'sin_actividades')
            ->assertJsonPath('data.entrevista.peso', null)
            ->assertJsonPath('data.prueba_tecnica.peso', null)
            ->assertJsonPath('data.actualizada_por', $seleccionador->name);
    }

    // ── Opciones y pesos ───────────────────────────────────────────────

    public function test_las_cuatro_opciones_con_sus_pesos(): void
    {
        $ficha = $this->ficha('2896411');
        $convocatoria = $this->convocatoria($ficha);
        $actor = $this->seleccionador($ficha);

        $casos = [
            'sin_actividades' => [[false, false], [null, null]],
            'solo_entrevista' => [[true, false], [100, null]],
            'solo_prueba_tecnica' => [[false, true], [null, 100]],
            'ambas' => [[true, true], [50, 50]],
        ];

        foreach ($casos as $opcion => [[$e, $p], [$pesoE, $pesoP]]) {
            $this->guardar($actor, $convocatoria, ['entrevista_activa' => $e, 'prueba_tecnica_activa' => $p])
                ->assertOk()
                ->assertJsonPath('data.opcion', $opcion)
                ->assertJsonPath('data.entrevista', ['activa' => $e, 'peso' => $pesoE])
                ->assertJsonPath('data.prueba_tecnica', ['activa' => $p, 'peso' => $pesoP]);

            $fila = SeleccionConfiguracion::first();
            $this->assertSame([$pesoE, $pesoP], [$fila->peso_entrevista, $fila->peso_prueba_tecnica]);
        }
        $this->assertSame(1, SeleccionConfiguracion::count());
    }

    public function test_ponderacion_personalizada_y_peso_100_explicito(): void
    {
        $ficha = $this->ficha('2896411');
        $convocatoria = $this->convocatoria($ficha);
        $actor = $this->seleccionador($ficha);

        $this->guardar($actor, $convocatoria, ['entrevista_activa' => true, 'prueba_tecnica_activa' => true, 'peso_entrevista' => 40, 'peso_prueba_tecnica' => 60])
            ->assertOk()
            ->assertJsonPath('data.entrevista.peso', 40)
            ->assertJsonPath('data.prueba_tecnica.peso', 60);

        $this->guardar($actor, $convocatoria, ['entrevista_activa' => false, 'prueba_tecnica_activa' => true, 'peso_prueba_tecnica' => 100, 'peso_entrevista' => null])
            ->assertOk()
            ->assertJsonPath('data.prueba_tecnica.peso', 100)
            ->assertJsonPath('data.entrevista.peso', null);
    }

    public function test_pesos_invalidos_se_rechazan_sin_guardar(): void
    {
        $ficha = $this->ficha('2896411');
        $convocatoria = $this->convocatoria($ficha);
        $actor = $this->seleccionador($ficha);
        $ambas = ['entrevista_activa' => true, 'prueba_tecnica_activa' => true];

        // Un solo peso con ambas activas.
        $this->guardar($actor, $convocatoria, $ambas + ['peso_entrevista' => 40])->assertUnprocessable()->assertJsonValidationErrors('peso_entrevista');
        $this->guardar($actor, $convocatoria, $ambas + ['peso_prueba_tecnica' => 60])->assertUnprocessable()->assertJsonValidationErrors('peso_entrevista');
        // Suma distinta de 100.
        $this->guardar($actor, $convocatoria, $ambas + ['peso_entrevista' => 40, 'peso_prueba_tecnica' => 50])->assertUnprocessable()->assertJsonValidationErrors('peso_entrevista');
        $this->guardar($actor, $convocatoria, $ambas + ['peso_entrevista' => 60, 'peso_prueba_tecnica' => 60])->assertUnprocessable();
        // No positivos, fuera de rango o no enteros.
        $this->guardar($actor, $convocatoria, $ambas + ['peso_entrevista' => 0, 'peso_prueba_tecnica' => 100])->assertUnprocessable()->assertJsonValidationErrors('peso_entrevista');
        $this->guardar($actor, $convocatoria, $ambas + ['peso_entrevista' => 101, 'peso_prueba_tecnica' => -1])->assertUnprocessable();
        $this->guardar($actor, $convocatoria, $ambas + ['peso_entrevista' => 50.5, 'peso_prueba_tecnica' => 49.5])->assertUnprocessable();
        // Una sola actividad con un peso distinto de 100.
        $this->guardar($actor, $convocatoria, ['entrevista_activa' => true, 'prueba_tecnica_activa' => false, 'peso_entrevista' => 60])
            ->assertUnprocessable()->assertJsonValidationErrors('peso_entrevista');
        // Peso asignado a una actividad desactivada.
        $this->guardar($actor, $convocatoria, ['entrevista_activa' => true, 'prueba_tecnica_activa' => false, 'peso_prueba_tecnica' => 40])
            ->assertUnprocessable()->assertJsonValidationErrors('peso_prueba_tecnica');
        $this->guardar($actor, $convocatoria, ['entrevista_activa' => false, 'prueba_tecnica_activa' => false, 'peso_entrevista' => 100])
            ->assertUnprocessable()->assertJsonValidationErrors('peso_entrevista');
        // Contrato incompleto.
        $this->guardar($actor, $convocatoria, ['entrevista_activa' => true])->assertUnprocessable()->assertJsonValidationErrors('prueba_tecnica_activa');
        $this->guardar($actor, $convocatoria, [])->assertUnprocessable();

        $this->assertSame(0, SeleccionConfiguracion::count());
    }

    public function test_reenviar_no_duplica_y_actualiza_la_misma_fila(): void
    {
        $ficha = $this->ficha('2896411');
        $convocatoria = $this->convocatoria($ficha);
        $primero = $this->seleccionador($ficha);
        $segundo = $this->usuario(Role::Instructor, null, ['ficha_id' => $ficha->id]);
        $cuerpo = ['entrevista_activa' => true, 'prueba_tecnica_activa' => true, 'peso_entrevista' => 30, 'peso_prueba_tecnica' => 70];

        $this->guardar($primero, $convocatoria, $cuerpo)->assertOk();
        $id = SeleccionConfiguracion::first()->id;
        $this->guardar($primero, $convocatoria, $cuerpo)->assertOk();
        $this->assertSame(1, SeleccionConfiguracion::count());

        $this->guardar($segundo, $convocatoria, ['entrevista_activa' => false, 'prueba_tecnica_activa' => true])->assertOk();
        $fila = SeleccionConfiguracion::first();
        $this->assertSame(1, SeleccionConfiguracion::count());
        $this->assertSame($id, $fila->id);
        $this->assertSame($segundo->id, $fila->actualizado_por);
        $this->assertNull($fila->peso_entrevista);
        $this->assertSame(100, $fila->peso_prueba_tecnica);
    }

    public function test_cada_convocatoria_tiene_su_propia_configuracion(): void
    {
        $ficha = $this->ficha('2896411');
        $uno = $this->convocatoria($ficha);
        $dos = $this->convocatoria($ficha);
        $actor = $this->seleccionador($ficha);

        $this->guardar($actor, $uno, ['entrevista_activa' => true, 'prueba_tecnica_activa' => false])->assertOk();

        $this->actingAs($actor)->getJson($this->url($dos))->assertOk()->assertJsonPath('data.configurada', false);
        $this->assertSame(1, SeleccionConfiguracion::count());
    }

    // ── Estado de la convocatoria ──────────────────────────────────────

    public function test_se_puede_guardar_en_publicada_y_en_proceso(): void
    {
        $ficha = $this->ficha('2896411');
        $actor = $this->seleccionador($ficha);

        foreach (['publicada', 'en_proceso'] as $estado) {
            $convocatoria = $this->convocatoria($ficha, $estado);
            $this->guardar($actor, $convocatoria, ['entrevista_activa' => true, 'prueba_tecnica_activa' => true])
                ->assertOk()->assertJsonPath('data.estado_convocatoria', $estado)->assertJsonPath('data.editable', true);
        }
    }

    public function test_no_se_puede_guardar_en_borrador_ni_cerrada(): void
    {
        $ficha = $this->ficha('2896411');
        $actor = $this->seleccionador($ficha);

        foreach (['borrador', 'cerrada'] as $estado) {
            $convocatoria = $this->convocatoria($ficha, $estado);
            $this->guardar($actor, $convocatoria, ['entrevista_activa' => true, 'prueba_tecnica_activa' => false])
                ->assertUnprocessable()->assertJsonValidationErrors('convocatoria');
        }
        $this->assertSame(0, SeleccionConfiguracion::count());
    }

    public function test_una_convocatoria_cerrada_conserva_y_muestra_su_configuracion(): void
    {
        $ficha = $this->ficha('2896411');
        $convocatoria = $this->convocatoria($ficha, 'en_proceso');
        $actor = $this->seleccionador($ficha);

        $this->guardar($actor, $convocatoria, ['entrevista_activa' => true, 'prueba_tecnica_activa' => true, 'peso_entrevista' => 40, 'peso_prueba_tecnica' => 60])->assertOk();
        $this->cambiarEstado($convocatoria, 'cerrada');

        $this->actingAs($actor)->getJson($this->url($convocatoria))->assertOk()
            ->assertJsonPath('data.estado_convocatoria', 'cerrada')
            ->assertJsonPath('data.editable', false)
            ->assertJsonPath('data.opcion', 'ambas')
            ->assertJsonPath('data.entrevista.peso', 40)
            ->assertJsonPath('data.prueba_tecnica.peso', 60);

        // Y ya no se puede modificar.
        $this->guardar($actor, $convocatoria, ['entrevista_activa' => false, 'prueba_tecnica_activa' => false])->assertUnprocessable();
        $this->assertSame(40, SeleccionConfiguracion::first()->peso_entrevista);
    }
}
