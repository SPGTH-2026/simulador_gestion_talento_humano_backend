<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Parametro;
use App\Models\ParametroTema;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ParametroController extends Controller
{
    /**
     * Catálogo de parámetros para los formularios del módulo de convocatorias.
     *
     * - Sin ?tema=: devuelve el catálogo completo agrupado por tema.
     * - Con ?tema=<codigo>: devuelve solo los parámetros de ese tema (lista plana).
     *   Si el tema no existe, responde 404.
     *
     * Solo se devuelven parámetros activos, ordenados por 'orden' y luego por
     * 'nombre'. La respuesta expone únicamente los campos que el frontend usa
     * (id, codigo, nombre, es_sistema, es_defecto): nunca timestamps ni tema_id.
     */
    public function index(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'tema' => ['nullable', 'string', 'max:60'],
        ]);

        $temaCodigo = $datos['tema'] ?? null;

        // Filtro por tema: lista plana de sus parámetros activos.
        if ($temaCodigo !== null) {
            $tema = ParametroTema::where('codigo', $temaCodigo)->where('activo', true)->first();

            if (! $tema) {
                abort(404, 'Tema de parámetros no encontrado');
            }

            return response()->json(['data' => $this->parametrosDeTema($tema)]);
        }

        // Catálogo completo: un grupo por tema activo que tenga parámetros activos.
        $temas = ParametroTema::query()
            ->where('activo', true)
            ->whereHas('parametros', fn ($query) => $query->where('activo', true))
            ->orderBy('id')
            ->get();

        return response()->json([
            'data' => $temas->map(fn (ParametroTema $tema) => [
                'tema' => $tema->codigo,
                'nombre' => $tema->nombre,
                'parametros' => $this->parametrosDeTema($tema),
            ])->values(),
        ]);
    }

    /**
     * Parámetros activos de un tema, ordenados por 'orden' y luego por 'nombre',
     * con solo los campos públicos.
     *
     * @return array<int, array{id: int, codigo: ?string, nombre: string, es_sistema: bool, es_defecto: bool}>
     */
    private function parametrosDeTema(ParametroTema $tema): array
    {
        return Parametro::query()
            ->where('tema_id', $tema->id)
            ->where('activo', true)
            ->orderBy('orden')
            ->orderBy('nombre')
            ->get(['id', 'codigo', 'nombre', 'es_sistema', 'es_defecto'])
            ->map(fn (Parametro $parametro) => [
                'id' => $parametro->id,
                'codigo' => $parametro->codigo,
                'nombre' => $parametro->nombre,
                'es_sistema' => $parametro->es_sistema,
                'es_defecto' => $parametro->es_defecto,
            ])
            ->all();
    }
}
