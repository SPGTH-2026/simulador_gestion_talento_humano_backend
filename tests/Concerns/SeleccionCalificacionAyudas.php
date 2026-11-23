<?php

namespace Tests\Concerns;

use App\Enums\Role;
use App\Models\Convocatoria;
use App\Models\Evaluacion;
use App\Models\Ficha;
use App\Models\Parametro;
use App\Models\Postulacion;
use App\Models\SeleccionConfiguracion;
use App\Models\User;
use Database\Seeders\ParametroSeeder;

trait SeleccionCalificacionAyudas
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

    private function convocatoria(Ficha $ficha, string $estado = 'en_proceso'): Convocatoria
    {
        return Convocatoria::unguarded(fn () => Convocatoria::create([
            'ficha_id' => $ficha->id,
            'codigo' => sprintf('CONV-CAL-%03d', ++self::$codigoSecuencia),
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

    private function configurar(Convocatoria $convocatoria, bool $entrevista = true, bool $prueba = true, ?int $pesoE = 50, ?int $pesoP = 50): SeleccionConfiguracion
    {
        return SeleccionConfiguracion::create([
            'convocatoria_id' => $convocatoria->id,
            'entrevista_activa' => $entrevista,
            'prueba_tecnica_activa' => $prueba,
            // Igual que la configuración real: una sola actividad activa pesa 100.
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

    /** Candidato habilitado documentalmente (una evaluación finalizada que pasa). */
    private function candidatoHabilitado(Convocatoria $convocatoria): Postulacion
    {
        $postulacion = $this->postulacion($convocatoria);
        $this->evaluacion($postulacion, 'finalizada', 85, true);

        return $postulacion;
    }

    private function url(Convocatoria $convocatoria, Postulacion $postulacion, string $actividad = ''): string
    {
        return "/api/seleccion/convocatorias/{$convocatoria->id}/candidatos/{$postulacion->id}/calificaciones".($actividad ? "/{$actividad}" : '');
    }

    private function calificar(User $actor, Convocatoria $convocatoria, Postulacion $postulacion, string $actividad, array $cuerpo)
    {
        return $this->actingAs($actor)->putJson($this->url($convocatoria, $postulacion, $actividad), $cuerpo + [
            'fecha_realizacion' => now('America/Bogota')->toDateString(),
        ]);
    }

    /** Escenario base: convocatoria en proceso con ambas actividades 50/50 y un candidato habilitado. */
    private function escenario(int $pesoE = 50, int $pesoP = 50): array
    {
        $ficha = $this->ficha('2896411');
        $convocatoria = $this->convocatoria($ficha);
        $this->configurar($convocatoria, true, true, $pesoE, $pesoP);

        return [$this->seleccionador($ficha), $convocatoria, $this->candidatoHabilitado($convocatoria), $ficha];
    }

    // ── Acceso ─────────────────────────────────────────────────────────
}
