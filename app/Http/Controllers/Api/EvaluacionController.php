<?php

namespace App\Http\Controllers\Api;

use App\Enums\PostulacionEstado;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Convocatoria;
use App\Models\Evaluacion;
use App\Models\Ficha;
use App\Models\Parametro;
use App\Models\PerfilAspirante;
use App\Models\Postulacion;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
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
    /**
     * Umbral único de habilitación documental. Vive en el servidor para que la
     * interfaz y Selección no calculen valores distintos. Cambiarlo aquí (o
     * volverlo configurable por convocatoria) es una decisión de negocio.
     */
    private const UMBRAL_SELECCION = 60;

    public function index(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'convocatoria_id' => ['nullable', 'integer', 'exists:convocatorias,id'],
            'ficha_id' => ['nullable', 'integer', 'exists:fichas,id'],
            'search' => ['nullable', 'string', 'max:120'],
        ]);

        $actor = $request->user();

        $query = Postulacion::query()
            ->with([
                'user:id,name,email,ficha_id',
                'user.perfilAspirante.sexo',
                'convocatoria:id,nombre,cargo,estado_id,ficha_id,fecha_evaluacion',
                'convocatoria.estado:id,codigo,nombre',
                'convocatoria.documentos',
                'convocatoria.skills',
                'documentos',
                'skills',
            ])
            ->whereHas('estado', fn ($q) => $q->where('codigo', PostulacionEstado::Postulada->value))
            ->whereHas('convocatoria', fn ($q) => $q->whereIn('ficha_id', $actor->fichaIds()));

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

    public function show(Request $request, Postulacion $postulacion): JsonResponse
    {
        $this->autorizar($request, $postulacion);

        $postulacion->load([
            'user:id,name,email,ficha_id',
            'user.perfilAspirante.sexo',
            'convocatoria:id,nombre,cargo,estado_id,ficha_id,fecha_evaluacion',
            'convocatoria.estado:id,codigo,nombre',
            'convocatoria.documentos',
            'convocatoria.skills',
            'documentos',
            'skills',
        ]);

        $actor = $request->user();
        $queryEval = Evaluacion::query()
            ->with('evaluador:id,name,email,subrole')
            ->where('postulacion_id', $postulacion->id);

        if (! in_array($actor->role, [Role::Instructor, Role::SuperAdmin], true)) {
            $queryEval->where('evaluador_id', $actor->id);
            $evaluacion = $queryEval->first();
        } else {
            $evaluacion = $this->seleccionarEvaluacionVisible($queryEval->get());
        }

        return response()->json(['data' => $this->presentar($postulacion, $evaluacion, $actor)]);
    }

    /**
     * El Instructor/super_admin administra el proceso: cuando una postulación
     * tiene varias evaluaciones, trabaja sobre la más representativa. Primero
     * prioriza la que realizó un aprendiz con subrol evaluador (es el trabajo
     * que el admin/super_admin supervisa); luego el estado (finalizada, en
     * corrección, borrador) y por último la más reciente.
     *
     * @param  Collection<int, Evaluacion>  $evaluaciones
     */
    private function seleccionarEvaluacionVisible($evaluaciones): ?Evaluacion
    {
        return $evaluaciones
            ->sortByDesc(fn (Evaluacion $e) => [
                $e->evaluador?->subrole === 'evaluador' ? 1 : 0,
                match ($e->estado) {
                    'finalizada' => 3,
                    'correccion_solicitada' => 2,
                    default => 1,
                },
                $e->updated_at?->getTimestamp() ?? 0,
                $e->id,
            ])
            ->first();
    }

    public function upsert(Request $request, Postulacion $postulacion): JsonResponse
    {
        $this->autorizar($request, $postulacion);
        $this->exigirPostulada($postulacion);

        $datos = $request->validate([
            'observaciones' => ['nullable', 'string', 'max:2000'],
            'estado' => ['nullable', Rule::in(['en_proceso', 'correccion_solicitada', 'finalizada'])],
            'criterios' => ['required', 'array', 'min:1'],
            'criterios.*.criterio' => ['required', 'string', 'max:120'],
            'criterios.*.puntaje' => ['required', 'numeric', 'min:0', 'max:100'],
            'criterios.*.ponderacion' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'perfil' => ['nullable', 'array'],
            'cumplimiento' => ['nullable', 'integer', 'min:0', 'max:100'],
        ]);

        // Si el evaluador manda a corregir, debe indicar qué documento(s) no
        // cumplen (marcados con No = false) y explicar el motivo en observaciones.
        if (($datos['estado'] ?? null) === 'correccion_solicitada') {
            if (trim((string) ($datos['observaciones'] ?? '')) === '') {
                abort(422, 'Debes escribir en las observaciones qué documentos o datos debe corregir el aspirante.');
            }

            $perfil = (array) ($datos['perfil'] ?? []);
            $hayRechazado = collect($perfil)->contains(fn ($valor) => $valor === false);

            if (! $hayRechazado) {
                abort(422, 'Marca con "No" al menos un documento que el aspirante deba corregir.');
            }
        }

        // El cumplimiento se calcula en el SERVIDOR con la ponderación que el
        // gestor configuró para ESTA convocatoria: el cliente solo manda qué
        // documentos cumplió (perfil). Si no hay documentos obligatorios
        // aplicables no existe base para decidir el 60 %: se rechaza en vez de
        // confiar en el porcentaje que envíe el navegador.
        $calculado = $this->calcularCumplimiento($postulacion, $datos['perfil'] ?? null);

        if ($calculado === null) {
            abort(422, 'No se puede evaluar: la convocatoria no tiene documentos obligatorios aplicables con ponderación válida.');
        }

        $evaluacion = DB::transaction(function () use ($request, $postulacion, $datos, $calculado) {
            $actor = $request->user();

            // El Instructor/super_admin administra el proceso: si ya existe una
            // evaluación de un aprendiz evaluador, la edita; si no existe, crea
            // la suya. El evaluador siempre guarda la suya.
            if (in_array($actor->role, [Role::Instructor, Role::SuperAdmin], true)) {
                $existente = $this->seleccionarEvaluacionVisible(
                    Evaluacion::where('postulacion_id', $postulacion->id)->get()
                );
                $evaluacion = $existente ?? new Evaluacion([
                    'postulacion_id' => $postulacion->id,
                    'evaluador_id' => $actor->id,
                ]);
            } else {
                $evaluacion = Evaluacion::firstOrNew([
                    'postulacion_id' => $postulacion->id,
                    'evaluador_id' => $actor->id,
                ]);
            }

            $evaluacion->observaciones = $datos['observaciones'] ?? null;
            $evaluacion->estado = $datos['estado'] ?? 'en_proceso';
            $evaluacion->perfil = $datos['perfil'] ?? null;
            $evaluacion->cumplimiento = $calculado;
            // Solo una evaluación finalizada es resultado: un borrador no define si
            // la postulación pasa a selección.
            $evaluacion->pasa_seleccion = ($evaluacion->estado === 'finalizada' && $evaluacion->cumplimiento !== null)
                ? $evaluacion->cumplimiento >= self::UMBRAL_SELECCION
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
            'user.perfilAspirante.sexo',
            'convocatoria:id,nombre,cargo,estado_id,ficha_id,fecha_evaluacion',
            'convocatoria.estado:id,codigo,nombre',
            'convocatoria.documentos',
            'convocatoria.skills',
            'documentos',
            'skills',
        ])->loadCount([
            'documentos',
        ]);

        return response()->json([
            'data' => $this->presentar($postulacion, $evaluacion->load('detalles'), $request->user()),
        ]);
    }

    public function misEvaluaciones(Request $request): JsonResponse
    {
        $actor = $request->user();

        $query = Evaluacion::query()->with([
            'detalles',
            'evaluador:id,name,email',
            'postulacion.convocatoria:id,nombre,cargo,estado_id',
            'postulacion.user:id,name,email',
            'postulacion.documentos',
        ]);

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
                'perfil' => $e->perfil,
                'documentos_actualizados' => $this->documentosActualizados($e->postulacion, $e),
                'aspirante' => [
                    'id' => $e->postulacion->user?->id,
                    'name' => $e->postulacion->user?->name,
                    'email' => $e->postulacion->user?->email,
                ],
                'convocatoria' => [
                    'id' => $e->postulacion->convocatoria?->id,
                    'titulo' => $e->postulacion->convocatoria?->nombre,
                ],
                'evaluador' => [
                    'id' => $e->evaluador?->id,
                    'name' => $e->evaluador?->name,
                    'email' => $e->evaluador?->email,
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

    /**
     * Solo las postulaciones enviadas (postulada) se evalúan. Un borrador es un
     * formulario incompleto que el aspirante aún no confirmó, y una retirada ya
     * no participa del proceso. La validación vive en el servidor: la interfaz
     * solo es un reflejo de esta regla.
     */
    private function exigirPostulada(Postulacion $postulacion): void
    {
        $postulacion->loadMissing('estado');

        if ($postulacion->estado?->codigo !== PostulacionEstado::Postulada->value) {
            abort(422, 'Solo se pueden evaluar postulaciones enviadas. Esta postulación no ha sido enviada o fue retirada.');
        }
    }

    /**
     * Pesos (%) de los documentos OBLIGATORIOS y APLICABLES de la convocatoria,
     * indexados por id del parámetro (tipo_documento). Si la convocatoria no
     * configuró ponderaciones (todas null) se reparte el 100 % en partes
     * iguales, que es el comportamiento histórico. Si solo algunas están
     * configuradas (no debería pasar: el alta lo valida), el resto se reparte
     * con lo que sobra.
     *
     * @return array<int, float>
     */
    private function pesosDocumentos(Postulacion $postulacion): array
    {
        $documentos = $postulacion->convocatoria?->documentos ?? collect();

        if ($documentos->isEmpty()) {
            return [];
        }

        // Solo cuentan los documentos OBLIGATORIOS y APLICABLES, con la misma
        // regla que usa el envío (DocumentosDePostulacion): un documento
        // opcional no debe restar, y la libreta militar de una mujer no aplica.
        $perfil = PerfilAspirante::query()
            ->where('user_id', $postulacion->user_id)
            ->with('sexo')
            ->first();

        $sexoMujer = $perfil?->sexo?->codigo === 'mujer';

        $documentos = $documentos->filter(function (Parametro $documento) use ($sexoMujer) {
            if (! (bool) $documento->pivot->obligatorio) {
                return false;
            }

            return ! ($documento->codigo === Parametro::DOC_LIBRETA_MILITAR && $sexoMujer);
        });

        if ($documentos->isEmpty()) {
            return [];
        }

        $pesos = [];
        $sinPeso = [];
        $suma = 0.0;

        foreach ($documentos as $documento) {
            $valor = $documento->pivot->ponderacion;

            if ($valor === null) {
                $sinPeso[] = (int) $documento->id;

                continue;
            }

            $pesos[(int) $documento->id] = (float) $valor;
            $suma += (float) $valor;
        }

        if ($sinPeso === []) {
            return $pesos;
        }

        $cuota = max(0.0, 100.0 - $suma) / count($sinPeso);

        foreach ($sinPeso as $id) {
            $pesos[$id] = $cuota;
        }

        return $pesos;
    }

    /**
     * Cumplimiento ponderado (0-100) a partir de lo marcado por el evaluador.
     *
     * El `perfil` llega como { "doc-{parametro_id}": true|false } desde la
     * pantalla de evaluación. Se suma el peso de cada documento OBLIGATORIO y
     * APLICABLE marcado como cumplido sobre el total de pesos configurados.
     */
    private function calcularCumplimiento(Postulacion $postulacion, ?array $perfil): ?int
    {
        $pesos = $this->pesosDocumentos($postulacion);

        if ($pesos === []) {
            return null;
        }

        $total = array_sum($pesos);

        if ($total <= 0) {
            return null;
        }

        $obtenido = 0.0;
        foreach ($pesos as $parametroId => $peso) {
            if (! empty($perfil["doc-{$parametroId}"])) {
                $obtenido += $peso;
            }
        }

        return (int) round(min(100.0, ($obtenido / $total) * 100));
    }

    private function presentar(Postulacion $p, ?Evaluacion $e, ?User $actor = null): array
    {
        // Solo cuentan los documentos OBLIGATORIOS y APLICABLES que el
        // aspirante ya cargó (misma regla que el cálculo del 60 %). No existe
        // un estado de validación en la base: "cargado" es lo único que se
        // puede afirmar. La libreta militar de una mujer no aplica.
        $sexoMujer = $p->user?->perfilAspirante?->sexo?->codigo === 'mujer';

        $requeridos = ($p->convocatoria?->documentos ?? collect())
            ->filter(fn (Parametro $d) => (bool) $d->pivot->obligatorio
                && ! ($d->codigo === Parametro::DOC_LIBRETA_MILITAR && $sexoMujer));

        $docsRequeridos = $requeridos->count();
        $docsCargados = $p->documentos
            ->whereIn('parametro_id', $requeridos->pluck('id'))
            ->count();

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
                'cargados' => $docsCargados,
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
                'evaluador_id' => $e->evaluador_id,
                'evaluador' => $e->evaluador?->name ?? $e->evaluador?->email,
                'es_propia' => $actor !== null && (int) $e->evaluador_id === (int) $actor->id,
                'puntaje_total' => $e->puntaje_total,
                'estado' => $e->estado,
                'observaciones' => $e->observaciones,
                'cumplimiento' => $e->cumplimiento,
                'pasa_seleccion' => $e->pasa_seleccion,
                'fecha' => $e->created_at?->toDateString(),
                'perfil' => $e->perfil,
                'documentos_actualizados' => $this->documentosActualizados($p, $e),
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

    /**
     * Identificadores "doc-{parametro_id}" de los documentos requeridos cuyo
     * archivo se subió o reemplazó DESPUÉS de la última revisión del evaluador.
     *
     * @return array<int, string>
     */
    private function documentosActualizados(?Postulacion $p, ?Evaluacion $e): array
    {
        if (! $p || ! $e || ! $e->updated_at) {
            return [];
        }

        $evalUpdated = Carbon::parse($e->updated_at);

        return $p->documentos
            ->whereNotNull('parametro_id')
            ->filter(fn ($doc) => $doc->updated_at && Carbon::parse($doc->updated_at)->gt($evalUpdated))
            ->map(fn ($doc) => 'doc-'.$doc->parametro_id)
            ->values()
            ->all();
    }
}
