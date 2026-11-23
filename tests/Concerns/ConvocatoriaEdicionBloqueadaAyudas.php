<?php

namespace Tests\Concerns;

use App\Enums\Role;
use App\Models\Convocatoria;
use App\Models\Ficha;
use App\Models\Parametro;
use App\Models\ParametroTema;
use App\Models\Postulacion;
use App\Models\User;
use Database\Seeders\ParametroSeeder;

trait ConvocatoriaEdicionBloqueadaAyudas
{
    private static int $codigoSecuencia = 0;

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

    private function aspirante(Ficha $ficha): User
    {
        return $this->usuario(Role::Aspirante, ['ficha_id' => $ficha->id]);
    }

    private function parametroId(string $tema, string $codigo): int
    {
        return (int) Parametro::porTemaYCodigo($tema, $codigo)->id;
    }

    /**
     * Documento EXTRA del catálogo: parámetro ACTIVO creado dentro de la prueba.
     * El alta ya lo guardaría como obligatorio; estas pruebas lo adjuntan a mano
     * con obligatorio = false para simular convocatorias creadas antes de la regla.
     */
    private function documentoExtra(string $codigo = 'documento_adicional'): Parametro
    {
        $tema = ParametroTema::where('codigo', 'tipo_documento')->firstOrFail();

        return Parametro::create([
            'tema_id' => $tema->id,
            'codigo' => $codigo,
            'nombre' => 'Documento adicional',
            'orden' => 99,
            'activo' => true,
        ]);
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
            'estado_id' => $this->parametroId('estado_convocatoria', 'borrador'),
            'creado_por' => $creador->id,
        ]));
    }

    /** Publicada con la ventana abierta y todos los campos poblados. */
    private function publicable(Ficha $ficha, User $creador, array $overrides = []): Convocatoria
    {
        return $this->crearConvocatoria($ficha, $creador, $overrides + [
            'nombre' => 'Instructor de software',
            'cargo' => 'Instructor',
            'area' => 'Sistemas',
            'descripcion' => 'Descripción inicial',
            'vacantes' => 2,
            'salario_min' => 3500000,
            'salario_max' => 4200000,
            'funciones' => 'Funciones iniciales',
            'responsabilidades' => 'Responsabilidades iniciales',
            'perfil_requerido' => 'Perfil inicial',
            'formacion' => 'Ingeniería de sistemas',
            'experiencia' => '2 años',
            'competencias' => 'Trabajo en equipo',
            'conocimientos_especificos' => 'Laravel',
            'estado_id' => $this->parametroId('estado_convocatoria', 'publicada'),
            'fecha_apertura' => now('America/Bogota')->subDay()->toDateString(),
            'fecha_cierre' => now('America/Bogota')->addDays(10)->toDateString(),
            'fecha_evaluacion' => now('America/Bogota')->addDays(20)->toDateString(),
        ]);
    }

    private function conSkills(Convocatoria $convocatoria, array $skills): Convocatoria
    {
        foreach ($skills as $skill) {
            $convocatoria->skills()->create(['nombre' => $skill]);
        }

        return $convocatoria;
    }

    /** $documentos = ['codigo' => bool obligatorio, ...]. */
    private function conDocumentos(Convocatoria $convocatoria, array $documentos): Convocatoria
    {
        $sync = [];
        foreach ($documentos as $codigo => $obligatorio) {
            $sync[$this->parametroId('tipo_documento', $codigo)] = ['obligatorio' => $obligatorio];
        }

        $convocatoria->documentos()->sync($sync);

        return $convocatoria;
    }

    private function postular(Convocatoria $convocatoria, User $aspirante): Postulacion
    {
        return Postulacion::unguarded(fn () => Postulacion::create([
            'convocatoria_id' => $convocatoria->id,
            'user_id' => $aspirante->id,
            'estado_id' => $this->parametroId('estado_postulacion', 'postulada'),
            'fecha_postulacion' => now(),
        ]));
    }

    private function retirar(Convocatoria $convocatoria, User $aspirante): Postulacion
    {
        return Postulacion::unguarded(fn () => Postulacion::create([
            'convocatoria_id' => $convocatoria->id,
            'user_id' => $aspirante->id,
            'estado_id' => $this->parametroId('estado_postulacion', 'retirada'),
            'fecha_postulacion' => now()->subDay(),
            'fecha_retiro' => now(),
        ]));
    }

    private function borrador(Convocatoria $convocatoria, User $aspirante): Postulacion
    {
        return Postulacion::unguarded(fn () => Postulacion::create([
            'convocatoria_id' => $convocatoria->id,
            'user_id' => $aspirante->id,
            'estado_id' => $this->parametroId('estado_postulacion', 'borrador'),
            'fecha_postulacion' => now(),
        ]));
    }

    /** Mensaje exacto de campo bloqueado. */
    private function msg(string $campo): string
    {
        return "No se puede modificar {$campo} porque la convocatoria ya tiene postulaciones.";
    }

    /** Cuerpo completo a partir de los valores guardados (wizard reenvía todo). */
    private function payloadCompleto(Convocatoria $c): array
    {
        $c = $c->fresh();

        return [
            'nombre' => $c->nombre,
            'cargo' => $c->cargo,
            'area' => $c->area,
            'descripcion' => $c->descripcion,
            'vacantes' => (int) $c->vacantes,
            'salario_min' => (float) $c->salario_min,
            'salario_max' => (float) $c->salario_max,
            'funciones' => $c->funciones,
            'responsabilidades' => $c->responsabilidades,
            'perfil_requerido' => $c->perfil_requerido,
            'formacion' => $c->formacion,
            'experiencia' => $c->experiencia,
            'competencias' => $c->competencias,
            'conocimientos_especificos' => $c->conocimientos_especificos,
            'tipo_convocatoria' => $c->tipoConvocatoria->codigo,
            'tipo_empleo' => $c->tipoEmpleo->codigo,
            'tipo_contrato' => $c->tipoContrato->codigo,
            'modalidad' => $c->modalidad->codigo,
            'fecha_apertura' => $c->fecha_apertura->toDateString(),
            'fecha_cierre' => $c->fecha_cierre->toDateString(),
            'fecha_evaluacion' => $c->fecha_evaluacion->toDateString(),
            'skills' => $c->skills->pluck('nombre')->all(),
            'documentos' => $c->documentos->map(fn ($d) => [
                'codigo' => $d->codigo,
                'obligatorio' => (bool) $d->pivot->obligatorio,
            ])->values()->all(),
        ];
    }

    // ── La regla solo aplica a publicada ---------------------------------

    private function publicadaConPostulada(array $overrides = []): array
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->publicable($ficha, $instructor, $overrides);
        $this->postular($convocatoria, $this->aspirante($ficha));

        return [$instructor, $convocatoria];
    }
}
