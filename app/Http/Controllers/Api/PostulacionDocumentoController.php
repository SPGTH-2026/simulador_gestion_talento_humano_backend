<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\GestionaPostulacionPropia;
use App\Http\Controllers\Api\Concerns\GestionaArchivosDocumentos;
use App\Http\Controllers\Api\Concerns\IdentificadorConvocatoria;
use App\Http\Controllers\Controller;
use App\Models\PerfilAspirante;
use App\Models\Postulacion;
use App\Models\PostulacionDocumento;
use App\Services\DocumentosDePostulacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use App\Http\Controllers\Api\Concerns\PresentaDocumentos;
use App\Http\Controllers\Api\Concerns\GuardaDocumentos;

/**
 * Paso de documentos de una postulación.
 *
 * - El aspirante dueño ve el estado, sube/reemplaza, borra y descarga sus
 *   documentos y adicionales. Una postulación ajena o inexistente -> 404.
 * - Solo se edita con la convocatoria publicada y dentro de la ventana, y con la
 *   postulación en 'borrador' (antes de enviar) o 'postulada' (después). Una
 *   'retirada' también es editable mientras la ventana esté abierta.
 * - El envío (PostulacionController::enviar) exige los obligatorios completos;
 *   aquí el campo `completo` se calcula con DocumentosDePostulacion, la misma
 *   regla que valida el envío.
 * - Los revisores (permiso 'documentos:ver') pueden LEER y descargar ajenos,
 *   respetando el alcance por ficha. Un borrador no entra a revisión (404).
 */
class PostulacionDocumentoController extends Controller
{
    use GuardaDocumentos;

    use PresentaDocumentos;

    use GestionaPostulacionPropia;
    use GestionaArchivosDocumentos;
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

        return $this->guardarRequerido($request, $postulacion);
    }

    /** Sube un documento ADICIONAL ("tipo otro"): título obligatorio, nota opcional, máx. config. */
    public function storeAdicional(Request $request, Postulacion $postulacion): JsonResponse
    {
        $this->exigirPropia($request, $postulacion);
        $this->exigirEditable($postulacion, 'documentos');
        $this->exigirSinCorreccionVigente($postulacion);

        return $this->guardarAdicional($request, $postulacion);
    }

    /** Borra un documento (requerido o adicional) de MI postulación. */
    public function destroy(Request $request, Postulacion $postulacion, PostulacionDocumento $documento): JsonResponse
    {
        $this->exigirPropia($request, $postulacion);
        $this->exigirDocumentoDeLaPostulacion($postulacion, $documento);
        $this->exigirEditable($postulacion, 'documentos');
        $this->exigirSinCorreccionVigente($postulacion);
        $this->exigirQueNoSeaObligatorio($postulacion, $documento);

        $this->eliminarDocumento($documento);

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

    // ── Escritura compartida (dueño y gestor documental) ────────────────

    // ── Reglas de acceso ───────────────────────────────────────────────

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
        return $this->puedeEditar($postulacion) || $this->correccionVigente($postulacion) !== null;
    }

    // ── Archivos ───────────────────────────────────────────────────────

    private function descargar(Postulacion $postulacion, PostulacionDocumento $documento, bool $inline = false): Response
    {
        $this->exigirDocumentoDeLaPostulacion($postulacion, $documento);

        // inline: el navegador lo muestra en la pestaña (Visor PDF del front).
        // attachment: descarga clásica, el comportamiento histórico.
        return $this->leerArchivo(
            $documento->disco,
            $documento->ruta,
            $documento->nombre_original,
            $documento->mime,
            $inline,
        );
    }

    // ── Presentación ───────────────────────────────────────────────────

    // ── Mensajes en español ────────────────────────────────────────────

}
