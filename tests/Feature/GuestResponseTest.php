<?php

// Backend solo-API: ninguna petición a /api debe acabar en un redirect a una
// ruta web 'login' (no existe) con 500. Un invitado debe recibir 401 JSON.

namespace Tests\Feature;

use Tests\TestCase;

class GuestResponseTest extends TestCase
{
    public function test_invitado_en_api_recibe_401_y_no_500(): void
    {
        // Sin cabecera Accept: application/json, a propósito.
        $this->get('/api/auth/me')->assertStatus(401);
    }

    public function test_invitado_con_accept_json_recibe_401(): void
    {
        $this->get('/api/auth/me', ['Accept' => 'application/json'])
            ->assertStatus(401)
            ->assertJson(['message' => 'Unauthenticated.']);
    }
}
