<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AceptacionLegal;
use App\Models\DocumentoLegal;
use App\Services\Legal\GestorTextosLegales;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Aceptaciones del propio usuario autenticado. El usuario sale SIEMPRE de la sesión: no hay forma de registrar una aceptación por otra
 * persona (el cuerpo de la petición no se lee). No se guardan IP ni datos del navegador. Detrás de la bandera legal.aceptaciones_activas.
 */
class AceptacionLegalController extends Controller
{
    /** 201 la primera vez; 200 si ya estaba aceptada (idempotente, no crea otra). Un borrador responde 422. */
    public function store(Request $request, DocumentoLegal $documento, GestorTextosLegales $gestor): JsonResponse
    {
        [$aceptacion, $creada] = $gestor->aceptar($request->user(), $documento);

        return response()->json(['data' => $this->presentar($aceptacion->setRelation('documento', $documento))], $creada ? 201 : 200);
    }

    /** Las aceptaciones propias, la más reciente primero. */
    public function index(Request $request): JsonResponse
    {
        $propias = AceptacionLegal::with('documento')->where('user_id', $request->user()->id)->orderByDesc('aceptado_en')->orderByDesc('id')->get();

        return response()->json(['data' => $propias->map(fn ($a) => $this->presentar($a))->all()]);
    }

    /** @return array<string, mixed> */
    private function presentar(AceptacionLegal $a): array
    {
        return [
            'id' => $a->id, 'documento_id' => $a->documento_legal_id, 'tipo' => $a->documento->tipo, 'version' => $a->documento->version,
            'huella' => $a->huella_aceptada, 'aceptado_en' => $a->aceptado_en->toIso8601String(),
        ];
    }
}
