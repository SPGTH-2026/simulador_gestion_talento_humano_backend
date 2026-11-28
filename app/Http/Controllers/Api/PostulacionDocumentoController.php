<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\GestionaPostulacionPropia;
use App\Http\Controllers\Api\Concerns\IdentificadorConvocatoria;
use App\Http\Controllers\Api\Concerns\PresentaDocumentos;
use App\Http\Controllers\Controller;
use App\Models\Parametro;
use App\Models\Postulacion;
use App\Models\PostulacionDocumento;
use App\Services\Documentos\AlmacenArchivos;
use App\Services\Documentos\EscribeDocumentoPostulacion;
use App\Services\DocumentosDePostulacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Paso de documentos de una postulación.
 *
 * Este controlador solo hace la parte HTTP: valida la entrada, comprueba el acceso y responde. La escritura (reglas,
 * transacciones, invalidación de evaluaciones) está en EscribeDocumentoPostulacion; el disco, en AlmacenArchivos.
 *
 * - El aspirante dueño ve el estado, sube/reemplaza, borra y descarga sus documentos. Una postulación ajena
 *   o inexistente -> 404.
 * - Solo se edita con la convocatoria publicada y dentro de la ventana, y con la postulación en 'borrador' (antes de
 *   enviar) o 'postulada' (después). Una 'retirada' también es editable mientras la ventana esté abierta.
 * - El envío (PostulacionController::enviar) exige los obligatorios completos; aquí el campo `completo` se calcula con
 *   DocumentosDePostulacion, la misma regla que valida el envío.
 * - Los revisores (permiso 'documentos:ver') pueden LEER y descargar ajenos, respetando el alcance por ficha. Un borrador
 *   no entra a revisión (404).
 */
class PostulacionDocumentoController extends Controller
{
    use GestionaPostulacionPropia;
    use IdentificadorConvocatoria;
    use PresentaDocumentos;

    public function __construct(
        private AlmacenArchivos $almacen,
        private EscribeDocumentoPostulacion $escribe,
    ) {}

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
     * Sube o REEMPLAZA un documento requerido por la convocatoria. 201 si es nuevo; 200 si reemplaza el existente. El tipo
     * se resuelve por CÓDIGO (documentos de sistema) o por ID (documentos creados por el gestor).
     */
    public function store(Request $request, Postulacion $postulacion): JsonResponse
    {
        $this->exigirPropia($request, $postulacion);
        $this->exigirEditable($postulacion, 'documentos');

        $datos = $request->validate([
            'codigo' => ['nullable', 'string', 'required_without:parametro_id'],
            'parametro_id' => ['nullable', 'integer', 'required_without:codigo'],
            'archivo' => $this->almacen->reglas(),
        ], $this->mensajes(), $this->atributos());

        // Un documento del catálogo puede identificarse por código (documentos de sistema) o por id (documentos NUEVOS
        // creados por el gestor, que nacen sin código). Se resuelve por código solo si vino.
        $campo = empty($datos['parametro_id']) ? 'codigo' : 'parametro_id';

        $parametro = empty($datos['parametro_id'])
            ? Parametro::porTemaYCodigo('tipo_documento', $datos['codigo'])
            : Parametro::query()
                ->whereKey($datos['parametro_id'])
                ->whereHas('tema', fn ($q) => $q->where('codigo', 'tipo_documento'))
                ->first();

        [$documento, $codigoHttp] = $this->escribe->guardarRequerido(
            $postulacion,
            $parametro,
            $campo,
            $request->file('archivo'),
            $this->correccionVigente($postulacion),
        );

        return response()->json(['data' => $this->archivoResumen($documento)], $codigoHttp);
    }

    /** Borra un documento de MI postulación. */
    public function destroy(Request $request, Postulacion $postulacion, PostulacionDocumento $documento): JsonResponse
    {
        $this->exigirPropia($request, $postulacion);
        $this->exigirDocumentoDeLaPostulacion($postulacion, $documento);
        $this->exigirEditable($postulacion, 'documentos');
        $this->exigirSinCorreccionVigente($postulacion);

        $this->escribe->eliminar($documento);

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

    private function esEditable(Postulacion $postulacion): bool
    {
        return $this->puedeEditar($postulacion) || $this->correccionVigente($postulacion) !== null;
    }

    private function exigirDocumentoDeLaPostulacion(Postulacion $postulacion, PostulacionDocumento $documento): void
    {
        if ($documento->postulacion_id !== $postulacion->id) {
            abort(404, 'Documento no encontrado.');
        }
    }

    private function descargar(Postulacion $postulacion, PostulacionDocumento $documento, bool $inline = false): Response
    {
        $this->exigirDocumentoDeLaPostulacion($postulacion, $documento);

        // inline: el navegador lo muestra en la pestaña (Visor PDF del front). attachment: descarga clásica.
        return $this->almacen->leer(
            $documento->disco,
            $documento->ruta,
            $documento->nombre_original,
            $documento->mime,
            $inline,
        );
    }
}
