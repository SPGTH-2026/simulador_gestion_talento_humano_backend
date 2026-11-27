<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Models\Convocatoria;
use App\Models\ConvocatoriaSkill;
use App\Models\Parametro;
use App\Models\PerfilAspirante;
use App\Models\Postulacion;
use App\Models\PostulacionDocumento;
use App\Models\User;
use App\Services\DatosPersonalesDePostulacion;
use App\Services\Documentos\ReglaLibreta;
use App\Services\DocumentosDePostulacion;

trait PresentaDocumentos
{
    private function presentarEstado(Postulacion $postulacion, bool $editable, DocumentosDePostulacion $documentosService, User $user): array
    {
        $postulacion->load([
            'estado',
            'convocatoria.estado',
            'convocatoria.documentos',
            'convocatoria.skills',
            'documentos',
            'skills',
        ]);

        $porParametro = $postulacion->documentos->keyBy('parametro_id');

        $perfil = PerfilAspirante::query()
            ->where('user_id', $postulacion->user_id)
            ->with('sexo')
            ->first();

        $libretaNoAplica = ReglaLibreta::noAplica($perfil, $postulacion->datosPersonales);

        $pideLibreta = $postulacion->convocatoria->documentos
            ->contains(fn (Parametro $d) => $d->codigo === Parametro::DOC_LIBRETA_MILITAR);

        // El frente pregunta el sexo SOLO si la convocatoria pide la libreta
        // militar y el aspirante aún no lo ha registrado. Ya registrado, no hay
        // que volver a pedirlo aunque la convocatoria siga pidiendo la libreta.
        $requiereSexo = $pideLibreta && $perfil === null;

        $documentos = $postulacion->convocatoria->documentos
            ->map(function (Parametro $parametro) use ($porParametro, $libretaNoAplica) {
                $subido = $porParametro->get($parametro->id);
                $noAplica = $parametro->codigo === Parametro::DOC_LIBRETA_MILITAR && $libretaNoAplica;

                return [
                    'parametro' => [
                        'id' => $parametro->id,
                        'codigo' => $parametro->codigo,
                        'nombre' => $parametro->nombre,
                    ],
                    'obligatorio' => (bool) $parametro->pivot->obligatorio,
                    // Ítems del checklist de este documento: una lista, un ítem por línea.
                    'items' => $this->itemsDeDocumento($parametro->pivot->items),
                    // Peso (%) configurado por el gestor para este documento en esta convocatoria. null = sin configurar (reparto igual).
                    'ponderacion' => $parametro->pivot->ponderacion === null
                        ? null
                        : round((float) $parametro->pivot->ponderacion, 2),
                    'estado' => $noAplica ? 'no_aplica' : ($subido ? 'subido' : 'pendiente'),
                    'archivo' => $subido ? $this->archivoResumen($subido) : null,
                ];
            })
            ->values();

        // Completo = todos los documentos OBLIGATORIOS están "subidos" o en
        // "no_aplica" (la libreta de una mujer no se exige). Los opcionales y los
        // adicionales no afectan la completitud. Misma regla que valida el envío.
        $completo = $documentosService->completo($postulacion);

        $adicionales = $postulacion->documentos
            ->whereNull('parametro_id')
            ->map(fn (PostulacionDocumento $d) => $this->adicionalResumen($d))
            ->values();

        $skills = $postulacion->skills
            ->map(fn ($skill) => $this->skillResumen($skill))
            ->values();

        return [
            'postulacion' => [
                'id' => $postulacion->id,
                'user_id' => $postulacion->user_id,
                'estado' => [
                    'codigo' => $postulacion->estado?->codigo,
                    'nombre' => $postulacion->estado?->nombre,
                ],
            ],
            'convocatoria' => $this->convocatoriaResumen($postulacion->convocatoria, $user),
            'ventana' => $postulacion->convocatoria->ventanaPostulacion()->value,
            'editable' => $editable,
            'motivo_edicion' => $editable ? null : $this->motivoNoEditable($postulacion),
            'fecha_evaluacion' => $postulacion->convocatoria->fecha_evaluacion?->toDateString(),
            'completo' => $completo,
            'requiere_sexo' => $requiereSexo,
            'sexo' => $perfil?->sexo
                ? ['id' => $perfil->sexo->id, 'codigo' => $perfil->sexo->codigo, 'nombre' => $perfil->sexo->nombre]
                : null,
            'datos_personales' => app(DatosPersonalesDePostulacion::class)->presentar($postulacion),
            'documentos' => $documentos,
            'adicionales' => $adicionales,
            'skills' => $skills,
            'skills_convocatoria' => $postulacion->convocatoria->skills
                ->map(fn (ConvocatoriaSkill $skill) => [
                    'id' => $skill->id,
                    'nombre' => $skill->nombre,
                ])
                ->values(),
        ];
    }

