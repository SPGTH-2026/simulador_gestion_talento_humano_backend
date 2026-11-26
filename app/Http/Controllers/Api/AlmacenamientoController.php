<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Ficha;
use App\Models\PostulacionDocumento;
use App\Models\User;
use App\Services\Almacenamiento\UsoAlmacenamiento;
use App\Services\Documentos\EscribeDocumentoPostulacion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Uso de almacenamiento y gestión de documentos por ficha. Lo ven y lo gestionan el super admin (todas las fichas) y el
 * instructor (solo sus fichas: las mismas que User::fichaIds()). Una ficha ajena responde 404, sin revelar que existe.
 *
 * Borrar sigue la misma regla que en el aspirante: los documentos OBLIGATORIOS no se borran (se reemplazan). Se borran los
 * adicionales y los opcionales, a través del servicio que ya aplica esa regla.
 */
class AlmacenamientoController extends Controller
{
    public function __construct(
        private UsoAlmacenamiento $uso,
        private EscribeDocumentoPostulacion $escribe,
    ) {}

    /**
     * Resumen por ficha: documentos registrados y bytes en el disco. La base de datos solo la ve el super admin, porque
     * es infraestructura de todo el sistema y no de una ficha.
     */
    public function resumen(Request $request): JsonResponse
    {
        $actor = $request->user();
        $fichas = $this->fichasDelActor($actor);

        $conteos = DB::table('postulacion_documentos')
            ->join('postulaciones', 'postulaciones.id', '=', 'postulacion_documentos.postulacion_id')
            ->join('convocatorias', 'convocatorias.id', '=', 'postulaciones.convocatoria_id')
            ->whereIn('convocatorias.ficha_id', $fichas->pluck('id'))
            ->selectRaw('convocatorias.ficha_id, count(*) as documentos')
            ->groupBy('convocatorias.ficha_id')
            ->get()
            ->keyBy('ficha_id');

        $filas = $fichas->map(function (Ficha $ficha) use ($conteos) {
            return [
                'ficha_id' => $ficha->id,
                'codigo' => $ficha->codigo,
                'nombre_programa' => $ficha->nombre_programa,
                'documentos' => (int) ($conteos[$ficha->id]->documentos ?? 0),
                'almacenamiento' => $this->uso->documentosDeFicha($ficha),
            ];
        })->values();

        $data = [
            'fichas' => $filas,
            'totales' => [
                'documentos' => $filas->sum('documentos'),
                'bytes' => $filas->sum(fn (array $f) => $f['almacenamiento']['bytes'] ?? 0),
            ],
        ];

        // El porcentaje total lo ven todos los que gestionan almacenamiento. El detalle de la base, solo el super admin.
        $esSuperAdmin = $actor->role === Role::SuperAdmin;
        $data['uso'] = $this->uso->resumenUso(conDetalle: $esSuperAdmin);

        if ($esSuperAdmin) {
            $data['base'] = $this->uso->base();
        }

        return response()->json(['data' => $data]);
    }

    /** Documentos de una ficha, con si cada uno se puede borrar. */
    public function documentos(Request $request, Ficha $ficha): JsonResponse
    {
        $this->exigirFichaDelActor($request->user(), $ficha);

        $datos = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $listado = $this->consultaDeFicha($ficha, $request)
            ->paginate($datos['per_page'] ?? 20, ['*'], 'page', $datos['page'] ?? 1);

        return response()->json([
            'ficha' => ['id' => $ficha->id, 'codigo' => $ficha->codigo, 'nombre_programa' => $ficha->nombre_programa],
            'data' => $listado->getCollection()->map(fn (PostulacionDocumento $d) => $this->presentarDocumento($d))->values(),
            'meta' => [
                'current_page' => $listado->currentPage(),
                'last_page' => $listado->lastPage(),
                'per_page' => $listado->perPage(),
                'total' => $listado->total(),
            ],
        ]);
    }

    /**
     * Ids de TODOS los documentos de la ficha que se pueden borrar y que cumplen la búsqueda y el filtro (no solo los de la
     * página). Sirve para «seleccionar todos los resultados».
     */
    public function idsBorrables(Request $request, Ficha $ficha): JsonResponse
    {
        $this->exigirFichaDelActor($request->user(), $ficha);

        $ids = $this->consultaDeFicha($ficha, $request)
            ->with(['postulacion.convocatoria.documentos', 'parametro:id,nombre'])
            ->get()
            ->filter(fn (PostulacionDocumento $d) => $this->presentarDocumento($d)['borrable'])
            ->pluck('id')
            ->values();

        return response()->json(['data' => ['ids' => $ids, 'total' => $ids->count()]]);
    }

