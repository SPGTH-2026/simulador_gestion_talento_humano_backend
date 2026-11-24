<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notificacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Campana de notificaciones del usuario autenticado. Cada consulta filtra por el usuario: nadie ve ni marca avisos ajenos
 * (un aviso ajeno responde 404, sin revelar que existe).
 */
class NotificacionController extends Controller
{
    /**
     * Lista paginada, la más reciente primero. `meta.no_leidas` es el número que muestra la campana.
     */
    public function index(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $base = Notificacion::query()->where('user_id', $request->user()->id);

        $listado = (clone $base)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($datos['per_page'] ?? 20, ['*'], 'page', $datos['page'] ?? 1);

        return response()->json([
            'data' => $listado->getCollection()->map(fn (Notificacion $n) => $this->presentar($n))->values(),
            'meta' => [
                'current_page' => $listado->currentPage(),
                'last_page' => $listado->lastPage(),
                'per_page' => $listado->perPage(),
                'total' => $listado->total(),
                'no_leidas' => (clone $base)->noLeidas()->count(),
            ],
        ]);
    }

    /** Marca una notificación propia como leída. Repetirlo no cambia la fecha de lectura. */
    public function leer(Request $request, Notificacion $notificacion): JsonResponse
    {
        if ($notificacion->user_id !== $request->user()->id) {
            abort(404, 'Notificación no encontrada.');
        }

        if (! $notificacion->estaLeida()) {
            // `leida_at` no es asignable en masa: la fija solo el sistema, nunca lo que llega en la petición.
            $notificacion->forceFill(['leida_at' => now()])->save();
        }

        return response()->json(['data' => $this->presentar($notificacion->fresh())]);
    }

    /** Marca como leídas todas las notificaciones propias pendientes. Responde cuántas cambiaron. */
    public function leerTodas(Request $request): JsonResponse
    {
        $marcadas = Notificacion::query()
            ->where('user_id', $request->user()->id)
            ->noLeidas()
            ->update(['leida_at' => now()]);

        return response()->json(['data' => ['marcadas' => $marcadas]]);
    }

    /** @return array<string, mixed> */
    private function presentar(Notificacion $n): array
    {
        return [
            'id' => $n->id,
            'tipo' => $n->tipo->value,
            'titulo' => $n->titulo,
            'mensaje' => $n->mensaje,
            'url' => $n->url,
            'leida' => $n->estaLeida(),
            'leida_at' => $n->leida_at?->toISOString(),
            'created_at' => $n->created_at?->toISOString(),
        ];
    }
}
