<?php

// Retención: las ocultas y las leídas se eliminan a los 30 días; las no leídas, nunca. Todo con avisos ficticios.

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\TipoNotificacion;
use App\Models\Ficha;
use App\Models\Notificacion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DepurarNotificacionesTest extends TestCase
{
    use RefreshDatabase;

    private function usuario(): User
    {
        $ficha = Ficha::create(['codigo' => (string) random_int(1000000, 9999999), 'nombre_programa' => 'ADSO']);

        return User::factory()->create(['role' => Role::Aspirante, 'ficha_id' => $ficha->id, 'active' => true, 'email_verified_at' => now()]);
    }

    private function aviso(User $u, string $titulo, ?int $leidaHaceDias = null, ?int $ocultaHaceDias = null): Notificacion
    {
        $n = Notificacion::create(['user_id' => $u->id, 'tipo' => TipoNotificacion::Citacion, 'titulo' => $titulo, 'mensaje' => 'm', 'url' => null]);
        $n->forceFill([
            'created_at' => now()->subDays(200),
            'leida_at' => $leidaHaceDias === null ? null : now()->subDays($leidaHaceDias),
            'ocultada_at' => $ocultaHaceDias === null ? null : now()->subDays($ocultaHaceDias),
        ])->save();

        return $n;
    }

    private function quedan(): array
    {
        return Notificacion::orderBy('id')->pluck('titulo')->all();
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['notificaciones.depuracion_activa' => true]);
    }

    public function test_elimina_las_ocultas_y_las_leidas_con_30_dias_o_mas_y_conserva_el_resto(): void
    {
        $u = $this->usuario();
        $this->aviso($u, 'Sin leer muy vieja');
        $this->aviso($u, 'Leída 29 días', leidaHaceDias: 29);
        $this->aviso($u, 'Leída 30 días', leidaHaceDias: 30);
        $this->aviso($u, 'Leída 90 días', leidaHaceDias: 90);
        $this->aviso($u, 'Oculta 29 días', ocultaHaceDias: 29);
        $this->aviso($u, 'Oculta 30 días', ocultaHaceDias: 30);
        $this->aviso($u, 'Oculta sin leer 31 días', ocultaHaceDias: 31);

        $this->artisan('notificaciones:depurar')->expectsOutput('2 oculta(s) y 2 leída(s) eliminadas.')->assertSuccessful();

        $this->assertSame(['Sin leer muy vieja', 'Leída 29 días', 'Oculta 29 días'], $this->quedan());
    }

    public function test_una_oculta_se_rige_por_la_fecha_en_que_se_oculto_no_por_la_de_lectura(): void
    {
        $u = $this->usuario();
        $this->aviso($u, 'Leída hace 90 y oculta hace 5', leidaHaceDias: 90, ocultaHaceDias: 5);
        $this->aviso($u, 'Leída hace 5 y oculta hace 40', leidaHaceDias: 5, ocultaHaceDias: 40);

        $this->artisan('notificaciones:depurar')->assertSuccessful();

        $this->assertSame(['Leída hace 90 y oculta hace 5'], $this->quedan());
    }

    public function test_es_idempotente_trabaja_por_lotes_y_los_plazos_salen_de_la_configuracion(): void
    {
        $u = $this->usuario();
        foreach (range(1, 5) as $i) {
            $this->aviso($u, "Vieja {$i}", leidaHaceDias: 40);
        }
        $this->aviso($u, 'Leída 10 días', leidaHaceDias: 10);

        $this->artisan('notificaciones:depurar')->expectsOutput('0 oculta(s) y 5 leída(s) eliminadas.')->assertSuccessful();
        $this->artisan('notificaciones:depurar')->expectsOutput('0 oculta(s) y 0 leída(s) eliminadas.')->assertSuccessful();
        $this->assertSame(['Leída 10 días'], $this->quedan());

        config(['notificaciones.dias_leidas' => 7]);
        $this->artisan('notificaciones:depurar')->assertSuccessful();
        $this->assertSame([], $this->quedan());
    }

    public function test_desactivada_por_defecto_no_elimina_nada_ni_se_programa_y_la_api_no_anuncia_plazos(): void
    {
        config(['notificaciones.depuracion_activa' => false]);
        $u = $this->usuario();
        $this->aviso($u, 'Oculta vieja', ocultaHaceDias: 90);
        $this->aviso($u, 'Leída vieja', leidaHaceDias: 90);

        $this->artisan('notificaciones:depurar')->assertSuccessful();
        $this->assertSame(['Oculta vieja', 'Leída vieja'], $this->quedan());

        $evento = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'notificaciones:depurar'));
        $this->assertFalse($evento->filtersPass($this->app));
        $this->actingAs($u)->getJson('/api/notificaciones')->assertOk()->assertJsonPath('meta.retencion', null);

        config(['notificaciones.depuracion_activa' => true]);
        $this->assertTrue($evento->filtersPass($this->app));
        $this->actingAs($u)->getJson('/api/notificaciones?estado=ocultas')->assertJsonPath('meta.retencion.ocultas_dias', 30)->assertJsonPath('meta.retencion.leidas_dias', 30);
    }

    public function test_esta_programado_a_diario_y_se_puede_disparar_desde_el_disparador_externo(): void
    {
        $tareas = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())->pluck('command')->implode(' ');
        $this->assertStringContainsString('notificaciones:depurar', $tareas);

        config(['tareas.cron_token' => 'token-de-prueba']);
        $this->withHeader('Authorization', 'Bearer token-de-prueba')->postJson('/api/tareas/depurar-notificaciones')->assertOk()->assertJsonPath('data.ok', true);
    }
}
