<?php

namespace Tests\Concerns;

use App\Enums\Role;
use App\Models\Convocatoria;
use App\Models\Ficha;
use App\Models\Parametro;
use App\Models\User;
use Database\Seeders\ParametroSeeder;

trait SeleccionConfiguracionAyudas
{
    private static int $codigoSecuencia = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ParametroSeeder::class);
    }

    private function ficha(string $codigo): Ficha
    {
        return Ficha::create(['codigo' => $codigo, 'nombre_programa' => 'ADSO']);
    }

    private function usuario(Role $role, ?string $subrole = null, array $extra = []): User
    {
        return User::factory()->create($extra + [
            'role' => $role,
            'subrole' => $subrole,
            'active' => true,
            'email_verified_at' => now(),
        ]);
    }

    private function seleccionador(Ficha $ficha): User
    {
        return $this->usuario(Role::Aprendiz, 'seleccionador', ['ficha_id' => $ficha->id]);
    }

    private function parametroId(string $tema, string $codigo): int
    {
        return (int) Parametro::porTemaYCodigo($tema, $codigo)->id;
    }

    private function convocatoria(Ficha $ficha, string $estado = 'publicada'): Convocatoria
    {
        return Convocatoria::unguarded(fn () => Convocatoria::create([
            'ficha_id' => $ficha->id,
            'codigo' => sprintf('CONV-CFG-%03d', ++self::$codigoSecuencia),
            'nombre' => 'Convocatoria '.$ficha->codigo,
            'creado_por' => $this->usuario(Role::Instructor, null, ['ficha_id' => $ficha->id])->id,
            'estado_id' => $this->parametroId('estado_convocatoria', $estado),
            'tipo_convocatoria_id' => $this->parametroId('tipo_convocatoria', 'externo'),
            'tipo_empleo_id' => $this->parametroId('tipo_empleo', 'tiempo_completo'),
            'tipo_contrato_id' => $this->parametroId('tipo_contrato', 'termino_fijo'),
            'modalidad_id' => $this->parametroId('modalidad', 'presencial'),
            'vacantes' => 5,
        ]));
    }

    private function cambiarEstado(Convocatoria $convocatoria, string $estado): void
    {
        $convocatoria->forceFill(['estado_id' => $this->parametroId('estado_convocatoria', $estado)])->save();
    }

    private function url(Convocatoria $convocatoria): string
    {
        return "/api/seleccion/convocatorias/{$convocatoria->id}/configuracion";
    }

    private function guardar(User $actor, Convocatoria $convocatoria, array $cuerpo)
    {
        return $this->actingAs($actor)->putJson($this->url($convocatoria), $cuerpo);
    }

    // ── Acceso ─────────────────────────────────────────────────────────
}
