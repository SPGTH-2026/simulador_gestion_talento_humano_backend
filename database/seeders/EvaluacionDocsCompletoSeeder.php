<?php

namespace Database\Seeders;

use App\Enums\ConvocatoriaEstado;
use App\Enums\PostulacionEstado;
use App\Enums\Role;
use App\Models\Convocatoria;
use App\Models\ConvocatoriaSkill;
use App\Models\Ficha;
use App\Models\Parametro;
use App\Models\Postulacion;
use App\Models\PostulacionDocumento;
use App\Models\PostulacionSkill;
use App\Models\User;
use Illuminate\Database\Seeder;

class EvaluacionDocsCompletoSeeder extends Seeder
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
        $instructor = User::firstWhere('email', 'instructor@test.com');

        // Aspirante con documentos completos.
        $aspirante = User::firstWhere('email', 'aspirante.docscompletos@test.com');
        if (! $aspirante) {
            $aspirante = User::forceCreate([
                'name' => 'Aspirante Docs Completos',
                'email' => 'aspirante.docscompletos@test.com',
                'password' => $password,
                'role' => Role::Aspirante,
                'active' => true,
                'ficha_id' => $ficha->id,
                'email_verified_at' => now(),
            ]);
        } elseif ($aspirante->ficha_id === null) {
            $aspirante->forceFill(['ficha_id' => $ficha->id])->save();
        }

        $estadoPublicada = Parametro::porTemaYCodigo('estado_convocatoria', ConvocatoriaEstado::Publicada->value);
        $estadoPostulada = Parametro::porTemaYCodigo('estado_postulacion', PostulacionEstado::Postulada->value);

        if (! $estadoPublicada || ! $estadoPostulada) {
            return;
        }

        // Tipos de documento requeridos.
        $docIds = [];
        foreach (['documento_identidad', 'hoja_de_vida', 'certificados_academicos'] as $codigo) {
            $p = Parametro::porTemaYCodigo('tipo_documento', $codigo);
            if ($p) {
                $docIds[] = $p->id;
            }
        }

        if (empty($docIds)) {
            return;
        }

        // Convocatoria con documentos requeridos.
        $convocatoria = Convocatoria::firstOrCreate(
            ['ficha_id' => $ficha->id, 'nombre' => 'Convocatoria documentos completos'],
            [
                'codigo' => 'CONV-2026-002',
                'nombre' => 'Convocatoria documentos completos',
                'cargo' => 'Auxiliar Administrativo',
                'descripcion' => 'Convocatoria con todos los documentos cargados, para probar la evaluación.',
                'ficha_id' => $ficha->id,
                'creado_por' => $instructor?->id,
                'tipo_convocatoria_id' => Parametro::porTemaYCodigo('tipo_convocatoria', 'externo')->id,
                'tipo_empleo_id' => Parametro::porTemaYCodigo('tipo_empleo', 'tiempo_completo')->id,
                'tipo_contrato_id' => Parametro::porTemaYCodigo('tipo_contrato', 'termino_fijo')->id,
                'modalidad_id' => Parametro::porTemaYCodigo('modalidad', 'presencial')->id,
                'estado_id' => $estadoPublicada->id,
                'vacantes' => 5,
                'fecha_apertura' => now()->toDateString(),
                'fecha_cierre' => now()->addMonth()->toDateString(),
                'fecha_evaluacion' => now()->addMonths(2)->toDateString(),
            ],
        );

        $convocatoria->forceFill(['estado_id' => $estadoPublicada->id])->save();

        // Skills requeridas por la convocatoria (texto libre).
        $skillNombres = ['Excel avanzado', 'Atención al cliente', 'Manejo de ERP'];
        $skillIds = [];
        foreach ($skillNombres as $nombre) {
            $skill = ConvocatoriaSkill::firstOrCreate(
                ['convocatoria_id' => $convocatoria->id, 'nombre' => $nombre],
            );
            $skillIds[] = $skill->id;
        }

        // Adjunta los documentos requeridos a la convocatoria (pivot).
        foreach ($docIds as $docId) {
            $convocatoria->documentos()->syncWithoutDetaching([$docId => ['obligatorio' => true]]);
        }

        // Postulación del aspirante (postulada).
        $postulacion = Postulacion::firstOrCreate(
            ['convocatoria_id' => $convocatoria->id, 'user_id' => $aspirante->id],
            [
                'estado_id' => $estadoPostulada->id,
                'fecha_postulacion' => now(),
            ],
        );

        // Carga todos los documentos requeridos (marca como 'subido').
        foreach ($docIds as $docId) {
            PostulacionDocumento::firstOrCreate(
                ['postulacion_id' => $postulacion->id, 'parametro_id' => $docId],
                [
                    'postulacion_id' => $postulacion->id,
                    'parametro_id' => $docId,
                    'titulo' => null,
                    'nota' => null,
                    'nombre_original' => 'documento.pdf',
                    'ruta' => 'postulaciones/'.$postulacion->id.'/doc_'.$docId.'.pdf',
                    'disco' => 'local',
                    'mime' => 'application/pdf',
                    'tamano_bytes' => 1024,
                ],
            );
        }

        // Aspirante declara las skills con certificado.
        foreach ($skillNombres as $nombre) {
            PostulacionSkill::firstOrCreate(
                ['postulacion_id' => $postulacion->id, 'nombre' => $nombre],
                [
                    'postulacion_id' => $postulacion->id,
                    'convocatoria_skill_id' => ConvocatoriaSkill::where('convocatoria_id', $convocatoria->id)->where('nombre', $nombre)->value('id'),
                    'nombre' => $nombre,
                    'certificado_nombre_original' => $nombre.'.pdf',
                    'certificado_ruta' => 'postulaciones/'.$postulacion->id.'/skill_'.$nombre.'.pdf',
                    'certificado_disco' => 'local',
                    'certificado_mime' => 'application/pdf',
                    'certificado_tamano_bytes' => 2048,
                ],
            );
        }
    }
}
