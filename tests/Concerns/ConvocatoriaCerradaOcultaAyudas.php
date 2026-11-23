<?php

namespace Tests\Concerns;

use App\Enums\Role;
use App\Models\Convocatoria;
use App\Models\Ficha;
use App\Models\Parametro;
use App\Models\User;
use Database\Seeders\ParametroSeeder;
use Illuminate\Testing\TestResponse;

trait ConvocatoriaCerradaOcultaAyudas
{
    private static int $codigoSecuencia = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ParametroSeeder::class);
    }

    private function ficha(): Ficha
    {
        return Ficha::create([
            'codigo' => '3173334',
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

    private function instructor(Ficha $ficha): User
    {
        $instructor = $this->usuario(Role::Instructor);
        $instructor->fichas()->attach($ficha->id);

        return $instructor->fresh();
    }

    private function aspirante(Ficha $ficha): User
    {
        return $this->usuario(Role::Aspirante, ['ficha_id' => $ficha->id]);
    }

    private function aprendiz(Ficha $ficha, string $subrol): User
    {
        return $this->usuario(Role::Aprendiz, [
            'subrole' => $subrol,
            'ficha_id' => $ficha->id,
        ]);
    }

    private function parametroId(string $tema, string $codigo): int
    {
        return (int) Parametro::porTemaYCodigo($tema, $codigo)->id;
    }

    /** Convocatoria en el estado indicado, con la fecha de cierre dada (en días respecto a hoy). */
    private function convocatoria(Ficha $ficha, User $creador, string $estado, ?int $diasParaCierre): Convocatoria
    {
        $hoy = now('America/Bogota');

        return Convocatoria::unguarded(fn () => Convocatoria::create([
            'ficha_id' => $ficha->id,
            'codigo' => sprintf('CONV-CER-%03d', ++self::$codigoSecuencia),
            'nombre' => "Convocatoria {$estado} ".self::$codigoSecuencia,
            'vacantes' => 1,
            'tipo_convocatoria_id' => $this->parametroId('tipo_convocatoria', 'externo'),
            'tipo_empleo_id' => $this->parametroId('tipo_empleo', 'tiempo_completo'),
            'tipo_contrato_id' => $this->parametroId('tipo_contrato', 'termino_fijo'),
            'modalidad_id' => $this->parametroId('modalidad', 'presencial'),
            'estado_id' => $this->parametroId('estado_convocatoria', $estado),
            'fecha_apertura' => $hoy->copy()->subDays(10)->toDateString(),
            'fecha_cierre' => $diasParaCierre === null ? null : $hoy->copy()->addDays($diasParaCierre)->toDateString(),
            'creado_por' => $creador->id,
        ]));
    }

    /** @return array{vigente: Convocatoria, vencida: Convocatoria, cerrada: Convocatoria, enProceso: Convocatoria} */
    private function cuatroConvocatorias(Ficha $ficha, User $creador): array
    {
        return [
            'vigente' => $this->convocatoria($ficha, $creador, 'publicada', 5),
            'vencida' => $this->convocatoria($ficha, $creador, 'publicada', -1),
            'cerrada' => $this->convocatoria($ficha, $creador, 'cerrada', -1),
            'enProceso' => $this->convocatoria($ficha, $creador, 'en_proceso', -1),
        ];
    }

    /** @return list<int> */
    private function ids(TestResponse $respuesta): array
    {
        $ids = array_map('intval', array_column($respuesta->json('data'), 'id'));
        sort($ids);

        return $ids;
    }

    /** @return list<int> */
    private function idsEsperados(Convocatoria ...$convocatorias): array
    {
        $ids = array_map(fn (Convocatoria $c) => (int) $c->id, $convocatorias);
        sort($ids);

        return $ids;
    }

    // ── Listado ─────────────────────────────────────────────────────────
}
