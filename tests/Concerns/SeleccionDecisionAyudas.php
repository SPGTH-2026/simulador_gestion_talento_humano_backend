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

trait SeleccionDecisionAyudas
{
    private static int $codigoSecuencia = 0;

    private ?User $evaluadorCompartido = null;

    private const MOTIVO = 'Decisión tomada por el comité de selección.';

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

    private function convocatoria(Ficha $ficha, int $vacantes = 2, string $estado = 'en_proceso'): Convocatoria
    {
        return Convocatoria::unguarded(fn () => Convocatoria::create([
            'ficha_id' => $ficha->id,
            'codigo' => sprintf('CONV-DEC-%03d', ++self::$codigoSecuencia),
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

    private function postulacion(Convocatoria $convocatoria): Postulacion
    {
        return Postulacion::create([
            'convocatoria_id' => $convocatoria->id,
            'user_id' => $this->usuario(Role::Aspirante, null, ['ficha_id' => $convocatoria->ficha_id])->id,
            'estado_id' => $this->parametroId('estado_postulacion', 'postulada'),
            'fecha_postulacion' => now(),
        ]);
    }

    /** Candidato habilitado con ambas actividades calificadas. */
    private function candidato(Convocatoria $convocatoria, string $nota = '8'): Postulacion
    {
        $this->evaluadorCompartido ??= $this->usuario(Role::Aprendiz, 'evaluador', ['ficha_id' => $convocatoria->ficha_id]);
        $postulacion = $this->postulacion($convocatoria);
        Evaluacion::create([
            'postulacion_id' => $postulacion->id,
            'evaluador_id' => $this->evaluadorCompartido->id,
            'estado' => 'finalizada',
            'cumplimiento' => 85,
            'pasa_seleccion' => true,
        ]);
        foreach (['entrevista', 'prueba_tecnica'] as $actividad) {
            SeleccionCalificacion::create([
                'convocatoria_id' => $convocatoria->id,
                'postulacion_id' => $postulacion->id,
                'actividad' => $actividad,
                'nota' => $nota,
                'fecha_realizacion' => '2026-11-10',
            ]);
        }

        return $postulacion;
    }

    /** Convocatoria en proceso con actividades 50/50, N candidatos y la lista de finalistas confirmada. */
    private function escenario(int $vacantes, int $candidatos): array
    {
        $ficha = $this->ficha();
        $convocatoria = $this->convocatoria($ficha, $vacantes);
        SeleccionConfiguracion::create(['convocatoria_id' => $convocatoria->id, 'entrevista_activa' => true, 'prueba_tecnica_activa' => true, 'peso_entrevista' => 50, 'peso_prueba_tecnica' => 50]);
        $seleccionador = $this->seleccionador($ficha);
        $personas = array_map(fn (int $i) => $this->candidato($convocatoria, (string) (10 - $i)), range(0, $candidatos - 1));
        $version = $this->confirmarLista($seleccionador, $convocatoria, $personas);

        return [$seleccionador, $convocatoria, $personas, $version];
    }

    /** Confirma la lista de finalistas por la API y devuelve su versión. */
    private function confirmarLista(User $actor, Convocatoria $convocatoria, array $personas): int
    {
        $this->actingAs($actor)->putJson("/api/seleccion/convocatorias/{$convocatoria->id}/ranking/objetivo", ['objetivo' => 100])->assertOk();
        $version = $this->actingAs($actor)->getJson("/api/seleccion/convocatorias/{$convocatoria->id}/ranking")->json('meta.version');

        return $this->actingAs($actor)->postJson("/api/seleccion/convocatorias/{$convocatoria->id}/finalistas", [
            'version' => $version,
            'postulacion_ids' => array_map(fn (Postulacion $p) => $p->id, $personas),
            'justificacion' => 'Lista confirmada para las pruebas de decisión',
        ])->assertSuccessful()->json('data.version');
    }

    private function decidir(User $actor, Convocatoria $convocatoria, Postulacion $postulacion, string $resultado, ?int $version, ?string $justificacion = self::MOTIVO)
    {
        return $this->actingAs($actor)->putJson("/api/seleccion/convocatorias/{$convocatoria->id}/decisiones/{$postulacion->id}", array_filter([
            'resultado' => $resultado,
            'lista_version' => $version,
            'justificacion' => $justificacion,
        ], fn ($v) => $v !== null));
    }

    private function decisiones(User $actor, Convocatoria $convocatoria, string $consulta = '')
    {
        return $this->actingAs($actor)->getJson("/api/seleccion/convocatorias/{$convocatoria->id}/decisiones{$consulta}");
    }

    // ── Acceso ─────────────────────────────────────────────────────────

    /** Escenario independiente en otra ficha, para no mezclar datos dentro de una misma prueba. */
    private function escenarioEnOtraFicha(): array
    {
        $ficha = $this->ficha('2631620');
        $convocatoria = $this->convocatoria($ficha, 1);
        SeleccionConfiguracion::create(['convocatoria_id' => $convocatoria->id, 'entrevista_activa' => true, 'prueba_tecnica_activa' => true, 'peso_entrevista' => 50, 'peso_prueba_tecnica' => 50]);
        $seleccionador = $this->seleccionador($ficha);
        $personas = [$this->candidato($convocatoria)];
        $version = $this->confirmarLista($seleccionador, $convocatoria, $personas);

        return [$seleccionador, $convocatoria, $personas, $version];
    }

    private function fila(User $actor, Convocatoria $convocatoria, Postulacion $postulacion): array
    {
        return collect($this->decisiones($actor, $convocatoria)->assertOk()->json('data'))->firstWhere('postulacion_id', $postulacion->id);
    }
}
