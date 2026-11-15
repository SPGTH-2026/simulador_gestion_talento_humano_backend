<?php

namespace App\Tests\Feature;

use App\Enums\Role;
use App\Models\Convocatoria;
use App\Models\Evaluacion;
use App\Models\Ficha;
use App\Models\Parametro;
use App\Models\Postulacion;
use App\Models\SeleccionConfiguracion;
use App\Models\SeleccionFinalistaLista;
use App\Models\User;
use Database\Seeders\ParametroSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Listado de convocatorias de Selección: filtro por estado, conteo de candidatos y «requiere atención».
 */
class SeleccionListadoTest extends TestCase
{
    use RefreshDatabase;

    private static int $codigoSecuencia = 0;

    private ?User $evaluadorCompartido = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ParametroSeeder::class);
    }

    private function ficha(string $codigo = '2896411'): Ficha
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

    private function convocatoria(Ficha $ficha, string $estado = 'en_proceso'): Convocatoria
    {
        return Convocatoria::unguarded(fn () => Convocatoria::create([
            'ficha_id' => $ficha->id,
            'codigo' => sprintf('CONV-LST-%03d', ++self::$codigoSecuencia),
            'nombre' => 'Convocatoria '.self::$codigoSecuencia,
            'creado_por' => $this->usuario(Role::Instructor, null, ['ficha_id' => $ficha->id])->id,
            'estado_id' => $this->parametroId('estado_convocatoria', $estado),
            'tipo_convocatoria_id' => $this->parametroId('tipo_convocatoria', 'externo'),
            'tipo_empleo_id' => $this->parametroId('tipo_empleo', 'tiempo_completo'),
            'tipo_contrato_id' => $this->parametroId('tipo_contrato', 'termino_fijo'),
            'modalidad_id' => $this->parametroId('modalidad', 'presencial'),
            'vacantes' => 2,
        ]));
    }

    /** Sin actividades: basta con la habilitación documental. */
    private function sinActividades(Convocatoria $convocatoria): void
    {
        SeleccionConfiguracion::create([
            'convocatoria_id' => $convocatoria->id,
            'entrevista_activa' => false,
            'prueba_tecnica_activa' => false,
        ]);
    }

    /** Con entrevista activa: sin nota, nadie está listo. */
    private function conEntrevista(Convocatoria $convocatoria): void
    {
        SeleccionConfiguracion::create([
            'convocatoria_id' => $convocatoria->id,
            'entrevista_activa' => true,
            'prueba_tecnica_activa' => false,
            'peso_entrevista' => 100,
        ]);
    }

    private function postulacion(Convocatoria $convocatoria, string $estado = 'postulada'): Postulacion
    {
        return Postulacion::create([
            'convocatoria_id' => $convocatoria->id,
            'user_id' => $this->usuario(Role::Aspirante, null, ['ficha_id' => $convocatoria->ficha_id])->id,
            'estado_id' => $this->parametroId('estado_postulacion', $estado),
            'fecha_postulacion' => now(),
        ]);
    }

    /** Candidato habilitado documentalmente (evaluación finalizada que pasa a selección). */
    private function habilitado(Convocatoria $convocatoria): Postulacion
    {
        $postulacion = $this->postulacion($convocatoria);
        $this->evaluadorCompartido ??= $this->usuario(Role::Aprendiz, 'evaluador', ['ficha_id' => $convocatoria->ficha_id]);
        Evaluacion::create([
            'postulacion_id' => $postulacion->id,
            'evaluador_id' => $this->evaluadorCompartido->id,
            'estado' => 'finalizada',
            'cumplimiento' => 90,
            'pasa_seleccion' => true,
        ]);

        return $postulacion;
    }

    private function confirmarLista(Convocatoria $convocatoria, User $actor): void
    {
        SeleccionFinalistaLista::create([
            'convocatoria_id' => $convocatoria->id,
            'version' => 1,
            'modo' => 'sin_actividades',
            'objetivo' => 1,
            'confirmado_por' => $actor->id,
        ]);
    }

    private function listado(User $actor, string $consulta = '')
    {
        return $this->actingAs($actor)->getJson('/api/seleccion/convocatorias'.$consulta);
    }

    // ── Filtro por estado ──────────────────────────────────────────────

    public function test_el_filtro_de_estado_se_aplica_antes_de_paginar(): void
    {
        $ficha = $this->ficha();
        $actor = $this->seleccionador($ficha);
        // Las 3 en proceso son las más viejas: con 21 publicadas encima quedarían fuera de la primera página.
        $enProceso = collect(range(1, 3))->map(fn () => $this->convocatoria($ficha, 'en_proceso'));
        foreach (range(1, 21) as $_) {
            $this->convocatoria($ficha, 'publicada');
        }

        $this->listado($actor)->assertOk()->assertJsonPath('meta.total', 24)->assertJsonCount(20, 'data');

        $respuesta = $this->listado($actor, '?estado=en_proceso')->assertOk();
        $respuesta->assertJsonPath('meta.total', 3)->assertJsonPath('meta.last_page', 1);
        $this->assertEqualsCanonicalizing($enProceso->pluck('id')->all(), collect($respuesta->json('data'))->pluck('id')->all());
        $this->assertSame(['en_proceso'], collect($respuesta->json('data'))->pluck('estado.codigo')->unique()->values()->all());
    }

    public function test_un_estado_invalido_o_borrador_responde_422(): void
    {
        $actor = $this->seleccionador($this->ficha());

        $this->listado($actor, '?estado=borrador')->assertUnprocessable()->assertJsonValidationErrors('estado');
        $this->listado($actor, '?estado=inexistente')->assertUnprocessable()->assertJsonValidationErrors('estado');
    }

    public function test_el_filtro_de_estado_respeta_el_alcance_por_ficha(): void
    {
        $propia = $this->ficha('2896411');
        $ajena = $this->ficha('2896412');
        $mia = $this->convocatoria($propia, 'en_proceso');
        $this->convocatoria($ajena, 'en_proceso');

        $ids = collect($this->listado($this->seleccionador($propia), '?estado=en_proceso')->assertOk()->json('data'))->pluck('id')->all();

        $this->assertSame([$mia->id], $ids);
    }

    public function test_una_convocatoria_por_id_se_resuelve_fuera_de_la_primera_pagina_y_solo_dentro_del_alcance(): void
    {
        $propia = $this->ficha('2896411');
        $ajena = $this->ficha('2896412');
        $vieja = $this->convocatoria($propia);
        foreach (range(1, 21) as $_) {
            $this->convocatoria($propia, 'publicada');
        }
        $deOtra = $this->convocatoria($ajena);
        $actor = $this->seleccionador($propia);

        $this->assertNotContains($vieja->id, collect($this->listado($actor)->json('data'))->pluck('id')->all());
        $this->assertSame([$vieja->id], collect($this->listado($actor, "?convocatoria={$vieja->id}")->assertOk()->json('data'))->pluck('id')->all());
        $this->assertSame([], $this->listado($actor, "?convocatoria={$deOtra->id}")->assertOk()->json('data'));
    }

    // ── Conteo de candidatos ───────────────────────────────────────────

    public function test_el_conteo_coincide_con_el_total_del_panel_de_candidatos_e_incluye_el_cero(): void
    {
        $ficha = $this->ficha();
        $actor = $this->seleccionador($ficha);
        $con = $this->convocatoria($ficha);
        foreach (range(1, 3) as $_) {
            $this->postulacion($con, 'postulada');
        }
        $this->postulacion($con, 'borrador');
        $this->postulacion($con, 'retirada');
        $sin = $this->convocatoria($ficha);

        $filas = collect($this->listado($actor)->assertOk()->json('data'))->keyBy('id');

        $this->assertSame(3, $filas[$con->id]['candidatos']);
        $this->assertSame(0, $filas[$sin->id]['candidatos']);
        $this->actingAs($actor)->getJson("/api/seleccion/convocatorias/{$con->id}/candidatos")
            ->assertOk()->assertJsonPath('meta.total', $filas[$con->id]['candidatos']);
    }

    public function test_el_conteo_no_incluye_postulaciones_de_otra_convocatoria_ni_de_otra_ficha(): void
    {
        $propia = $this->ficha('2896411');
        $ajena = $this->ficha('2896412');
        $mia = $this->convocatoria($propia);
        $this->postulacion($mia);
        $otra = $this->convocatoria($ajena);
        $this->postulacion($otra);
        $this->postulacion($otra);

        $filas = $this->listado($this->seleccionador($propia))->assertOk()->json('data');

        $this->assertCount(1, $filas);
        $this->assertSame(1, $filas[0]['candidatos']);
    }

    // ── Requiere atención ──────────────────────────────────────────────

    public function test_atencion_solo_trae_en_proceso_con_candidato_listo_y_sin_lista(): void
    {
        $ficha = $this->ficha();
        $actor = $this->seleccionador($ficha);

        $sinCandidatos = $this->convocatoria($ficha);
        $this->sinActividades($sinCandidatos);

        $ningunoListo = $this->convocatoria($ficha);
        $this->conEntrevista($ningunoListo);
        $this->habilitado($ningunoListo); // habilitado, pero falta la nota de la entrevista

        $sinConfigurar = $this->convocatoria($ficha);
        $this->habilitado($sinConfigurar); // configuración pendiente: nadie está listo

        $conLista = $this->convocatoria($ficha);
        $this->sinActividades($conLista);
        $this->habilitado($conLista);
        $this->confirmarLista($conLista, $actor);

        $publicada = $this->convocatoria($ficha, 'publicada');
        $this->sinActividades($publicada);
        $this->habilitado($publicada);

        $pendiente = $this->convocatoria($ficha);
        $this->sinActividades($pendiente);
        $this->habilitado($pendiente);
        $this->habilitado($pendiente);
        $this->postulacion($pendiente); // sin evaluación: candidato, pero no listo

        $respuesta = $this->listado($actor, '?atencion=1')->assertOk();

        $this->assertSame([$pendiente->id], collect($respuesta->json('data'))->pluck('id')->all());
        $respuesta->assertJsonPath('data.0.candidatos', 3)
            ->assertJsonPath('data.0.listos', 2)
            ->assertJsonPath('meta.hay_mas', false)
            ->assertJsonPath('meta.limite', 4);
    }

    public function test_atencion_encuentra_un_pendiente_que_no_esta_en_la_primera_pagina_del_listado(): void
    {
        $ficha = $this->ficha();
        $actor = $this->seleccionador($ficha);
        $vieja = $this->convocatoria($ficha);
        $this->sinActividades($vieja);
        $this->habilitado($vieja);
        foreach (range(1, 21) as $_) {
            $this->convocatoria($ficha, 'publicada');
        }

        $general = collect($this->listado($actor)->assertOk()->json('data'))->pluck('id');
        $this->assertNotContains($vieja->id, $general->all());

        $this->assertSame([$vieja->id], collect($this->listado($actor, '?atencion=1')->assertOk()->json('data'))->pluck('id')->all());
    }

    public function test_atencion_se_limita_a_cuatro_e_indica_si_hay_mas(): void
    {
        $ficha = $this->ficha();
        $actor = $this->seleccionador($ficha);
        $creadas = collect(range(1, 5))->map(function () use ($ficha) {
            $c = $this->convocatoria($ficha);
            $this->sinActividades($c);
            $this->habilitado($c);

            return $c;
        });

        $respuesta = $this->listado($actor, '?atencion=1')->assertOk();

        $this->assertCount(4, $respuesta->json('data'));
        $respuesta->assertJsonPath('meta.hay_mas', true);
        // De más reciente a más antigua: la más vieja queda fuera.
        $this->assertSame($creadas->reverse()->take(4)->pluck('id')->values()->all(), collect($respuesta->json('data'))->pluck('id')->all());
    }

    public function test_atencion_respeta_el_alcance_por_ficha(): void
    {
        $propia = $this->ficha('2896411');
        $ajena = $this->ficha('2896412');
        $c = $this->convocatoria($ajena);
        $this->sinActividades($c);
        $this->habilitado($c);

        $this->assertSame([], $this->listado($this->seleccionador($propia), '?atencion=1')->assertOk()->json('data'));
        $this->assertCount(1, $this->listado($this->seleccionador($ajena), '?atencion=1')->json('data'));
    }
}
