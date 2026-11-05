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
     * Crea un documento NUEVO en el catálogo (tema tipo_documento) para que el
     * gestor lo pueda sumar a una convocatoria con su propio porcentaje.
     *
     * Pensado para requisitos que no están en el catálogo base (especialización,
     * maestría, diplomado, etc.). El documento nace activo, sin código y sin ser
     * de sistema: no altera las reglas de los obligatorios fijos.
     *
     * El nombre es único dentro del tema (sin distinguir mayúsculas), para no
     * llenar el catálogo de duplicados.
     */
    public function storeDocumento(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'nombre' => ['required', 'string', 'min:3', 'max:80'],
        ]);

        $tema = ParametroTema::where('codigo', 'tipo_documento')->first();

        if (! $tema) {
            abort(404, 'No existe el catálogo de documentos');
        }

        $nombre = trim($datos['nombre']);

        $duplicado = Parametro::query()
            ->where('tema_id', $tema->id)
            ->whereRaw('LOWER(nombre) = ?', [mb_strtolower($nombre)])
            ->exists();

        if ($duplicado) {
            return response()->json([
                'message' => 'Ya existe un documento con ese nombre.',
                'errors' => ['nombre' => ['Ya existe un documento con ese nombre.']],
            ], 422);
        }

        $parametro = Parametro::create([
            'tema_id' => $tema->id,
            'codigo' => null,
            'nombre' => $nombre,
            'orden' => ((int) Parametro::where('tema_id', $tema->id)->max('orden')) + 1,
            'activo' => true,
            'es_sistema' => false,
            'es_defecto' => false,
        ]);

        return response()->json([
            'data' => [
                'id' => $parametro->id,
                'codigo' => $parametro->codigo,
                'nombre' => $parametro->nombre,
                'es_sistema' => false,
                'es_defecto' => false,
            ],
        ], 201);
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
