<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\CorreccionAutorizacion;
use App\Models\Ficha;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\RondasCorreccionAyudas;
use Tests\TestCase;

/** Rondas de corrección: autorizacion. Escenario y utilidades en RondasCorreccionAyudas. */
class RondasAutorizacionTest extends TestCase
{
    use RefreshDatabase;
    use RondasCorreccionAyudas;

    public function test_la_excepcion_solo_procede_tras_vencimiento_o_agotamiento(): void
    {
        // Sin ninguna ronda todavía.
        $this->autorizar($this->instructor)->assertStatus(422);

        // Con una ronda vigente.
        $this->pedir($this->evaluador)->assertOk();
        $this->autorizar($this->instructor)->assertStatus(422);

        // Respondida y aún quedan ordinarias.
        $this->responder()->assertOk();
        $this->autorizar($this->instructor)->assertStatus(422);
        $this->assertSame(0, CorreccionAutorizacion::count());
    }

    public function test_solo_instructor_de_la_ficha_o_superadmin_autorizan_con_justificacion_valida(): void
    {
        $this->pedir($this->evaluador)->assertOk();
        $this->vencer($this->rondas()->sole());

        $this->autorizar($this->evaluador)->assertForbidden();

        $otraFicha = Ficha::create(['codigo' => '4999999', 'nombre_programa' => 'Otra']);
        $ajeno = User::factory()->create(['role' => Role::Instructor, 'active' => true, 'email_verified_at' => now(), 'ficha_id' => $otraFicha->id]);
        $this->assertContains($this->autorizar($ajeno)->status(), [403, 404]);

        $this->autorizar($this->instructor, 'corta')->assertStatus(422);
        $this->autorizar($this->instructor, str_repeat('x', 501))->assertStatus(422);

        $this->autorizar($this->instructor, str_repeat('x', 10))->assertCreated()
            ->assertJsonPath('data.correccion.autorizacion.autorizada_por.id', $this->instructor->id);

        // Una sola autorización sin usar por postulación, aunque la pida el Superadmin.
        $super = User::factory()->create(['role' => Role::SuperAdmin, 'active' => true, 'email_verified_at' => now()]);
        $this->autorizar($super)->assertStatus(422);
        $this->assertSame(1, CorreccionAutorizacion::count());
    }

    public function test_el_superadmin_autoriza_en_cualquier_ficha(): void
    {
        $this->pedir($this->evaluador)->assertOk();
        $this->vencer($this->rondas()->sole());
        $super = User::factory()->create(['role' => Role::SuperAdmin, 'active' => true, 'email_verified_at' => now()]);

        $this->autorizar($super)->assertCreated();
        $this->assertSame($super->id, CorreccionAutorizacion::sole()->autorizada_por);
    }

    public function test_la_ronda_excepcional_tiene_48_horas_nuevas_no_consume_ordinaria_y_gasta_la_autorizacion(): void
    {
        $this->pedir($this->evaluador)->assertOk();
        $this->vencer($this->rondas()->sole());
        $this->autorizar($this->instructor)->assertCreated();

        $resumen = $this->resumen();
        $this->assertTrue($resumen['puede_solicitar']);
        $this->assertSame('excepcional', $resumen['motivo']);
        $this->assertSame('excepcional', $resumen['tipo_siguiente']);

        $this->pedir($this->evaluador, 'Excepcional.')->assertOk();

        $excepcional = $this->rondas()->last();
        $this->assertSame('excepcional', $excepcional->tipo);
        $this->assertSame(1, $excepcional->numero);
        $this->assertTrue($excepcional->fecha_limite->equalTo($excepcional->solicitada_at->copy()->addHours(48)));
        $this->assertSame(1, $this->resumen()['ordinarias_usadas'], 'no consume una ordinaria');

        $autorizacion = CorreccionAutorizacion::sole();
        $this->assertNull($autorizacion->sin_usar);
        $this->assertSame($excepcional->id, $autorizacion->usada_en_correccion_id);
        $this->assertSame($autorizacion->id, $excepcional->autorizacion_id);

        // Respondida la excepcional, sin autorización nueva no se puede volver a pedir.
        $this->responder()->assertOk();
        $this->pedir($this->evaluador, 'Otra más.')->assertStatus(422);
    }

    public function test_el_instructor_lee_un_motivo_dirigido_a_el_y_el_evaluador_el_de_siempre(): void
    {
        $this->pedir($this->evaluador)->assertOk();
        $this->vencer($this->rondas()->sole());

        $paraInstructor = $this->resumen($this->instructor);
        $this->assertTrue($paraInstructor['puede_autorizar']);
        $this->assertStringContainsString('Puedes autorizar una ronda excepcional', $paraInstructor['mensaje']);
        $this->assertStringNotContainsString('pedir una autorización excepcional al Instructor', $paraInstructor['mensaje']);

        $paraEvaluador = $this->resumen($this->evaluador);
        $this->assertFalse($paraEvaluador['puede_autorizar']);
        $this->assertStringContainsString('pedir una autorización excepcional al Instructor', $paraEvaluador['mensaje']);
    }
}
