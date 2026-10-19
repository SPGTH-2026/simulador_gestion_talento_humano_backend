<?php

namespace App\Tests\Feature;

use App\Models\Ficha;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Paso 1: el DatabaseSeeder liga los usuarios demo a la ficha base
 * (3173334) sin duplicar: aspirante y aprendices (incluido el gestor) por
 * users.ficha_id; el instructor por el pivote ficha_instructor; el
 * super_admin sin ficha. Solo corre en local/no producción.
 */
class FichasDemoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_aspirante_y_aprendices_quedan_ligados_por_ficha_id(): void
    {
        $ficha = Ficha::where('codigo', '3173334')->firstOrFail();

        foreach ([
            'aspirante@test.com',
            'general@test.com',
            'evaluador@test.com',
            'seleccionador@test.com',
            'revisor@test.com',
            'gestor@test.com',
        ] as $email) {
            $this->assertSame(
                $ficha->id,
                User::where('email', $email)->firstOrFail()->ficha_id,
                "{$email} debe quedar ligado a la ficha {$ficha->codigo}."
            );
        }
    }

    public function test_instructor_queda_ligado_por_el_pivote_y_admin_sin_ficha(): void
    {
        $ficha = Ficha::where('codigo', '3173334')->firstOrFail();

        $instructor = User::where('email', 'instructor@test.com')->firstOrFail();
        $this->assertContains($ficha->id, $instructor->fichas()->pluck('fichas.id')->all());
        $this->assertNull($instructor->ficha_id);

        $this->assertNull(User::where('email', 'admin@test.com')->firstOrFail()->ficha_id);
    }

    public function test_el_seeder_de_demo_es_idempotente_y_no_duplica(): void
    {
        $this->seed(DatabaseSeeder::class);

        $ficha = Ficha::where('codigo', '3173334')->firstOrFail();

        $this->assertSame(1, Ficha::where('codigo', '3173334')->count());
        $this->assertSame(
            1,
            $ficha->instructores()->where('users.email', 'instructor@test.com')->count()
        );
        $this->assertSame(
            1,
            User::where('email', 'aspirante@test.com')->where('ficha_id', $ficha->id)->count()
        );
    }
}
