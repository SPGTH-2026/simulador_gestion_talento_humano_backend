<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Convocatoria;
use App\Models\Evaluacion;
use App\Models\Postulacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Módulo de Evaluación (adaptado al módulo real de Convocatorias/Postulación).
 *
 * Evalúa postulaciones de convocatorias del alcance del usuario. El aspirante
 * consulta sus propias evaluaciones con propio:evaluaciones.
 */
class EvaluacionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'convocatoria_id' => ['nullable', 'integer', 'exists:convocatorias,id'],
            'search' => ['nullable', 'string', 'max:120'],
        ]);

        $actor = $request->user();

        $query = Postulacion::query()
            ->with([
                'user:id,name,email,ficha_id',
                'convocatoria:id,nombre,cargo,estado_id,ficha_id,fecha_evaluacion',
                'convocatoria.estado:id,codigo,nombre',
                'convocatoria.documentos',
                'convocatoria.skills',
                'documentos',
                'skills',
            ])
            ->whereHas('convocatoria', fn ($q) => $q->whereIn('ficha_id', $actor->fichaIds()));

        if ($datos['convocatoria_id'] ?? null) {
            $query->where('convocatoria_id', $datos['convocatoria_id']);
        }
        if ($datos['search'] ?? null) {
            $b = $datos['search'];
            $query->whereHas('user', fn ($q) => $q->where('name', 'like', "%{$b}%")->orWhere('email', 'like', "%{$b}%"));
        }

        $pagina = $query->orderBy('id')->paginate(20);

        $mias = Evaluacion::query()
            ->where('evaluador_id', $actor->id)
            ->whereIn('postulacion_id', $pagina->getCollection()->pluck('id'))
            ->get()
            ->keyBy('postulacion_id');

        return response()->json([
            'data' => $pagina->getCollection()->map(
                fn (Postulacion $p) => $this->presentar($p, $mias->get($p->id)),
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
            ],
        ]);
    }

    public function show(Request $request, Postulacion $postulacion): JsonResponse
    {
        $this->autorizar($request, $postulacion);

        $postulacion->load([
            'user:id,name,email,ficha_id',
            'convocatoria:id,nombre,cargo,estado_id,ficha_id,fecha_evaluacion',
            'convocatoria.estado:id,codigo,nombre',
            'convocatoria.documentos',
            'convocatoria.skills',
            'documentos',
            'skills',
        ]);

        $evaluacion = Evaluacion::query()
            ->where('postulacion_id', $postulacion->id)
            ->where('evaluador_id', $request->user()->id)
            ->first();

        return response()->json(['data' => $this->presentar($postulacion, $evaluacion)]);
    }

    public function upsert(Request $request, Postulacion $postulacion): JsonResponse
    {
        $this->autorizar($request, $postulacion);

        $datos = $request->validate([
            'observaciones' => ['nullable', 'string', 'max:2000'],
            'estado' => ['nullable', Rule::in(['en_proceso', 'finalizada'])],
            'criterios' => ['required', 'array', 'min:1'],
            'criterios.*.criterio' => ['required', 'string', 'max:120'],
            'criterios.*.puntaje' => ['required', 'numeric', 'min:0', 'max:100'],
            'criterios.*.ponderacion' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'perfil' => ['nullable', 'array'],
            'cumplimiento' => ['nullable', 'integer', 'min:0', 'max:100'],
        ]);

        $evaluacion = DB::transaction(function () use ($request, $postulacion, $datos) {
            $evaluacion = Evaluacion::firstOrNew([
                'postulacion_id' => $postulacion->id,
                'evaluador_id' => $request->user()->id,
            ]);
            $evaluacion->observaciones = $datos['observaciones'] ?? null;
            $evaluacion->estado = $datos['estado'] ?? 'en_proceso';
            $evaluacion->perfil = $datos['perfil'] ?? null;
            $evaluacion->cumplimiento = $datos['cumplimiento'] ?? null;
            $evaluacion->pasa_seleccion = ($evaluacion->cumplimiento !== null)
                ? $evaluacion->cumplimiento >= 60
                : null;

            $evaluacion->save();

            $evaluacion->detalles()->delete();

            $sumaPonderada = 0.0;
            $sumaPesos = 0.0;

            foreach ($datos['criterios'] as $criterio) {
                $peso = (float) ($criterio['ponderacion'] ?? 1);
                $evaluacion->detalles()->create([
                    'criterio' => $criterio['criterio'],
                    'puntaje' => $criterio['puntaje'],
                    'ponderacion' => $peso,
                ]);
                $sumaPonderada += (float) $criterio['puntaje'] * $peso;
                $sumaPesos += $peso;
            }

            $evaluacion->puntaje_total = $sumaPesos > 0 ? round($sumaPonderada / $sumaPesos, 2) : null;
            $evaluacion->save();

            return $evaluacion;
        });

        $postulacion->load([
            'user:id,name,email,ficha_id',
            'convocatoria:id,nombre,cargo,estado_id,ficha_id,fecha_evaluacion',
            'convocatoria.estado:id,codigo,nombre',
            'convocatoria.documentos',
            'convocatoria.skills',
            'documentos',
            'skills',
        ])->loadCount([
            'documentos',
        ]);

        return response()->json(['data' => $this->presentar($postulacion, $evaluacion->load('detalles'))]);
    }

    public function misEvaluaciones(Request $request): JsonResponse
    {
        $actor = $request->user();

        $query = Evaluacion::query()->with(['detalles', 'postulacion.convocatoria:id,nombre,cargo,estado_id', 'postulacion.user:id,name,email']);

        // El aspirante ve las evaluaciones sobre SUS postulaciones. El instructor
        // y el admin ven TODAS las evaluaciones calificadas de su alcance.
        if ($actor->role === Role::Aspirante) {
            $query->whereHas('postulacion', fn ($q) => $q->where('user_id', $actor->id));
        } elseif ($actor->role === Role::Instructor) {
            $query->whereHas('postulacion.convocatoria', fn ($q) => $q->whereIn('ficha_id', $actor->fichaIds()));
        } elseif ($actor->role === Role::Aprendiz) {
            // El evaluador (y el aprendiz general) ve SOLO las evaluaciones que
            // él mismo realizó, no las de toda la ficha.
            $query->where('evaluador_id', $actor->id);
        }
        // super_admin ve todas.

        $evaluaciones = $query->orderByDesc('id')->get();

        return response()->json([
            'data' => $evaluaciones->map(fn (Evaluacion $e) => [
                'id' => $e->id,
                'postulacion_id' => $e->postulacion_id,
                'puntaje_total' => $e->puntaje_total,
                'estado' => $e->estado,
                'observaciones' => $e->observaciones,
                'cumplimiento' => $e->cumplimiento,
                'pasa_seleccion' => $e->pasa_seleccion,
                'fecha' => $e->created_at?->toDateString(),
                'aspirante' => [
                    'id' => $e->postulacion->user?->id,
                    'name' => $e->postulacion->user?->name,
                    'email' => $e->postulacion->user?->email,
                ],
                'convocatoria' => [
                    'id' => $e->postulacion->convocatoria?->id,
                    'titulo' => $e->postulacion->convocatoria?->nombre,
                ],
                'criterios' => $e->detalles->map(fn ($d) => [
                    'criterio' => $d->criterio,
                    'puntaje' => $d->puntaje,
                    'ponderacion' => $d->ponderacion,
                ]),
            ]),
        ]);
    }

    private function autorizar(Request $request, Postulacion $postulacion): void
    {
        $fichaId = $postulacion->convocatoria?->ficha_id;
        if (! $fichaId || ! in_array((int) $fichaId, $request->user()->fichaIds(), true)) {
            abort(403, 'No tienes alcance sobre esta postulación');
        }
    }

    private function presentar(Postulacion $p, ?Evaluacion $e): array
    {
        $docsSubidos = $p->documentos->count();
        $docsRequeridos = $p->convocatoria?->documentos?->count() ?? 0;

        return [
            'id' => $p->id,
            'estado' => $p->estado?->nombre ?? $p->estado?->codigo,
            'aspirante' => [
                'id' => $p->user_id,
                'name' => $p->user?->name,
                'email' => $p->user?->email,
            ],
            'convocatoria' => [
                'id' => $p->convocatoria?->id,
                'titulo' => $p->convocatoria?->nombre,
                'estado' => $p->convocatoria?->estado?->codigo,
            ],
            'documentos' => [
                'total' => $docsRequeridos,
                'validados' => $docsSubidos,
            ],
            'skills' => $p->convocatoria?->skills?->map(function ($cs) use ($p) {
                $declarada = $p->skills->firstWhere('convocatoria_skill_id', $cs->id);

                return [
                    'id' => $cs->id,
                    'nombre' => $cs->nombre,
                    'declarada' => $declarada !== null,
                    'certificada' => $declarada?->tieneCertificado() ?? false,
                ];
            })->values() ?? [],
            'evaluacion' => $e ? [
                'id' => $e->id,
                'puntaje_total' => $e->puntaje_total,
                'estado' => $e->estado,
                'observaciones' => $e->observaciones,
                'cumplimiento' => $e->cumplimiento,
                'pasa_seleccion' => $e->pasa_seleccion,
                'fecha' => $e->created_at?->toDateString(),
                'perfil' => $e->perfil,
                'criterios' => $e->relationLoaded('detalles')
                    ? $e->detalles->map(fn ($d) => [
                        'criterio' => $d->criterio,
                        'puntaje' => $d->puntaje,
                        'ponderacion' => $d->ponderacion,
                    ])
                    : [],
            ] : null,
        ];
    }
}
