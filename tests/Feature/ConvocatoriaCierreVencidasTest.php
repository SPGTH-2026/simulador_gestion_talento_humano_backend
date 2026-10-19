<?php

namespace App\Tests\Feature;

use App\Enums\Role;
use App\Models\Convocatoria;
use App\Models\Ficha;
use App\Models\Parametro;
use App\Models\User;
use Database\Seeders\ParametroSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pruebas del cierre automático de convocatorias vencidas
 * (`convocatorias:cerrar-vencidas`): una convocatoria publicada cuya fecha de
 * cierre ya pasó debe avanzar a en_proceso y dejar de mostrarse al aspirante,
 * sin que el resto de estados se toque.
 */
class ConvocatoriaCierreVencidasTest extends TestCase
{
    use RefreshDatabase;

    private static int $codigoSecuencia = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ParametroSeeder::class);
    }

    // ── Utilidades ─────────────────────────────────────────────────────

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

    private function aspirante(Ficha $ficha): User
    {
        return $this->usuario(Role::Aspirante, ['ficha_id' => $ficha->id]);
    }

    private function parametroId(string $tema, string $codigo): int
    {
        $parametro = Parametro::porTemaYCodigo($tema, $codigo);
        $this->assertNotNull($parametro, "Falta el parámetro {$tema}.{$codigo}");

        return (int) $parametro->id;
    }

    private function crearConvocatoria(Ficha $ficha, User $creador, array $overrides = []): Convocatoria
    {
        return Convocatoria::unguarded(fn () => Convocatoria::create($overrides + [
            'ficha_id' => $ficha->id,
            'codigo' => sprintf('CONV-TEST-%03d', ++self::$codigoSecuencia),
            'nombre' => 'Convocatoria base',
            'vacantes' => 1,
            'tipo_convocatoria_id' => $this->parametroId('tipo_convocatoria', 'externo'),
            'tipo_empleo_id' => $this->parametroId('tipo_empleo', 'tiempo_completo'),
            'tipo_contrato_id' => $this->parametroId('tipo_contrato', 'termino_fijo'),
            'modalidad_id' => $this->parametroId('modalidad', 'presencial'),
            'estado_id' => $this->parametroId('estado_convocatoria', 'publicada'),
            'creado_por' => $creador->id,
        ]));
    }

    /** Una convocatoria en el estado dado y con la fecha de cierre indicada. */
    private function conEstadoYCierre(Ficha $ficha, User $creador, string $estado, string $cierre): Convocatoria
    {
        return $this->crearConvocatoria($ficha, $creador, [
            'estado_id' => $this->parametroId('estado_convocatoria', $estado),
            'fecha_apertura' => now('America/Bogota')->subDays(10)->toDateString(),
            'fecha_cierre' => $cierre,
        ]);
    }

    private function hoy(): string
    {
        return now('America/Bogota')->toDateString();
    }

    // ── Cierre automático ──────────────────────────────────────────────

    public function test_publicada_con_fecha_cierre_vencida_pasa_a_en_proceso(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->conEstadoYCierre($ficha, $instructor, 'publicada', now('America/Bogota')->subDay()->toDateString());

        $this->artisan('convocatorias:cerrar-vencidas')->assertSuccessful();

        $this->assertSame('en_proceso', $convocatoria->fresh()->estado?->codigo);
    }

    public function test_publicada_con_cierre_hoy_no_cambia(): void
    {
        // El día de cierre sigue abierto: los extremos son inclusivos.
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->conEstadoYCierre($ficha, $instructor, 'publicada', $this->hoy());

        $this->artisan('convocatorias:cerrar-vencidas')->assertSuccessful();

        $this->assertSame('publicada', $convocatoria->fresh()->estado?->codigo);
    }

    public function test_publicada_con_cierre_futuro_no_cambia(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->conEstadoYCierre($ficha, $instructor, 'publicada', now('America/Bogota')->addDays(5)->toDateString());

        $this->artisan('convocatorias:cerrar-vencidas')->assertSuccessful();

        $this->assertSame('publicada', $convocatoria->fresh()->estado?->codigo);
    }

    public function test_borrador_vencido_no_cambia(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->conEstadoYCierre($ficha, $instructor, 'borrador', now('America/Bogota')->subDay()->toDateString());

        $this->artisan('convocatorias:cerrar-vencidas')->assertSuccessful();

        $this->assertSame('borrador', $convocatoria->fresh()->estado?->codigo);
    }

    public function test_en_proceso_y_cerrada_no_cambian(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);

        $enProceso = $this->conEstadoYCierre($ficha, $instructor, 'en_proceso', now('America/Bogota')->subDay()->toDateString());
        $cerrada = $this->conEstadoYCierre($ficha, $instructor, 'cerrada', now('America/Bogota')->subDay()->toDateString());

        $this->artisan('convocatorias:cerrar-vencidas')->assertSuccessful();

        $this->assertSame('en_proceso', $enProceso->fresh()->estado?->codigo);
        $this->assertSame('cerrada', $cerrada->fresh()->estado?->codigo);
    }

    public function test_dry_run_no_guarda_cambios(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->conEstadoYCierre($ficha, $instructor, 'publicada', now('America/Bogota')->subDay()->toDateString());

        $this->artisan('convocatorias:cerrar-vencidas', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame('publicada', $convocatoria->fresh()->estado?->codigo);
    }

    public function test_es_idempotente(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->conEstadoYCierre($ficha, $instructor, 'publicada', now('America/Bogota')->subDay()->toDateString());

        $this->artisan('convocatorias:cerrar-vencidas')->assertSuccessful();
        $this->artisan('convocatorias:cerrar-vencidas')->assertSuccessful();

        $this->assertSame('en_proceso', $convocatoria->fresh()->estado?->codigo);
    }

    public function test_el_aspirante_deja_de_ver_la_convocatoria_cerrada(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);

        $this->conEstadoYCierre($ficha, $instructor, 'publicada', now('America/Bogota')->subDay()->toDateString());

        $antes = $this->actingAs($aspirante)->getJson('/api/convocatorias')->assertOk();
        $this->assertCount(1, $antes->json('data'));

        $this->artisan('convocatorias:cerrar-vencidas')->assertSuccessful();

        $despues = $this->actingAs($aspirante)->getJson('/api/convocatorias')->assertOk();
        $this->assertCount(0, $despues->json('data'));
    }
}
