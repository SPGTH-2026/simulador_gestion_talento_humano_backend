<?php

namespace App\Http\Controllers\Api;

use App\Enums\PostulacionEstado;
use App\Enums\VentanaPostulacion;
use App\Http\Controllers\Api\Concerns\GestionaPostulacionPropia;
use App\Http\Controllers\Api\Concerns\IdentificadorConvocatoria;
use App\Http\Controllers\Controller;
use App\Models\Convocatoria;
use App\Models\ConvocatoriaSkill;
use App\Models\Parametro;
use App\Models\PerfilAspirante;
use App\Models\Postulacion;
use App\Models\PostulacionDocumento;
use App\Models\User;
use App\Services\DocumentosDePostulacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Paso de documentos de una postulación.
 *
 * - El aspirante dueño ve el estado, sube/reemplaza, borra y descarga sus
 *   documentos y adicionales. Una postulación ajena o inexistente -> 404.
 * - Solo se edita con la convocatoria publicada y dentro de la ventana, y con la
 *   postulación en 'borrador' (antes de enviar) o 'postulada' (después). Una
 *   'retirada' queda bloqueada hasta reabrirla.
 * - El envío (PostulacionController::enviar) exige los obligatorios completos;
 *   aquí el campo `completo` se calcula con DocumentosDePostulacion, la misma
 *   regla que valida el envío.
 * - Los revisores (permiso 'documentos:ver') pueden LEER y descargar ajenos,
 *   respetando el alcance por ficha. Un borrador no entra a revisión (404).
 */
class PostulacionDocumentoController extends Controller
{
    use GestionaPostulacionPropia;
    use IdentificadorConvocatoria;

    /** Estado de documentos de MI postulación (dueño). */
    public function show(Request $request, Postulacion $postulacion, DocumentosDePostulacion $documentos): JsonResponse
    {
        $this->exigirPropia($request, $postulacion);

        return response()->json([
            'data' => $this->presentarEstado($postulacion, $this->esEditable($postulacion), $documentos, $request->user()),
        ]);
    }

    /** Estado de documentos de una postulación ajena (revisores, solo lectura). */
    public function showRevision(Request $request, Postulacion $postulacion, DocumentosDePostulacion $documentos): JsonResponse
    {
        $this->exigirRevision($request, $postulacion);

        return response()->json([
            'data' => $this->presentarEstado($postulacion, false, $documentos, $request->user()),
        ]);
    }

    /**
     * Sube o REEMPLAZA un documento requerido por la convocatoria. 201 si es
     * nuevo; 200 si reemplaza el existente. El tipo se resuelve por CÓDIGO.
     */
    public function store(Request $request, Postulacion $postulacion): JsonResponse
    {
        $this->exigirPropia($request, $postulacion);
        $this->exigirEditable($postulacion, 'documentos');

        $datos = $request->validate([
            'codigo' => ['required', 'string'],
            'archivo' => $this->reglasArchivo(),
        ], $this->mensajes(), $this->atributos());

        $parametro = Parametro::porTemaYCodigo('tipo_documento', $datos['codigo']);

        if (! $parametro || ! $this->convocatoriaPide($postulacion, $parametro->id)) {
            throw ValidationException::withMessages([
                'codigo' => 'Ese documento no hace parte de esta convocatoria.',
            ]);
        }

        if ($parametro->codigo === Parametro::DOC_LIBRETA_MILITAR) {
            $this->exigirLibretaAplicable($postulacion);
        }

        $nuevo = $this->guardarArchivo(
            $request->file('archivo'),
            "postulaciones/{$postulacion->id}/documentos",
        );

        $existente = PostulacionDocumento::query()
            ->where('postulacion_id', $postulacion->id)
            ->where('parametro_id', $parametro->id)
            ->first();

        if ($existente) {
            $anterior = [$existente->disco, $existente->ruta];
            $existente->update($nuevo);
            $this->borrarArchivo(...$anterior);
            $documento = $existente->fresh();
            $codigoHttp = 200;
        } else {
            $documento = PostulacionDocumento::create($nuevo + [
                'postulacion_id' => $postulacion->id,
                'parametro_id' => $parametro->id,
            ]);
            $codigoHttp = 201;
        }

        return response()->json(['data' => $this->archivoResumen($documento)], $codigoHttp);
    }

    /** Sube un documento ADICIONAL ("tipo otro"): título obligatorio, nota opcional, máx. config. */
    public function storeAdicional(Request $request, Postulacion $postulacion): JsonResponse
    {
        $this->exigirPropia($request, $postulacion);
        $this->exigirEditable($postulacion, 'documentos');

        $maxAdicionales = (int) config('convocatorias.documentos_adicionales_max');

        $datos = $request->validate([
            'titulo' => ['required', 'string', 'max:'.config('convocatorias.titulo_adicional_max_longitud')],
            'nota' => ['nullable', 'string', 'max:'.config('convocatorias.nota_adicional_max_longitud')],
            'archivo' => $this->reglasArchivo(),
        ], $this->mensajes(), $this->atributos());

        $actuales = PostulacionDocumento::query()
            ->where('postulacion_id', $postulacion->id)
            ->whereNull('parametro_id')
            ->count();

        if ($actuales >= $maxAdicionales) {
            throw ValidationException::withMessages([
                'titulo' => "No puedes agregar más de {$maxAdicionales} documentos adicionales.",
            ]);
        }

        $nuevo = $this->guardarArchivo(
            $request->file('archivo'),
            "postulaciones/{$postulacion->id}/adicionales",
        );

        $documento = PostulacionDocumento::create($nuevo + [
            'postulacion_id' => $postulacion->id,
            'parametro_id' => null,
            'titulo' => $datos['titulo'],
            'nota' => $datos['nota'] ?? null,
        ]);

        return response()->json(['data' => $this->adicionalResumen($documento)], 201);
    }

