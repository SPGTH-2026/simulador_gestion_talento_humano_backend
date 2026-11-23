<?php

namespace Tests\Concerns;

use App\Enums\Role;
use App\Models\Convocatoria;
use App\Models\Evaluacion;
use App\Models\Ficha;
use App\Models\Parametro;
use App\Models\Postulacion;
use App\Models\SeleccionCalificacion;
use App\Models\SeleccionConfiguracion;
use App\Models\User;
use Database\Seeders\ParametroSeeder;

trait SeleccionRankingAyudas
{
    private static int $codigoSecuencia = 0;

    private ?User $evaluadorCompartido = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ParametroSeeder::class);
    }

    private function ficha(string $codigo = '2896411'): Ficha
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

    private function convocatoria(Ficha $ficha, string $estado = 'en_proceso', int $vacantes = 2): Convocatoria
    {
        return Convocatoria::unguarded(fn () => Convocatoria::create([
            'ficha_id' => $ficha->id,
            'codigo' => sprintf('CONV-RNK-%03d', ++self::$codigoSecuencia),
            'nombre' => 'Convocatoria '.$ficha->codigo,
            'creado_por' => $this->usuario(Role::Instructor, null, ['ficha_id' => $ficha->id])->id,
            'estado_id' => $this->parametroId('estado_convocatoria', $estado),
            'tipo_convocatoria_id' => $this->parametroId('tipo_convocatoria', 'externo'),
            'tipo_empleo_id' => $this->parametroId('tipo_empleo', 'tiempo_completo'),
            'tipo_contrato_id' => $this->parametroId('tipo_contrato', 'termino_fijo'),
            'modalidad_id' => $this->parametroId('modalidad', 'presencial'),
            'vacantes' => $vacantes,
        ]));
    }

    private function configurar(Convocatoria $convocatoria, bool $entrevista = true, bool $prueba = true, ?int $pesoE = 50, ?int $pesoP = 50): void
    {
        SeleccionConfiguracion::create([
            'convocatoria_id' => $convocatoria->id,
            'entrevista_activa' => $entrevista,
            'prueba_tecnica_activa' => $prueba,
            'peso_entrevista' => $entrevista ? ($prueba ? $pesoE : 100) : null,
            'peso_prueba_tecnica' => $prueba ? ($entrevista ? $pesoP : 100) : null,
        ]);
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

    private function evaluacion(Postulacion $postulacion, string $estado, ?int $cumplimiento, ?bool $pasa, bool $nuevoEvaluador = false): Evaluacion
    {
        $this->evaluadorCompartido ??= $this->usuario(Role::Aprendiz, 'evaluador', ['ficha_id' => $postulacion->convocatoria->ficha_id]);
        $evaluador = $nuevoEvaluador ? $this->usuario(Role::Aprendiz, 'evaluador', ['ficha_id' => $postulacion->convocatoria->ficha_id]) : $this->evaluadorCompartido;

        return Evaluacion::create([
            'postulacion_id' => $postulacion->id,
            'evaluador_id' => $evaluador->id,
            'estado' => $estado,
            'cumplimiento' => $cumplimiento,
            'pasa_seleccion' => $pasa,
        ]);
    }

    private function nota(Postulacion $postulacion, string $actividad, string $valor): SeleccionCalificacion
    {
        return SeleccionCalificacion::create([
            'convocatoria_id' => $postulacion->convocatoria_id,
            'postulacion_id' => $postulacion->id,
            'actividad' => $actividad,
            'nota' => $valor,
            'fecha_realizacion' => '2026-11-10',
        ]);
    }

    /** Candidato habilitado con las notas indicadas (entrevista, prueba técnica). */
    private function candidato(Convocatoria $convocatoria, ?string $entrevista, ?string $prueba = null): Postulacion
    {
        $postulacion = $this->postulacion($convocatoria);
        $this->evaluacion($postulacion, 'finalizada', 85, true);
        if ($entrevista !== null) {
            $this->nota($postulacion, 'entrevista', $entrevista);
        }
        if ($prueba !== null) {
            $this->nota($postulacion, 'prueba_tecnica', $prueba);
        }

        return $postulacion;
    }

    private function ranking(User $actor, Convocatoria $convocatoria, string $consulta = '')
    {
        return $this->actingAs($actor->fresh())->getJson("/api/seleccion/convocatorias/{$convocatoria->id}/ranking{$consulta}");
    }

    private function confirmar(User $actor, Convocatoria $convocatoria, string $version, array $ids, ?string $justificacion = null)
    {
        return $this->actingAs($actor)->postJson("/api/seleccion/convocatorias/{$convocatoria->id}/finalistas", [
            'version' => $version,
            'postulacion_ids' => array_map(fn ($p) => $p instanceof Postulacion ? $p->id : $p, $ids),
            'justificacion' => $justificacion,
        ]);
    }

    /** Escenario con actividades 50/50 y notas de entrevista únicas (la prueba repite la misma). */
    private function escenarioConNotas(array $notas, int $vacantes = 2, string $codigoFicha = '2896411'): array
    {
        $ficha = $this->ficha($codigoFicha);
        $convocatoria = $this->convocatoria($ficha, 'en_proceso', $vacantes);
        $this->configurar($convocatoria);
        $candidatos = array_map(fn (string $n) => $this->candidato($convocatoria, $n, $n), $notas);

        return [$this->seleccionador($ficha), $convocatoria, $candidatos];
    }

    // ── Acceso ─────────────────────────────────────────────────────────
}
