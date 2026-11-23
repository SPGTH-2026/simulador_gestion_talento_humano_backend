<?php

namespace Tests\Concerns;

use App\Enums\Role;
use App\Models\Convocatoria;
use App\Models\Evaluacion;
use App\Models\Ficha;
use App\Models\Parametro;
use App\Models\Postulacion;
use App\Models\SeleccionConfiguracion;
use App\Models\SeleccionFinalistaLista;
use App\Models\User;
use Database\Seeders\ParametroSeeder;

trait SeleccionListadoAyudas
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

    private function convocatoria(Ficha $ficha, string $estado = 'en_proceso'): Convocatoria
    {
        return Convocatoria::unguarded(fn () => Convocatoria::create([
            'ficha_id' => $ficha->id,
            'codigo' => sprintf('CONV-LST-%03d', ++self::$codigoSecuencia),
            'nombre' => 'Convocatoria '.self::$codigoSecuencia,
            'creado_por' => $this->usuario(Role::Instructor, null, ['ficha_id' => $ficha->id])->id,
            'estado_id' => $this->parametroId('estado_convocatoria', $estado),
            'tipo_convocatoria_id' => $this->parametroId('tipo_convocatoria', 'externo'),
            'tipo_empleo_id' => $this->parametroId('tipo_empleo', 'tiempo_completo'),
            'tipo_contrato_id' => $this->parametroId('tipo_contrato', 'termino_fijo'),
            'modalidad_id' => $this->parametroId('modalidad', 'presencial'),
            'vacantes' => 2,
        ]));
    }

    /** Sin actividades: basta con la habilitación documental. */
    private function sinActividades(Convocatoria $convocatoria): void
    {
        SeleccionConfiguracion::create([
            'convocatoria_id' => $convocatoria->id,
            'entrevista_activa' => false,
            'prueba_tecnica_activa' => false,
        ]);
    }

    /** Con entrevista activa: sin nota, nadie está listo. */
    private function conEntrevista(Convocatoria $convocatoria): void
    {
        SeleccionConfiguracion::create([
            'convocatoria_id' => $convocatoria->id,
            'entrevista_activa' => true,
            'prueba_tecnica_activa' => false,
            'peso_entrevista' => 100,
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

    /** Candidato habilitado documentalmente (evaluación finalizada que pasa a selección). */
    private function habilitado(Convocatoria $convocatoria): Postulacion
    {
        $postulacion = $this->postulacion($convocatoria);
        $this->evaluadorCompartido ??= $this->usuario(Role::Aprendiz, 'evaluador', ['ficha_id' => $convocatoria->ficha_id]);
        Evaluacion::create([
            'postulacion_id' => $postulacion->id,
            'evaluador_id' => $this->evaluadorCompartido->id,
            'estado' => 'finalizada',
            'cumplimiento' => 90,
            'pasa_seleccion' => true,
        ]);

        return $postulacion;
    }

    private function confirmarLista(Convocatoria $convocatoria, User $actor): void
    {
        SeleccionFinalistaLista::create([
            'convocatoria_id' => $convocatoria->id,
            'version' => 1,
            'modo' => 'sin_actividades',
            'objetivo' => 1,
            'confirmado_por' => $actor->id,
        ]);
    }

    private function listado(User $actor, string $consulta = '')
    {
        return $this->actingAs($actor)->getJson('/api/seleccion/convocatorias'.$consulta);
    }

    // ── Filtro por estado ──────────────────────────────────────────────
}
