<?php

namespace App\Http\Controllers\Api;

use App\Enums\PostulacionEstado;
use App\Enums\Role;
use App\Models\Convocatoria;
use App\Models\Evaluacion;
use App\Models\Ficha;
use App\Models\Postulacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Http\Controllers\Api\Concerns\PresentaEvaluacion;
use App\Http\Controllers\Controller;

class EvaluacionListadoController extends Controller
{
    use PresentaEvaluacion;

    public function index(Request $request): JsonResponse
    {
        $datos = $request->validate([
            // Sin exists:, que revelaría qué IDs existen en otras fichas (422 vs 200). La consulta ya está limitada al alcance:
            // un ID ajeno simplemente no devuelve filas.
            'convocatoria_id' => ['nullable', 'integer'],
            'ficha_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:120'],
        ]);

        $actor = $request->user();

        $query = Postulacion::query()
            ->with([
                'user:id,name,email,ficha_id',
                'user.perfilAspirante.sexo',
                // El estado se lee en cada fila: sin cargarlo aquí sería una consulta por aspirante.
                'estado:id,codigo,nombre',
                'datosPersonales',
                'convocatoria:id,nombre,cargo,estado_id,ficha_id,fecha_evaluacion',
                'convocatoria.estado:id,codigo,nombre',
                'convocatoria.documentos',
                'convocatoria.skills',
                'documentos',
                'skills',
            ])
            ->whereHas('estado', fn ($q) => $q->where('codigo', PostulacionEstado::Postulada->value))
            ->whereHas('convocatoria', fn ($q) => $q->whereIn('ficha_id', $actor->fichaIds()))
            // Un usuario inhabilitado no se evalúa ni consume un cupo (misma regla que el portafolio).
            ->whereHas('user', fn ($u) => $u->where('active', true));

        if ($datos['convocatoria_id'] ?? null) {
            $query->where('convocatoria_id', $datos['convocatoria_id']);
        }
        // Filtro por ficha: la ficha vive en la convocatoria, no en la postulación.
        if ($datos['ficha_id'] ?? null) {
            $query->whereHas('convocatoria', fn ($q) => $q->where('ficha_id', $datos['ficha_id']));
        }
        if ($datos['search'] ?? null) {
            $b = $datos['search'];
            // LIKE es sensible a mayúsculas en PostgreSQL (en MySQL no). Se
            // compara en minúsculas para que el buscador funcione igual en
            // ambas bases.
            $patron = '%'.mb_strtolower($b).'%';
            $query->whereHas('user', fn ($q) => $q
                ->whereRaw('LOWER(name) LIKE ?', [$patron])
                ->orWhereRaw('LOWER(email) LIKE ?', [$patron]));
        }

        $pagina = $query->orderBy('id')->paginate(20);

        $postIds = $pagina->getCollection()->pluck('id');

        // El evaluador ve SU propia evaluación. El instructor/super_admin ve lo
        // que realizaron los evaluadores (cualquier evaluación de la postulación,
        // priorizando la más relevante/reciente) para ir mirando el proceso.
        $evaluaciones = Evaluacion::query()
            ->with('evaluador:id,name,email,subrole')
            ->whereIn('postulacion_id', $postIds)
            ->when(
                ! in_array($actor->role, [Role::Instructor, Role::SuperAdmin], true),
                fn ($q) => $q->where('evaluador_id', $actor->id)
            )
            ->get()
            ->groupBy('postulacion_id')
            ->map(fn ($grupo) => $this->seleccionarEvaluacionVisible($grupo));

        return response()->json([
            'data' => $pagina->getCollection()->map(
                fn (Postulacion $p) => $this->presentar($p, $evaluaciones->get($p->id), $actor)
            ),
            'meta' => [
                'current_page' => $pagina->currentPage(),
                'last_page' => $pagina->lastPage(),
                'per_page' => $pagina->perPage(),
                'total' => $pagina->total(),
                'convocatorias' => Convocatoria::query()
                    ->whereIn('ficha_id', $actor->fichaIds())
                    ->orderByDesc('id')
                    ->get(['id', 'nombre as titulo', 'estado_id']),
                // Opciones del filtro por ficha: solo las del alcance del usuario.
                'fichas' => Ficha::query()
                    ->whereIn('id', $actor->fichaIds())
                    ->orderBy('codigo')
                    ->get(['id', 'codigo', 'nombre_programa']),
            ],
        ]);
    }
}
