<?php

// Filtros del historial en el servidor y ocultar/restaurar (no borra ni cambia la lectura). Todo con cuentas y avisos ficticios.

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\TipoNotificacion;
use App\Models\Ficha;
use App\Models\Notificacion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificacionesOcultarFiltrosTest extends TestCase
{
    use RefreshDatabase;

    private function usuario(): User
    {
        $ficha = Ficha::create(['codigo' => (string) random_int(1000000, 9999999), 'nombre_programa' => 'ADSO']);

        return User::factory()->create(['role' => Role::Aspirante, 'ficha_id' => $ficha->id, 'active' => true, 'email_verified_at' => now()]);
    }

    private function aviso(User $u, string $titulo, bool $leida = false, bool $oculta = false): Notificacion
    {
        $n = Notificacion::create(['user_id' => $u->id, 'tipo' => TipoNotificacion::Citacion, 'titulo' => $titulo, 'mensaje' => "Mensaje de {$titulo}", 'url' => '/mis-postulaciones']);
        $n->forceFill(['leida_at' => $leida ? now() : null, 'ocultada_at' => $oculta ? now() : null])->save();

        return $n;
    }

    private function titulos(User $u, string $query = ''): array
    {
        return $this->actingAs($u)->getJson('/api/notificaciones'.$query)->assertOk()->json('data.*.titulo');
    }

    private function escenario(): User
    {
        $yo = $this->usuario();
        $this->aviso($yo, 'Nueva 1');
        $this->aviso($yo, 'Leída 1', leida: true);
        $this->aviso($yo, 'Nueva 2');
        $this->aviso($yo, 'Oculta sin leer', oculta: true);
        $this->aviso($yo, 'Oculta leída', leida: true, oculta: true);

        return $yo;
    }

    public function test_sin_filtro_sigue_como_antes_y_no_incluye_las_ocultas_ni_las_cuenta_como_pendientes(): void
    {
        $yo = $this->escenario();
        $res = $this->actingAs($yo)->getJson('/api/notificaciones')->assertOk();
        $this->assertEqualsCanonicalizing(['Nueva 1', 'Leída 1', 'Nueva 2'], $res->json('data.*.titulo'));
        $res->assertJsonPath('meta.total', 3)->assertJsonPath('meta.no_leidas', 2)->assertJsonPath('meta.ocultas', 2);
        $this->assertSame([false, false, false], $res->json('data.*.oculta'));
    }

    public function test_cada_filtro_se_aplica_en_el_servidor_con_su_propia_paginacion(): void
    {
        $yo = $this->escenario();
        $this->assertEqualsCanonicalizing(['Nueva 1', 'Nueva 2'], $this->titulos($yo, '?estado=no_leidas'));
        $this->assertSame(['Leída 1'], $this->titulos($yo, '?estado=leidas'));
        $this->assertEqualsCanonicalizing(['Oculta sin leer', 'Oculta leída'], $this->titulos($yo, '?estado=ocultas'));
        // El contador de pendientes no depende del filtro.
        foreach (['todas', 'no_leidas', 'leidas', 'ocultas'] as $e) {
            $this->actingAs($yo)->getJson("/api/notificaciones?estado={$e}")->assertJsonPath('meta.no_leidas', 2);
        }
        // Paginación real sobre el conjunto filtrado (no sobre lo cargado).
        $p = $this->actingAs($yo)->getJson('/api/notificaciones?estado=no_leidas&per_page=1&page=2')->assertOk();
        $p->assertJsonPath('meta.total', 2)->assertJsonPath('meta.last_page', 2)->assertJsonCount(1, 'data');
        $this->actingAs($yo)->getJson('/api/notificaciones?estado=otro')->assertUnprocessable()->assertJsonValidationErrors('estado');
    }

    public function test_ocultar_conserva_la_lectura_baja_el_contador_y_es_idempotente(): void
    {
        $yo = $this->usuario();
        $sinLeer = $this->aviso($yo, 'Sin leer');
        $leida = $this->aviso($yo, 'Leída', leida: true);

        $r = $this->actingAs($yo)->postJson("/api/notificaciones/{$sinLeer->id}/ocultar")->assertOk()->assertJsonPath('data.oculta', true)->assertJsonPath('data.leida', false);
        $primera = $sinLeer->fresh()->ocultada_at;
        $this->travel(5)->minutes();
        $this->actingAs($yo)->postJson("/api/notificaciones/{$sinLeer->id}/ocultar")->assertOk();
        $this->assertEquals($primera, $sinLeer->fresh()->ocultada_at);
        $this->actingAs($yo)->postJson("/api/notificaciones/{$leida->id}/ocultar")->assertOk()->assertJsonPath('data.leida', true);

        $this->assertSame([], $this->titulos($yo));
        $this->actingAs($yo)->getJson('/api/notificaciones')->assertJsonPath('meta.no_leidas', 0)->assertJsonPath('meta.ocultas', 2);
        // No se borró nada.
        $this->assertSame(2, Notificacion::where('user_id', $yo->id)->count());
    }

    public function test_restaurar_devuelve_el_aviso_con_su_lectura_y_recalcula_el_contador(): void
    {
        $yo = $this->usuario();
        $n = $this->aviso($yo, 'Pendiente', oculta: true);
        $this->actingAs($yo)->getJson('/api/notificaciones')->assertJsonPath('meta.no_leidas', 0);

        $this->actingAs($yo)->postJson("/api/notificaciones/{$n->id}/restaurar")->assertOk()->assertJsonPath('data.oculta', false)->assertJsonPath('data.leida', false);
        $this->actingAs($yo)->postJson("/api/notificaciones/{$n->id}/restaurar")->assertOk();
        $this->actingAs($yo)->getJson('/api/notificaciones')->assertJsonPath('meta.no_leidas', 1)->assertJsonPath('meta.ocultas', 0);
    }

    public function test_leer_una_oculta_no_la_restaura_y_leer_no_la_oculta(): void
    {
        $yo = $this->usuario();
        $n = $this->aviso($yo, 'Oculta', oculta: true);
        $this->actingAs($yo)->postJson("/api/notificaciones/{$n->id}/leer")->assertOk()->assertJsonPath('data.leida', true)->assertJsonPath('data.oculta', true);
        $visible = $this->aviso($yo, 'Visible');
        $this->actingAs($yo)->postJson("/api/notificaciones/{$visible->id}/leer")->assertOk()->assertJsonPath('data.oculta', false);
        $this->assertSame(['Visible'], $this->titulos($yo, '?estado=leidas'));
    }

    public function test_leer_todas_solo_afecta_a_las_visibles_sin_leer(): void
    {
        $yo = $this->escenario();
        $this->actingAs($yo)->postJson('/api/notificaciones/leer-todas')->assertOk()->assertJsonPath('data.marcadas', 2);
        $this->assertEqualsCanonicalizing(['Oculta sin leer'], Notificacion::where('user_id', $yo->id)->ocultas()->noLeidas()->pluck('titulo')->all());
        $this->actingAs($yo)->getJson('/api/notificaciones')->assertJsonPath('meta.no_leidas', 0);
    }

    public function test_un_aviso_ajeno_responde_404_en_ocultar_y_restaurar_y_no_cambia_y_nadie_ve_los_ajenos(): void
    {
        $yo = $this->usuario();
        $otro = $this->usuario();
        $ajena = $this->aviso($otro, 'Ajena');
        $this->actingAs($yo)->postJson("/api/notificaciones/{$ajena->id}/ocultar")->assertNotFound();
        $this->actingAs($yo)->postJson("/api/notificaciones/{$ajena->id}/restaurar")->assertNotFound();
        $this->assertNull($ajena->fresh()->ocultada_at);
        foreach (['todas', 'no_leidas', 'leidas', 'ocultas'] as $e) {
            $this->assertSame([], $this->titulos($yo, "?estado={$e}"));
        }
    }

    public function test_sin_sesion_ocultar_y_restaurar_responden_401(): void
    {
        $n = $this->aviso($this->usuario(), 'Cualquiera');
        $this->postJson("/api/notificaciones/{$n->id}/ocultar")->assertUnauthorized();
        $this->postJson("/api/notificaciones/{$n->id}/restaurar")->assertUnauthorized();
    }
}