    /** Borra un documento (requerido o adicional) de MI postulación. */
    public function destroy(Request $request, Postulacion $postulacion, PostulacionDocumento $documento): JsonResponse
    {
        $this->exigirPropia($request, $postulacion);
        $this->exigirDocumentoDeLaPostulacion($postulacion, $documento);
        $this->exigirEditable($postulacion, 'documentos');

        $disco = $documento->disco;
        $ruta = $documento->ruta;
        $documento->delete();
        $this->borrarArchivo($disco, $ruta);

        return response()->json(null, 204);
    }

    /** Entrega el archivo (dueño). Por defecto attachment; ?inline=1 lo deja en la pestaña. */
    public function archivo(Request $request, Postulacion $postulacion, PostulacionDocumento $documento): Response
    {
        $this->exigirPropia($request, $postulacion);

        return $this->descargar($postulacion, $documento, $request->boolean('inline'));
    }

    /** Entrega el archivo (revisor con 'documentos:ver'). Igual que archivo(): ?inline=1. */
    public function archivoRevision(Request $request, Postulacion $postulacion, PostulacionDocumento $documento): Response
    {
        $this->exigirRevision($request, $postulacion);

        return $this->descargar($postulacion, $documento, $request->boolean('inline'));
    }

    // ── Reglas de acceso ───────────────────────────────────────────────

    private function exigirDocumentoDeLaPostulacion(Postulacion $postulacion, PostulacionDocumento $documento): void
    {
        if ($documento->postulacion_id !== $postulacion->id) {
            abort(404, 'Documento no encontrado.');
        }
    }

    /** La libreta militar solo aplica a hombres, y exige tener el sexo registrado. */
    private function exigirLibretaAplicable(Postulacion $postulacion): void
    {
        $perfil = PerfilAspirante::query()
            ->where('user_id', $postulacion->user_id)
            ->with('sexo')
            ->first();

        if (! $perfil) {
            throw ValidationException::withMessages([
                'codigo' => 'Antes de subir la libreta militar debes indicar tu sexo.',
            ]);
        }

        if ($perfil->sexo?->codigo === 'mujer') {
            throw ValidationException::withMessages([
                'codigo' => 'La libreta militar no aplica: tu perfil está registrado como femenino.',
            ]);
        }
    }

    private function convocatoriaPide(Postulacion $postulacion, int $parametroId): bool
    {
        $postulacion->loadMissing('convocatoria.documentos');

        return $postulacion->convocatoria->documentos->contains('id', $parametroId);
    }

    private function esEditable(Postulacion $postulacion): bool
    {
        return $this->puedeEditar($postulacion);
    }

    // ── Archivos ───────────────────────────────────────────────────────

    private function descargar(Postulacion $postulacion, PostulacionDocumento $documento, bool $inline = false): Response
    {
        $this->exigirDocumentoDeLaPostulacion($postulacion, $documento);

        $disco = Storage::disk($documento->disco);

        if (! $disco->exists($documento->ruta)) {
            abort(404, 'Documento no encontrado.');
        }

        // inline: el navegador lo muestra en la pestaña (Visor PDF del front).
        // attachment: descarga clásica, el comportamiento histórico.
        if ($inline) {
            return $disco->response($documento->ruta, $documento->nombre_original);
        }

        return $disco->download($documento->ruta, $documento->nombre_original);
    }

    // ── Presentación ───────────────────────────────────────────────────

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

        $sexoMujer = $perfil?->sexo?->codigo === 'mujer';

        $pideLibreta = $postulacion->convocatoria->documentos
            ->contains(fn (Parametro $d) => $d->codigo === Parametro::DOC_LIBRETA_MILITAR);

        // El frente pregunta el sexo SOLO si la convocatoria pide la libreta
        // militar y el aspirante aún no lo ha registrado. Ya registrado, no hay
        // que volver a pedirlo aunque la convocatoria siga pidiendo la libreta.
        $requiereSexo = $pideLibreta && $perfil === null;

        $documentos = $postulacion->convocatoria->documentos
            ->map(function (Parametro $parametro) use ($porParametro, $sexoMujer) {
                $subido = $porParametro->get($parametro->id);
                $noAplica = $parametro->codigo === Parametro::DOC_LIBRETA_MILITAR && $sexoMujer;

                return [
                    'parametro' => [
                        'id' => $parametro->id,
                        'codigo' => $parametro->codigo,
                        'nombre' => $parametro->nombre,
                    ],
                    'obligatorio' => (bool) $parametro->pivot->obligatorio,
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

    // ── Mensajes en español ────────────────────────────────────────────

    /** @return array<string, string> */
    private function mensajes(): array
    {
        return [
            'required' => 'El campo :attribute es obligatorio.',
            'string' => 'El campo :attribute debe ser texto.',
            'max' => 'El campo :attribute no debe superar :max caracteres.',
            'codigo.required' => 'El tipo de documento es obligatorio.',
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
            'titulo' => 'título',
            'nota' => 'nota',
            'archivo' => 'archivo',
        ];
    }
}