    /**
     * Documentos de una ficha con la búsqueda y el filtro de tipo de la petición:
     *  - q: texto que se busca en el nombre o correo del aspirante, en el título o nombre del documento y en la convocatoria.
     *  - tipo: «adicional» (sin requisito) o «requerido» (de la convocatoria).
     */
    private function consultaDeFicha(Ficha $ficha, Request $request): Builder
    {
        $filtros = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'tipo' => ['nullable', Rule::in(['adicional', 'requerido'])],
        ]);

        $consulta = PostulacionDocumento::query()
            ->with(['postulacion.user:id,name,email', 'postulacion.convocatoria:id,nombre,ficha_id', 'postulacion.convocatoria.documentos', 'parametro:id,nombre'])
            ->whereHas('postulacion.convocatoria', fn ($q) => $q->where('ficha_id', $ficha->id))
            ->orderByDesc('id');

        if (($filtros['tipo'] ?? null) === 'adicional') {
            $consulta->whereNull('parametro_id');
        } elseif (($filtros['tipo'] ?? null) === 'requerido') {
            $consulta->whereNotNull('parametro_id');
        }

        $termino = trim((string) ($filtros['q'] ?? ''));
        if ($termino !== '') {
            $patron = '%'.mb_strtolower($termino).'%';
            $consulta->where(function (Builder $q) use ($patron) {
                $q->whereRaw('LOWER(titulo) LIKE ?', [$patron])
                    ->orWhereHas('parametro', fn ($p) => $p->whereRaw('LOWER(nombre) LIKE ?', [$patron]))
                    ->orWhereHas('postulacion.user', fn ($u) => $u->whereRaw('LOWER(name) LIKE ?', [$patron])->orWhereRaw('LOWER(email) LIKE ?', [$patron]))
                    ->orWhereHas('postulacion.convocatoria', fn ($c) => $c->whereRaw('LOWER(nombre) LIKE ?', [$patron]));
            });
        }

        return $consulta;
    }

    /**
     * Borra documentos por lote (hasta 100). Cada uno se valida por separado: los que no se pueden borrar (obligatorios) o
     * no son de una ficha del actor vuelven en `omitidos` con su motivo, y el resto se borra igualmente.
     */
    public function borrar(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['integer'],
        ]);

        $actor = $request->user();
        $fichasDelActor = $actor->fichaIds();
        $ids = array_values(array_unique(array_map('intval', $datos['ids'])));

        $documentos = PostulacionDocumento::query()
            ->with('postulacion.convocatoria')
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        $borrados = [];
        $omitidos = [];

        foreach ($ids as $id) {
            $documento = $documentos->get($id);

            // Un documento ajeno o inexistente se trata igual: no se revela si existe.
            if ($documento === null || ! in_array((int) $documento->postulacion->convocatoria->ficha_id, $fichasDelActor, true)) {
                $omitidos[] = ['id' => $id, 'motivo' => 'Ese documento no existe en tus fichas.'];

                continue;
            }

            try {
                $this->escribe->eliminar($documento);
                $borrados[] = $id;
            } catch (ValidationException $e) {
                $omitidos[] = ['id' => $id, 'motivo' => collect($e->errors())->flatten()->first() ?? 'No se pudo borrar.'];
            }
        }

        // Auditoría: quién borró qué. Solo ids, nunca nombres ni rutas de archivo.
        if ($borrados !== []) {
            Log::info('Documentos borrados desde Almacenamiento.', [
                'actor_id' => $actor->id,
                'documentos' => $borrados,
            ]);
        }

        return response()->json(['data' => ['borrados' => $borrados, 'omitidos' => $omitidos]]);
    }

    /** @return Collection<int, Ficha> */
    private function fichasDelActor(User $actor): Collection
    {
        return Ficha::query()->whereIn('id', $actor->fichaIds())->orderBy('codigo')->get();
    }

    private function exigirFichaDelActor(User $actor, Ficha $ficha): void
    {
        abort_unless(in_array($ficha->id, $actor->fichaIds(), true), 404, 'Ficha no encontrada.');
    }

    /** @return array<string, mixed> */
    private function presentarDocumento(PostulacionDocumento $d): array
    {
        $postulacion = $d->postulacion;
        $requerido = $postulacion->convocatoria->documentos->firstWhere('id', $d->parametro_id);
        $obligatorio = $d->esAdicional() ? false : (bool) $requerido?->pivot?->obligatorio;

        return [
            'id' => $d->id,
            'tipo' => $d->esAdicional() ? 'adicional' : 'requerido',
            'titulo' => $d->esAdicional() ? $d->titulo : $d->parametro?->nombre,
            'obligatorio' => $obligatorio,
            // Los obligatorios no se borran: se reemplazan (misma regla que en el servicio de documentos).
            'borrable' => $d->esAdicional() || ! $obligatorio,
            'aspirante' => $postulacion->user?->name,
            'convocatoria' => $postulacion->convocatoria?->nombre,
            'tamano_bytes' => (int) $d->tamano_bytes,
            'creado_at' => $d->created_at?->toISOString(),
        ];
    }
}
