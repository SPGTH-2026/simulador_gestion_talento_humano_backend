<?php

namespace App\Http\Controllers\Api;

use App\Enums\PostulacionEstado;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Postulacion;
use App\Models\User;
use App\Services\DocumentosDePostulacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Portafolio documental de UN usuario: sus postulaciones con el estado de sus
 * documentos, para que el instructor (o el super admin) las revise.
 *
 * Aislamiento: el super admin ve todo; el resto solo puede abrir el portafolio
 * de alguien que esté en sus fichas (mismo criterio que el listado de
 * usuarios). Fuera de alcance -> 404, sin revelar siquiera que existe.
 */
class PortafolioController extends Controller
{
    public function __construct(private readonly DocumentosDePostulacion $documentos)
    {
    }

    public function show(Request $request, User $user): JsonResponse
    {
        $actor = $request->user();

        if ($actor->role !== Role::SuperAdmin && ! in_array($user->ficha_id, $actor->fichaIds(), true)) {
            abort(404, 'Portafolio no encontrado.');
        }

        $postulaciones = Postulacion::query()
            ->with(['estado', 'convocatoria.estado', 'documentos', 'skills'])
            ->where('user_id', $user->id)
            ->orderByDesc('fecha_postulacion')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'data' => [
                'usuario' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'ficha' => $user->ficha_id
                        ? [
                            'id' => $user->ficha->id,
                            'codigo' => $user->ficha->codigo,
                            'nombre_programa' => $user->ficha->nombre_programa,
                        ]
                        : null,
                ],
                'postulaciones' => $postulaciones
                    ->map(fn (Postulacion $p) => $this->resumenPostulacion($p))
                    ->values(),
            ],
        ]);
    }

    /** Tarjeta de una postulación: lo que pinta el listado del portafolio. */
    private function resumenPostulacion(Postulacion $p): array
    {
        $requeridos = $p->convocatoria->documentos
            ->filter(fn ($parametro) => (bool) $parametro->pivot->obligatorio);
        $subidosPorParametro = $p->documentos
            ->whereNotNull('parametro_id')
            ->pluck('parametro_id');
        $adicionales = $p->documentos->whereNull('parametro_id')->count();

        $conCertificado = $p->skills->filter->tieneCertificado()->count();

        return [
            'id' => $p->id,
            'estado' => [
                'codigo' => $p->estado?->codigo,
                'nombre' => $p->estado?->nombre,
            ],
            'fecha_postulacion' => $p->fecha_postulacion?->toIso8601String(),
            'fecha_retiro' => $p->fecha_retiro?->toIso8601String(),
            'convocatoria' => [
                'id' => $p->convocatoria->id,
                'codigo' => $p->convocatoria->codigo,
                'nombre' => $p->convocatoria->nombre,
                'cargo' => $p->convocatoria->cargo,
                'area' => $p->convocatoria->area,
            ],
            // Un borrador todavía no es un portafolio: solo lo son las enviadas.
            'puede_ver_documentos' => $p->estado?->codigo !== PostulacionEstado::Borrador->value,
            // Misma regla que la validación del envío (servicio único).
            'completo' => $this->documentos->completo($p),
            'documentos' => [
                'requeridos' => $requeridos->count(),
                'subidos' => $requeridos
                    ->filter(fn ($parametro) => $subidosPorParametro->contains($parametro->id))
                    ->count(),
                'adicionales' => $adicionales,
            ],
            'skills' => [
                'total' => $p->skills->count(),
                'con_certificado' => $conCertificado,
            ],
        ];
    }
}