    /**
     * Resumen mínimo de la convocatoria para encabezar el paso en el front.
     *
     * El `codigo` (CONV-AAAA-NNN) es un dato de GESTIÓN: solo lo recibe quien
     * está en la lista blanca (el revisor: instructor, super admin o el
     * subrol gestor_convocatorias). Para el dueño de la postulación llega en
     * null.
     */
    private function convocatoriaResumen(Convocatoria $convocatoria, User $user): array
    {
        return [
            'id' => $convocatoria->id,
            'nombre' => $convocatoria->nombre,
            'codigo' => $this->veIdentificadorConvocatoria($user) ? $convocatoria->codigo : null,
        ];
    }

    private function archivoResumen(PostulacionDocumento $documento): array
    {
        return [
            'id' => $documento->id,
            'nombre_original' => $documento->nombre_original,
            'mime' => $documento->mime,
            'tamano_bytes' => $documento->tamano_bytes,
            'subido_en' => $documento->created_at?->toIso8601String(),
        ];
    }

    private function adicionalResumen(PostulacionDocumento $documento): array
    {
        return [
            'id' => $documento->id,
            'titulo' => $documento->titulo,
            'nota' => $documento->nota,
            'archivo' => $this->archivoResumen($documento),
        ];
    }

    private function skillResumen($skill): array
    {
        return [
            'id' => $skill->id,
            'nombre' => $skill->nombre,
            'origen' => $skill->origen(),
            'certificado' => $skill->tieneCertificado()
                ? [
                    'id' => $skill->id,
                    'nombre_original' => $skill->certificado_nombre_original,
                    'mime' => $skill->certificado_mime,
                    'tamano_bytes' => $skill->certificado_tamano_bytes,
                ]
                : null,
        ];
    }

    /** @return array<string, string> */
    private function mensajes(): array
    {
        return [
            'required' => 'El campo :attribute es obligatorio.',
            'string' => 'El campo :attribute debe ser texto.',
            'max' => 'El campo :attribute no debe superar :max caracteres.',
            'codigo.required' => 'El tipo de documento es obligatorio.',
            'parametro_id.required' => 'El tipo de documento es obligatorio.',
            'titulo.required' => 'El título del documento adicional es obligatorio.',
            'archivo.required' => 'El archivo es obligatorio.',
            'archivo.file' => 'El archivo no es válido.',
            'archivo.mimes' => 'El archivo debe ser un PDF.',
            'archivo.max' => 'El archivo no debe superar los '.config('convocatorias.tamano_maximo_mb').' MB.',
        ];
    }

    /** @return array<string, string> */
    private function atributos(): array
    {
        return [
            'codigo' => 'tipo de documento',
            'parametro_id' => 'tipo de documento',
            'titulo' => 'título',
            'nota' => 'nota',
            'archivo' => 'archivo',
        ];
    }

    /** Ítems del checklist de un documento: un ítem por línea, sin líneas vacías. */
    private function itemsDeDocumento(?string $texto): array
    {
        if ($texto === null) {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode("
", $texto)), fn ($linea) => $linea !== ''));
    }
}
