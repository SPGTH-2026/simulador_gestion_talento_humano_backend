<?php

namespace Tests\Concerns;

use App\Enums\Role;
use App\Models\Convocatoria;
use App\Models\Evaluacion;
use App\Models\Ficha;
use App\Models\Parametro;
use App\Models\Postulacion;
use App\Models\User;
use Database\Seeders\ParametroSeeder;

trait SeleccionReferenciaAyudas
{
    private static int $codigoSecuencia = 0;

    private const JUSTIFICACION = 'El segundo evaluador revisó el soporte completo.';

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

    private function instructor(Ficha $ficha): User
    {
        return $this->usuario(Role::Instructor, null, ['ficha_id' => $ficha->id]);
    }

    private function parametroId(string $tema, string $codigo): int
    {
        return (int) Parametro::porTemaYCodigo($tema, $codigo)->id;
    }

    private function convocatoria(Ficha $ficha): Convocatoria
    {
        return Convocatoria::unguarded(fn () => Convocatoria::create([
            'ficha_id' => $ficha->id,
            'codigo' => sprintf('CONV-REF-%03d', ++self::$codigoSecuencia),
            'nombre' => 'Convocatoria '.$ficha->codigo,
            'creado_por' => $this->instructor($ficha)->id,
            'estado_id' => $this->parametroId('estado_convocatoria', 'publicada'),
            'tipo_convocatoria_id' => $this->parametroId('tipo_convocatoria', 'externo'),
            'tipo_empleo_id' => $this->parametroId('tipo_empleo', 'tiempo_completo'),
            'tipo_contrato_id' => $this->parametroId('tipo_contrato', 'termino_fijo'),
            'modalidad_id' => $this->parametroId('modalidad', 'presencial'),
            'vacantes' => 5,
        ]));
    }

    private function postulacion(Convocatoria $convocatoria, string $estado = 'postulada'): Postulacion
    {
        return Postulacion::create([
            'convocatoria_id' => $convocatoria->id,
            'user_id' => $this->usuario(Role::Aspirante, null, ['ficha_id' => $convocatoria->ficha_id])->id,
            'estado_id' => $this->parametroId('estado_postulacion', $estado),
            'fecha_postulacion' => now(),
        ]);
    }

    private function evaluacion(Postulacion $postulacion, string $estado, ?int $cumplimiento, ?bool $pasa): Evaluacion
    {
        return Evaluacion::create([
            'postulacion_id' => $postulacion->id,
            'evaluador_id' => $this->usuario(Role::Aprendiz, 'evaluador', ['ficha_id' => $postulacion->convocatoria->ficha_id])->id,
            'estado' => $estado,
            'cumplimiento' => $cumplimiento,
            'pasa_seleccion' => $pasa,
        ]);
    }

    private function url(Convocatoria $convocatoria, Postulacion $postulacion): string
    {
        return "/api/seleccion/convocatorias/{$convocatoria->id}/candidatos/{$postulacion->id}/referencia-documental";
    }

    private function designar(User $actor, Convocatoria $convocatoria, Postulacion $postulacion, Evaluacion $evaluacion, ?string $justificacion = self::JUSTIFICACION)
    {
        return $this->actingAs($actor)->postJson($this->url($convocatoria, $postulacion), [
            'evaluacion_id' => $evaluacion->id,
            'justificacion' => $justificacion,
        ]);
    }

    /** Fila del listado de candidatos para una postulación. */
    private function fila(User $actor, Convocatoria $convocatoria, Postulacion $postulacion): array
    {
        return collect($this->actingAs($actor)
            ->getJson("/api/seleccion/convocatorias/{$convocatoria->id}/candidatos")
            ->assertOk()->json('data'))->firstWhere('id', $postulacion->id);
    }

    /** Postulación con dos evaluaciones finalizadas que se contradicen (A habilita, B no). */
    private function casoContradictorio(): array
    {
        $ficha = $this->ficha('2896411');
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->convocatoria($ficha);
        $postulacion = $this->postulacion($convocatoria);
        $a = $this->evaluacion($postulacion, 'finalizada', 90, true);
        $b = $this->evaluacion($postulacion, 'finalizada', 30, false);

        return [$instructor, $convocatoria, $postulacion, $a, $b, $ficha];
    }

    // ── Acceso ─────────────────────────────────────────────────────────
}
