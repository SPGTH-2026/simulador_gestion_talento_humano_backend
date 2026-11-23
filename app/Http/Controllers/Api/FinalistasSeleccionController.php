<?php

namespace App\Http\Controllers\Api;

use App\Enums\ConvocatoriaEstado;
use App\Http\Controllers\Api\Concerns\RankingSeleccion;
use App\Http\Controllers\Api\Concerns\ResultadoAdicional;
use App\Http\Controllers\Api\Concerns\ResultadosDocumentales;
use App\Http\Controllers\Controller;
use App\Models\Convocatoria;
use App\Models\Parametro;
use App\Models\Postulacion;
use App\Models\SeleccionFinalistaLista;
use App\Models\SeleccionFinalistaMiembro;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Selección, etapa 5: confirmación de finalistas.
 *
 * Cada confirmación es una versión nueva; las anteriores nunca se editan. Un reenvío
 * idéntico (mismo conjunto y sin cambios en los candidatos) devuelve la versión
 * vigente sin crear otra. El cliente envía la `version` del ranking que leyó: si el
 * ranking cambió (nota corregida, referencia reabierta, otro candidato listo...) se
 * responde 409 para que refresque y revise.
 *
 * Límite de concurrencia: se comprueba bajo el bloqueo de la convocatoria, el mismo que
 * usan las notas, la configuración y el objetivo. Evaluación y Documentos no toman ese
 * bloqueo, por lo que un cambio suyo posterior a la comprobación no se ve hasta la
 * siguiente lectura, donde la lista confirmada aparece con `requiere_revision`.
 * La referencia vigente se compara por el updated_at de la evaluación elegida
 * (segundos); un cambio dentro del mismo segundo con los mismos valores no se detecta.
 */
class FinalistasSeleccionController extends Controller
{
    use RankingSeleccion;
    use ResultadoAdicional;
    use ResultadosDocumentales;

    private const POR_PAGINA = 20;

    public function index(Request $request, int $convocatoria): JsonResponse
    {
        $convocatoria = $this->convocatoriaEnAlcance($request->user(), $convocatoria);

        // La lista vigente se consulta aparte: no depende de la página del historial que se pida.
        $vigente = SeleccionFinalistaLista::query()
            ->with(['confirmador:id,name', 'miembros.postulacion.user:id,name'])
            ->where('convocatoria_id', $convocatoria->id)
            ->orderByDesc('version')
            ->first();
        $actual = $vigente === null ? null : $this->presentar($vigente, $this->construirRanking($convocatoria));

        $versiones = SeleccionFinalistaLista::query()
            ->withCount('miembros')
            ->with('confirmador:id,name')
            ->where('convocatoria_id', $convocatoria->id)
            ->orderByDesc('version')
            ->paginate(self::POR_PAGINA);

        return response()->json([
            'data' => [
                'actual' => $actual,
                'versiones' => $versiones->getCollection()->map(fn (SeleccionFinalistaLista $l) => [
                    'version' => $l->version,
                    'modo' => $l->modo,
                    'total' => $l->miembros_count,
                    'motivo' => $l->motivo,
                    'confirmada_por' => $l->confirmador?->name,
                    'confirmada_en' => $l->created_at?->toIso8601String(),
                ])->values(),
            ],
            'meta' => [
                'current_page' => $versiones->currentPage(),
                'last_page' => $versiones->lastPage(),
                'per_page' => $versiones->perPage(),
                'total' => $versiones->total(),
            ],
        ]);
    }

