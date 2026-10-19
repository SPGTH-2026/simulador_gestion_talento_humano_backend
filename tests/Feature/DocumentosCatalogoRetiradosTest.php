<?php

namespace App\Tests\Feature;

use App\Enums\Role;
use App\Models\Ficha;
use App\Models\User;
use Database\Seeders\ParametroSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Tests\TestCase;

/**
 * Catálogo del tema tipo_documento tras retirar definitivamente titulos,
 * soportes_laborales y certificacion_competencias.
 *
 *  - El catálogo vigente son exactamente 6 documentos.
 *  - Un código que ya no está en el catálogo recibe el error genérico actual.
 */
class DocumentosCatalogoRetiradosTest extends TestCase
{
    use RefreshDatabase;

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

    // ── Pruebas ────────────────────────────────────────────────────────

    public function test_get_parametros_tipo_documento_devuelve_exactamente_los_6_en_orden(): void
    {
        $data = $this->actingAs($this->usuario(Role::Aspirante))
            ->getJson('/api/parametros?tema=tipo_documento')
            ->assertOk()
            ->json('data');

        $this->assertSame(
            [
                'documento_identidad',
                'hoja_de_vida',
                'certificados_academicos',
                'certificados_laborales',
                'libreta_militar',
                'tarjeta_profesional',
            ],
            array_column($data, 'codigo'),
        );
    }

    public function test_un_codigo_retirado_recibe_el_error_generico(): void
    {
        $instructor = $this->instructor($this->ficha());

        $respuesta = $this->actingAs($instructor)->postJson('/api/convocatorias', [
            'nombre' => 'Con código retirado',
            'documentos' => ['titulos'],
        ])->assertStatus(422);

        $this->assertContains(
            'El documento seleccionado no es válido.',
            Arr::flatten($respuesta->json('errors')),
        );
    }
}
