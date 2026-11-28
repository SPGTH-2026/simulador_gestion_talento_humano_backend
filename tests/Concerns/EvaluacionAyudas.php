<?php

namespace Tests\Concerns;

use App\Enums\Role;
use App\Models\Convocatoria;
use App\Models\Ficha;
use App\Models\Parametro;
use App\Models\Postulacion;
use App\Models\User;
use Database\Seeders\ParametroSeeder;

trait EvaluacionAyudas
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

    private function parametroId(string $tema, string $codigo): int
    {
        return (int) Parametro::porTemaYCodigo($tema, $codigo)->id;
    }

    private function convocatoria(Ficha $ficha, ?User $creador = null, string $estado = 'publicada'): Convocatoria
    {
        $creador ??= $this->usuario(Role::Instructor, null, ['ficha_id' => $ficha->id]);

        return Convocatoria::unguarded(fn () => Convocatoria::create([
            'ficha_id' => $ficha->id,
            'codigo' => sprintf('CONV-TEST-%03d', ++self::$codigoSecuencia),
            'nombre' => 'Convocatoria '.$ficha->codigo,
            'creado_por' => $creador->id,
            'estado_id' => $this->parametroId('estado_convocatoria', $estado),
            'tipo_convocatoria_id' => $this->parametroId('tipo_convocatoria', 'externo'),
            'tipo_empleo_id' => $this->parametroId('tipo_empleo', 'tiempo_completo'),
            'tipo_contrato_id' => $this->parametroId('tipo_contrato', 'termino_fijo'),
            'modalidad_id' => $this->parametroId('modalidad', 'presencial'),
            'vacantes' => 5,
        ]));
    }

    private function postulacion(Convocatoria $convocatoria, ?User $aspirante = null): Postulacion
    {
        $aspirante ??= $this->usuario(Role::Aspirante, null, ['ficha_id' => $convocatoria->ficha_id]);

        return Postulacion::create([
            'convocatoria_id' => $convocatoria->id,
            'user_id' => $aspirante->id,
            'estado_id' => $this->parametroId('estado_postulacion', 'postulada'),
            'fecha_postulacion' => now(),
        ]);
    }

    /** El aspirante ya subió todos los documentos obligatorios de la convocatoria (la evaluación exige archivos cargados). */
    private function conDocumentosCargados(Postulacion $postulacion): Postulacion
    {
        $postulacion->convocatoria->load('documentos');
        foreach ($postulacion->convocatoria->documentos as $documento) {
            if ($documento->pivot->obligatorio) {
                $postulacion->documentos()->create([
                    'nombre_original' => 'archivo.pdf',
                    'ruta' => "postulaciones/test/{$postulacion->id}-{$documento->id}.pdf",
                    'disco' => 'local',
                    'mime' => 'application/pdf',
                    'tamano_bytes' => 1024,
                    'parametro_id' => $documento->id,
                ]);
            }
        }

        return $postulacion;
    }

    /** Perfil con todos los documentos obligatorios de la convocatoria calificados (todos «Cumple» o todos «No cumple»). */
    private function perfilDeTodos(Postulacion $postulacion, bool $cumple = true): array
    {
        $postulacion->convocatoria->load('documentos');

        return $postulacion->convocatoria->documentos
            ->filter(fn ($documento) => (bool) $documento->pivot->obligatorio)
            ->mapWithKeys(fn ($documento) => ['doc-'.$documento->id => $cumple])
            ->all();
    }

    /** @param array<int,array<string,mixed>> $criterios */
    private function criterios(array $criterios = []): array
    {
        return $criterios ?: [
            ['criterio' => 'Experiencia', 'puntaje' => 80, 'ponderacion' => 2],
            ['criterio' => 'Prueba técnica', 'puntaje' => 60, 'ponderacion' => 1],
        ];
    }

    // ── Acceso ─────────────────────────────────────────────────────────

    /** Adjunta documentos a la convocatoria con su ponderación (null = sin configurar). */
    private function documentosConPeso(Convocatoria $convocatoria, array $codigos): array
    {
        $ids = [];
        foreach ($codigos as $codigo => $peso) {
            $id = $this->parametroId('tipo_documento', $codigo);
            $convocatoria->documentos()->attach($id, [
                'obligatorio' => true,
                'ponderacion' => $peso,
            ]);
            $ids[$codigo] = $id;
        }

        return $ids;
    }

    /** Postulación a la que solo le falta la hoja de vida (sí subió el documento de identidad). */
    private function postulacionSinHojaDeVida(): array
    {
        $ficha = $this->ficha('2896411');
        $evaluador = $this->usuario(Role::Aprendiz, 'evaluador', ['ficha_id' => $ficha->id]);
        $convocatoria = $this->convocatoria($ficha);
        $ids = $this->documentosConPeso($convocatoria, ['hoja_de_vida' => 40, 'documento_identidad' => 60]);
        $postulacion = $this->postulacion($convocatoria);
        $postulacion->documentos()->create([
            'nombre_original' => 'id.pdf', 'ruta' => "postulaciones/test/{$postulacion->id}-id.pdf", 'disco' => 'local',
            'mime' => 'application/pdf', 'tamano_bytes' => 1024, 'parametro_id' => $ids['documento_identidad'],
        ]);

        return [$evaluador, $postulacion, $ids];
    }

    /** Escenario común: evaluador, aspirante y una postulación con la hoja de vida marcada para corregir. */
    private function escenarioCorreccion(string $estadoConvocatoria = 'publicada'): array
    {
        $ficha = $this->ficha('2896499');
        $evaluador = $this->usuario(Role::Aprendiz, 'evaluador', ['ficha_id' => $ficha->id]);
        $aspirante = $this->usuario(Role::Aspirante, null, ['ficha_id' => $ficha->id]);
        $convocatoria = $this->convocatoria($ficha, null, $estadoConvocatoria);
        $convocatoria->forceFill([
            'fecha_apertura' => now('America/Bogota')->subDays(5)->toDateString(),
            'fecha_cierre' => now('America/Bogota')->subDay()->toDateString(),
        ])->save();

        $hoja = $this->parametroId('tipo_documento', 'hoja_de_vida');
        $convocatoria->documentos()->attach($hoja, ['obligatorio' => true, 'ponderacion' => 100]);
        $postulacion = $this->postulacion($convocatoria, $aspirante);

        $cuerpo = fn (string $observaciones) => [
            'estado' => 'correccion_solicitada',
            'observaciones' => $observaciones,
            'criterios' => [['criterio' => 'Evaluación', 'puntaje' => 0, 'ponderacion' => 1]],
            'perfil' => ['doc-'.$hoja => false],
        ];

        return [$evaluador, $aspirante, $postulacion, $cuerpo];
    }
}