    public function store(Request $request, int $convocatoria): JsonResponse
    {
        $this->convocatoriaEnAlcance($request->user(), $convocatoria);

        $datos = $request->validate([
            'version' => ['required', 'string', 'max:64'],
            'postulacion_ids' => ['required', 'array', 'min:1', 'max:500'],
            'postulacion_ids.*' => ['integer', 'distinct'],
            'justificacion' => ['nullable', 'string', 'min:10', 'max:500'],
        ], [
            'postulacion_ids.required' => 'Elige al menos un finalista.',
            'postulacion_ids.min' => 'Elige al menos un finalista.',
            'justificacion.min' => 'La justificación debe tener al menos 10 caracteres.',
        ]);

        $actor = $request->user();
        $ids = collect($datos['postulacion_ids'])->map(fn ($id) => (int) $id)->sort()->values();
        $estado = 201;

        $lista = DB::transaction(function () use ($convocatoria, $datos, $ids, $actor, &$estado) {
            $fila = Convocatoria::query()->whereKey($convocatoria)->lockForUpdate()->first();
            $enProceso = (int) Parametro::porTemaYCodigo('estado_convocatoria', ConvocatoriaEstado::EnProceso->value)->id;
            if (! $fila || (int) $fila->estado_id !== $enProceso) {
                $this->rechazar('convocatoria', 'Los finalistas solo se pueden confirmar con la convocatoria en proceso.');
            }

            // Postulaciones de ESTA convocatoria, bloqueadas en orden de id (orden consistente
            // contra bloqueos cruzados). Si alguna no pertenece, se rechaza todo sin guardar nada
            // ni decir si el id existe en otra convocatoria.
            $bloqueadas = Postulacion::query()
                ->where('convocatoria_id', $fila->id)
                ->whereIn('id', $ids)
                ->whereHas('user', fn ($u) => $u->where('active', true))
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id']);
            if ($bloqueadas->count() !== $ids->count()) {
                $this->rechazar('postulacion_ids', 'Alguna de las postulaciones indicadas no corresponde a esta convocatoria.');
            }

            $ranking = $this->construirRanking($fila);

            if (! hash_equals($ranking['version'], $datos['version'])) {
                throw new HttpResponseException(response()->json([
                    'message' => 'El ranking cambió desde que lo consultaste. Actualízalo y revisa la lista antes de confirmar.',
                    'codigo' => 'ranking_desactualizado',
                    'version' => $ranking['version'],
                ], 409));
            }

            $listos = $ranking['listos']->keyBy('id');
            if ($ranking['modo'] === 'pendiente_de_configurar') {
                $this->rechazar('postulacion_ids', 'La convocatoria aún no tiene configuradas las actividades de selección.');
            }
            $fuera = $ids->reject(fn (int $id) => $listos->has($id));
            if ($fuera->isNotEmpty()) {
                $this->rechazar('postulacion_ids', 'Solo pueden ser finalistas los candidatos listos: habilitados documentalmente y con todas las actividades calificadas.');
            }

            // Justificación: sin actividades (elección manual), empate en el corte o cambio sobre la sugerencia.
            $sugeridos = collect($ranking['sugeridos'])->sort()->values();
            $requiereMotivo = $ranking['modo'] === 'sin_actividades'
                || $ranking['empate'] !== null
                || $ids->all() !== $sugeridos->all();
            if ($requiereMotivo && empty($datos['justificacion'])) {
                $this->rechazar('justificacion', 'Indica la justificación: elegiste sin actividades, resolviste un empate en el corte o modificaste la sugerencia.');
            }

            $ultima = SeleccionFinalistaLista::query()
                ->with('miembros:id,lista_id,postulacion_id,huella')
                ->where('convocatoria_id', $fila->id)
                ->orderByDesc('version')
                ->first();

            // Reenvío idéntico: mismo conjunto y nada cambió desde que se confirmó.
            if ($ultima !== null
                && $ultima->miembros->pluck('postulacion_id')->sort()->values()->all() === $ids->all()
                && $this->revisionDeLista($ultima, $ranking) === []) {
                $estado = 200;

                return $ultima;
            }

            $lista = SeleccionFinalistaLista::create([
                'convocatoria_id' => $fila->id,
                'version' => ($ultima?->version ?? 0) + 1,
                'modo' => $ranking['modo'],
                'objetivo' => $ranking['objetivo'],
                'motivo' => $requiereMotivo ? $datos['justificacion'] : null,
                'confirmado_por' => $actor->id,
            ]);

            foreach ($ranking['listos']->filter(fn (array $c) => $ids->contains($c['id'])) as $c) {
                SeleccionFinalistaMiembro::create([
                    'lista_id' => $lista->id,
                    'postulacion_id' => $c['id'],
                    'posicion' => $c['posicion'],
                    'resultado' => $c['resultado'] === null ? null : $this->formatear($c['resultado']),
                    'huella' => $c['huella'],
                ]);
            }

            return $lista;
        });

        $lista = SeleccionFinalistaLista::query()->with(['confirmador:id,name', 'miembros.postulacion.user:id,name'])->findOrFail($lista->id);

        return response()->json(
            ['data' => $this->presentar($lista, $this->construirRanking(Convocatoria::query()->findOrFail($convocatoria)))],
            $estado,
        );
    }

    private function rechazar(string $campo, string $mensaje): never
    {
        throw ValidationException::withMessages([$campo => $mensaje]);
    }

    private function presentar(SeleccionFinalistaLista $lista, array $ranking): array
    {
        $revisar = $this->revisionDeLista($lista, $ranking);

        return [
            'version' => $lista->version,
            'modo' => $lista->modo,
            'objetivo' => $lista->objetivo,
            'motivo' => $lista->motivo,
            'confirmada_por' => $lista->confirmador?->name,
            'confirmada_en' => $lista->created_at?->toIso8601String(),
            // La lista registrada no se modifica: si algo cambió, se señala para revisarla antes de la decisión final.
            'requiere_revision' => $revisar !== [],
            'revisar' => $revisar,
            'finalistas' => $lista->miembros->map(fn (SeleccionFinalistaMiembro $m) => [
                'postulacion_id' => $m->postulacion_id,
                'aspirante' => $m->postulacion?->user?->name,
                'posicion' => $m->posicion,
                'resultado_adicional' => $m->resultado === null ? null : (string) $m->resultado,
            ])->values(),
        ];
    }
}
