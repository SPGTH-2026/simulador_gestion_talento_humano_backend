<?php

namespace Database\Seeders;

use App\Enums\ConvocatoriaEstado;
use App\Enums\PostulacionEstado;
use App\Enums\Role;
use App\Models\Convocatoria;
use App\Models\Evaluacion;
use App\Models\Ficha;
use App\Models\Parametro;
use App\Models\Postulacion;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Datos de demostración del módulo de Evaluación (solo desarrollo).
 */
class EvaluacionDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            return;
        }

        $ficha = Ficha::firstWhere('codigo', '3173334') ?? Ficha::query()->first();

        if (! $ficha) {
            return;
        }

        $password = env('SEED_PASSWORD', 'Dev12345');

        $aspirantes = [];

        foreach ([
            ['Aspirante Demo', 'aspirante@test.com'],
            ['Aspirante Uno Demo', 'aspirante.uno@test.com'],
            ['Aspirante Dos Demo', 'aspirante.dos@test.com'],
            ['Aspirante Tres Demo', 'aspirante.tres@test.com'],
            ['Aspirante Completo Demo', 'aspirante.completo@test.com'],
        ] as [$name, $email]) {
            $user = User::firstWhere('email', $email);

            if (! $user) {
                $user = User::forceCreate([
                    'name' => $name,
                    'email' => $email,
                    'password' => $password,
                    'role' => Role::Aspirante,
                    'active' => true,
                    'ficha_id' => $ficha->id,
                    'email_verified_at' => now(),
                ]);
            } elseif ($user->ficha_id === null) {
                $user->forceFill(['ficha_id' => $ficha->id])->save();
            }

            $aspirantes[] = $user->id;
        }

        $aspirantes = array_values(array_unique($aspirantes));

        $instructor = User::firstWhere('email', 'instructor@test.com');

        // Parámetros necesarios (resueltos por código de tema + código).
        $estadoPublicada = Parametro::porTemaYCodigo('estado_convocatoria', ConvocatoriaEstado::Publicada->value);
        $tipoConvocatoria = Parametro::porTemaYCodigo('tipo_convocatoria', 'externo');
        $tipoEmpleo = Parametro::porTemaYCodigo('tipo_empleo', 'tiempo_completo');
        $tipoContrato = Parametro::porTemaYCodigo('tipo_contrato', 'termino_fijo');
        $modalidad = Parametro::porTemaYCodigo('modalidad', 'presencial');
        $estadoPostulada = Parametro::porTemaYCodigo('estado_postulacion', PostulacionEstado::Postulada->value);

        if (! $estadoPublicada || ! $tipoConvocatoria || ! $tipoEmpleo || ! $tipoContrato || ! $modalidad || ! $estadoPostulada) {
            return;
        }

        $convocatoria = Convocatoria::firstOrCreate(
            ['ficha_id' => $ficha->id, 'nombre' => 'Convocatoria de prueba SPGTH'],
            [
                'codigo' => 'CONV-2026-001',
                'nombre' => 'Convocatoria de prueba SPGTH',
                'cargo' => 'Auxiliar Administrativo',
                'descripcion' => 'Datos de demostración para el módulo de Evaluación.',
                'ficha_id' => $ficha->id,
                'creado_por' => $instructor?->id,
                'tipo_convocatoria_id' => $tipoConvocatoria->id,
                'tipo_empleo_id' => $tipoEmpleo->id,
                'tipo_contrato_id' => $tipoContrato->id,
                'modalidad_id' => $modalidad->id,
                'estado_id' => $estadoPublicada->id,
                'vacantes' => 10,
                'fecha_apertura' => now()->toDateString(),
                'fecha_cierre' => now()->addMonth()->toDateString(),
                'fecha_evaluacion' => now()->addMonths(2)->toDateString(),
            ],
        );

        $convocatoria->forceFill(['estado_id' => $estadoPublicada->id])->save();

        foreach ($aspirantes as $aspiranteId) {
            Postulacion::firstOrCreate(
                ['convocatoria_id' => $convocatoria->id, 'user_id' => $aspiranteId],
                [
                    'estado_id' => $estadoPostulada->id,
                    'fecha_postulacion' => now(),
                ],
            );
        }

        // Evaluaciones de demostración (hechas por el instructor demo), para que
        // "Mis evaluaciones" muestre algo al entrar como instructor.
        $evaluadorDemo = User::firstWhere('email', 'instructor@test.com');
        if ($evaluadorDemo) {
            $postulacionesDemo = Postulacion::query()
                ->where('convocatoria_id', $convocatoria->id)
                ->orderBy('id')
                ->take(2)
                ->get();

            foreach ($postulacionesDemo as $i => $postulacion) {
                Evaluacion::firstOrCreate(
                    ['postulacion_id' => $postulacion->id, 'evaluador_id' => $evaluadorDemo->id],
                    [
                        'puntaje_total' => $i === 0 ? 85 : 72,
                        'estado' => 'finalizada',
                        'observaciones' => 'Evaluación de demostración.',
                        'cumplimiento' => $i === 0 ? 100 : 75,
                        'pasa_seleccion' => $i === 0 ? true : true,
                        'perfil' => ['formacion' => true, 'experiencia' => true],
                    ],
                );
            }
        }
    }
}
