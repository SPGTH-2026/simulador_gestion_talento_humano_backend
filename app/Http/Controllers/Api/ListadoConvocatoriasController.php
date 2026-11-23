<?php

namespace App\Http\Controllers\Api;

use App\Enums\ConvocatoriaEstado;
use App\Models\Convocatoria;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use App\Http\Controllers\Api\Concerns\FiltraConvocatorias;
use App\Http\Controllers\Api\Concerns\IdentificadorConvocatoria;
use App\Http\Controllers\Api\Concerns\NombresDeAtributos;
use App\Http\Controllers\Api\Concerns\PersisteConvocatoria;
use App\Http\Controllers\Api\Concerns\PresentaConvocatoria;
use App\Http\Controllers\Controller;

class ListadoConvocatoriasController extends Controller
{
    use FiltraConvocatorias;
    use PresentaConvocatoria;
    use PersisteConvocatoria;
    use IdentificadorConvocatoria;
    use NombresDeAtributos;

    /**
     * Listado paginado dentro del alcance de fichas del usuario. Quien gestiona ve
     * todos los estados de sus fichas; el aspirante solo ve las publicadas.
     *
     * Paso 4: cada fila lleva, según el rol, `postulantes` (conteo de postuladas,
     * solo a quien puede gestionar) o `mi_postulacion` (la del autenticado, solo
     * al aspirante). N+1: el conteo es una subconsulta y las postulaciones del
     * aspirante se cargan en UNA consulta para toda la página.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $datos = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'estado' => ['nullable', 'string'],
            'ficha_id' => ['nullable', 'integer', 'exists:fichas,id'],
            'q' => ['nullable', 'string', 'max:150'],
            'tipo_convocatoria' => ['nullable', 'string', 'max:50'],
            'modalidad' => ['nullable', 'string', 'max:50'],
            'orden' => ['nullable', 'string', 'in:reciente,antigua,nombre,cierre'],
        ], $this->mensajes(), $this->atributos());

        if (($datos['estado'] ?? null) !== null && ! in_array($datos['estado'], ConvocatoriaEstado::codigos(), true)) {
            throw ValidationException::withMessages(['estado' => 'El estado no es válido.']);
        }

        $esGestor = $user->can('convocatorias:gestionar');

        $query = Convocatoria::query()
            ->with($this->relaciones())
            ->whereIn('ficha_id', $user->fichaIds());

        if (! $esGestor) {
            // El aspirante solo ve las publicadas.
            $query->where('estado_id', $this->parametroId('estado_convocatoria', ConvocatoriaEstado::Publicada->value));

            // Filtrar por otro estado no le devuelve nada.
            if (($datos['estado'] ?? null) !== null && $datos['estado'] !== ConvocatoriaEstado::Publicada->value) {
                $query->whereRaw('1 = 0');
            }
        } elseif (($datos['estado'] ?? null) !== null) {
            $query->where('estado_id', $this->parametroId('estado_convocatoria', $datos['estado']));
        }

        // Quien no gestiona no ve las cerradas (estado cerrada o publicada con la
        // fecha de cierre ya pasada). Se filtra ANTES de paginar: conteos y
        // páginas coinciden.
        if (! $this->esGestorDeConvocatorias($user)) {
            $this->excluirCerradas($query);
        }

        // Filtro por ficha del alcance (lo usan super admin e instructor cuando
        // tienen más de una). Se aplica SOBRE el whereIn previo, así que una
        // ficha ajena simplemente no devuelve filas: nunca amplía la visibilidad.
        if (($datos['ficha_id'] ?? null) !== null) {
            $query->where('ficha_id', $datos['ficha_id']);
        }

        // El buscador aplica los mismos filtros a todos; el código (dato de
        // gestión) solo se busca para quien está en la lista blanca.
        $this->aplicarBuscador($query, $datos['q'] ?? null, $this->veIdentificadorConvocatoria($user));

        // Filtros por catálogo: el código se resuelve a id; uno inexistente no devuelve filas.
        $this->filtrarPorParametro($query, 'tipo_convocatoria_id', 'tipo_convocatoria', $datos['tipo_convocatoria'] ?? null);
        $this->filtrarPorParametro($query, 'modalidad_id', 'modalidad', $datos['modalidad'] ?? null);

        // Campos de postulación que le corresponden al usuario (sin N+1).
        $this->aplicarCamposDePostulacion($query, $user);

        $this->aplicarOrden($query, $datos['orden'] ?? 'reciente');

        $listado = $query
            ->paginate($datos['per_page'] ?? 20, ['*'], 'page', $datos['page'] ?? 1);

        return response()->json([
            'data' => $listado->getCollection()
                ->map(fn (Convocatoria $c) => $this->presentarResumen($c, $user))
                ->values(),
            'meta' => [
                'current_page' => $listado->currentPage(),
                'last_page' => $listado->lastPage(),
                'per_page' => $listado->perPage(),
                'total' => $listado->total(),
            ],
        ]);
    }
}
