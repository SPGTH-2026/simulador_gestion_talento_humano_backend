<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notificacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Campana de notificaciones del usuario autenticado. Cada consulta filtra por el usuario: nadie ve ni marca avisos ajenos
 * (un aviso ajeno responde 404, sin revelar que existe). Ocultar y restaurar no borran nada ni cambian el estado de lectura: los
 * ocultos salen de la lista normal y del contador de pendientes.
 */
class NotificacionController extends Controller
{
    private const ESTADOS = ['todas', 'no_leidas', 'leidas', 'ocultas'];

    /**
     * Lista paginada, la más reciente primero. `estado` filtra en el servidor (la paginación es sobre el conjunto filtrado):
     * `todas` (por defecto, sin las ocultas), `no_leidas`, `leidas` (ambas sin ocultas) u `ocultas`. `meta.no_leidas` es el número
     * que muestra la campana (visibles sin leer) y no depende del filtro; `meta.ocultas` cuenta las ocultas.
     */
    public function index(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'estado' => ['nullable', 'string', Rule::in(self::ESTADOS)],
        ], ['estado.in' => 'El filtro de notificaciones no es válido.']);

        $base = Notificacion::query()->where('user_id', $request->user()->id);
        $estado = $datos['estado'] ?? 'todas';
        $filtrada = (clone $base)->when($estado === 'ocultas', fn ($q) => $q->ocultas(), fn ($q) => $q->visibles())
            ->when($estado === 'no_leidas', fn ($q) => $q->noLeidas())
            ->when($estado === 'leidas', fn ($q) => $q->whereNotNull('leida_at'));

        $listado = $filtrada
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
                'no_leidas' => (clone $base)->visibles()->noLeidas()->count(),
                'ocultas' => (clone $base)->ocultas()->count(),
                // Días tras los que se eliminan ocultas y leídas; null si la depuración está desactivada (nada se elimina).
                'retencion' => config('notificaciones.depuracion_activa')
                    ? ['ocultas_dias' => (int) config('notificaciones.dias_ocultas'), 'leidas_dias' => (int) config('notificaciones.dias_leidas')]
                    : null,
            ],
        ]);
    }

    /** Marca una notificación propia como leída. Repetirlo no cambia la fecha de lectura. */
    public function leer(Request $request, Notificacion $notificacion): JsonResponse
    {
        $this->propia($request, $notificacion);

        if (! $notificacion->estaLeida()) {
            // `leida_at` no es asignable en masa: la fija solo el sistema, nunca lo que llega en la petición.
            $notificacion->forceFill(['leida_at' => now()])->save();
        }

        return response()->json(['data' => $this->presentar($notificacion->fresh())]);
    }

    /** Oculta una notificación propia (idempotente). No cambia si está leída: solo sale de la lista normal y del contador. */
    public function ocultar(Request $request, Notificacion $notificacion): JsonResponse
    {
        $this->propia($request, $notificacion);
        if (! $notificacion->estaOculta()) {
            $notificacion->forceFill(['ocultada_at' => now()])->save();
        }

        return response()->json(['data' => $this->presentar($notificacion->fresh())]);
    }

    /** Devuelve una notificación oculta a la lista normal (idempotente). Vuelve a contar si seguía sin leer. */
    public function restaurar(Request $request, Notificacion $notificacion): JsonResponse
    {
        $this->propia($request, $notificacion);
        if ($notificacion->estaOculta()) {
            $notificacion->forceFill(['ocultada_at' => null])->save();
        }

        return response()->json(['data' => $this->presentar($notificacion->fresh())]);
    }

    private function propia(Request $request, Notificacion $notificacion): void
    {
        if ($notificacion->user_id !== $request->user()->id) {
            abort(404, 'Notificación no encontrada.');
        }
    }

    /** Marca como leídas las notificaciones propias VISIBLES pendientes (las ocultas no cambian). Responde cuántas cambiaron. */
    public function leerTodas(Request $request): JsonResponse
    {
        $marcadas = Notificacion::query()
            ->where('user_id', $request->user()->id)
            ->visibles()
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
            'oculta' => $n->estaOculta(),
            'ocultada_at' => $n->ocultada_at?->toISOString(),
            'created_at' => $n->created_at?->toISOString(),
        ];
    }
}
