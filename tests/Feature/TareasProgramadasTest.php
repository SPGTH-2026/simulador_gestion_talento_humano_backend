<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Disparador externo de tareas programadas: solo con el token correcto, solo tareas de la lista cerrada.
 */
class TareasProgramadasTest extends TestCase
{
    private const TOKEN = 'token-de-prueba-largo-y-aleatorio-0123456789';

    protected function setUp(): void
    {
        parent::setUp();
        config(['tareas.cron_token' => self::TOKEN]);
    }

    public function test_sin_token_configurado_el_endpoint_no_existe(): void
    {
        config(['tareas.cron_token' => null]);

        $this->postJson('/api/tareas/cerrar-vencidas')->assertNotFound();
    }

    public function test_sin_cabecera_de_autorizacion_responde_401(): void
    {
        $this->postJson('/api/tareas/cerrar-vencidas')->assertUnauthorized();
    }

    public function test_token_incorrecto_responde_401_y_no_ejecuta_nada(): void
    {
        Artisan::shouldReceive('call')->never();

        $this->withHeaders(['Authorization' => 'Bearer token-equivocado'])
            ->postJson('/api/tareas/cerrar-vencidas')
            ->assertUnauthorized();
    }

    public function test_el_token_en_la_url_no_sirve(): void
    {
        Artisan::shouldReceive('call')->never();

        $this->postJson('/api/tareas/cerrar-vencidas?token='.self::TOKEN)->assertUnauthorized();
    }

    public function test_una_tarea_fuera_de_la_lista_no_se_ejecuta_aunque_el_token_sea_bueno(): void
    {
        Artisan::shouldReceive('call')->never();

        $this->withHeaders(['Authorization' => 'Bearer '.self::TOKEN])
            ->postJson('/api/tareas/borrar-todo')
            ->assertNotFound();
    }

    public function test_solo_acepta_post(): void
    {
        $this->withHeaders(['Authorization' => 'Bearer '.self::TOKEN])
            ->getJson('/api/tareas/cerrar-vencidas')
            ->assertStatus(405);
    }

    public function test_con_el_token_correcto_ejecuta_el_cierre_de_convocatorias(): void
    {
        Artisan::shouldReceive('call')
            ->once()
            ->with('convocatorias:cerrar-vencidas')
            ->andReturn(0);

        $this->withHeaders(['Authorization' => 'Bearer '.self::TOKEN])
            ->postJson('/api/tareas/cerrar-vencidas')
            ->assertOk()
            ->assertJsonPath('data.tarea', 'cerrar-vencidas')
            ->assertJsonPath('data.ok', true);
    }
}
