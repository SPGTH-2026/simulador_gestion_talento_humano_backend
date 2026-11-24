<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\TipoNotificacion;
use App\Models\Ficha;
use App\Models\Notificacion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificacionesListadoTest extends TestCase
{
    use RefreshDatabase;

    private function aviso(User $usuario, string $titulo, ?string $url = null): Notificacion
    {
        return Notificacion::create([
            'user_id' => $usuario->id,
            'tipo' => TipoNotificacion::CorreccionSolicitada,
            'titulo' => $titulo,
            'mensaje' => "Mensaje de {$titulo}",
            'url' => $url,
        ]);
    }

    private function usuario(): User
    {
        // Un aspirante sin ficha no entra a la API (middleware «ficha»): cada uno recibe la suya.
        $ficha = Ficha::create(['codigo' => (string) random_int(1000000, 9999999), 'nombre_programa' => 'ADSO']);

        return User::factory()->create([
            'role' => Role::Aspirante,
            'ficha_id' => $ficha->id,
            'active' => true,
            'email_verified_at' => now(),
        ]);
    }

    public function test_lista_solo_las_propias_con_el_conteo_de_no_leidas(): void
    {
        $yo = $this->usuario();
        $otro = $this->usuario();

        $this->aviso($yo, 'Primera');
        $leida = $this->aviso($yo, 'Leída');
        $leida->forceFill(['leida_at' => now()])->save();
        $this->aviso($otro, 'Ajena');

        $this->actingAs($yo)->getJson('/api/notificaciones')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.no_leidas', 1)
            ->assertJsonMissing(['titulo' => 'Ajena']);
    }

    public function test_la_mas_reciente_va_primero_y_la_presentacion_incluye_tipo_y_url(): void
    {
        $yo = $this->usuario();
        $this->aviso($yo, 'Antigua', '/mis-postulaciones');
        $this->aviso($yo, 'Nueva', '/mis-evaluaciones');

        $this->actingAs($yo)->getJson('/api/notificaciones')
            ->assertOk()
            ->assertJsonPath('data.0.titulo', 'Nueva')
            ->assertJsonPath('data.0.url', '/mis-evaluaciones')
            ->assertJsonPath('data.0.tipo', 'correccion_solicitada')
            ->assertJsonPath('data.0.leida', false);
    }

    public function test_marcar_leida_fija_la_fecha_y_repetirlo_no_la_cambia(): void
    {
        $yo = $this->usuario();
        $aviso = $this->aviso($yo, 'Una');

        $this->actingAs($yo)->postJson("/api/notificaciones/{$aviso->id}/leer")
            ->assertOk()
            ->assertJsonPath('data.leida', true);

        $primera = $aviso->fresh()->leida_at;
        $this->assertNotNull($primera);

        $this->travel(5)->minutes();
        $this->actingAs($yo)->postJson("/api/notificaciones/{$aviso->id}/leer")->assertOk();

        $this->assertTrue($primera->equalTo($aviso->fresh()->leida_at));
    }

    public function test_no_puede_marcar_una_notificacion_ajena(): void
    {
        $yo = $this->usuario();
        $ajena = $this->aviso($this->usuario(), 'Ajena');

        $this->actingAs($yo)->postJson("/api/notificaciones/{$ajena->id}/leer")->assertNotFound();

        $this->assertNull($ajena->fresh()->leida_at);
    }

    public function test_leer_todas_marca_solo_las_propias_pendientes(): void
    {
        $yo = $this->usuario();
        $otro = $this->usuario();

        $this->aviso($yo, 'A');
        $this->aviso($yo, 'B');
        $ajena = $this->aviso($otro, 'Ajena');

        $this->actingAs($yo)->postJson('/api/notificaciones/leer-todas')
            ->assertOk()
            ->assertJsonPath('data.marcadas', 2);

        $this->assertDatabaseMissing('notificaciones', ['user_id' => $yo->id, 'leida_at' => null]);
        $this->assertNull($ajena->fresh()->leida_at);
    }

    public function test_sin_sesion_no_se_puede_consultar(): void
    {
        $this->getJson('/api/notificaciones')->assertUnauthorized();
    }
}
