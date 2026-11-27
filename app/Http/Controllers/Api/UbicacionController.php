<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Departamento;
use App\Models\Municipio;
use Illuminate\Http\JsonResponse;

/**
 * Departamentos y municipios de Colombia (DIVIPOLA) para los formularios. Son datos de consulta del DANE: solo lectura.
 * Se piden en dos pasos (primero los departamentos, luego los municipios del elegido) para no enviar 1.122 filas juntas.
 */
class UbicacionController extends Controller
{
    /** Los 33 departamentos, por orden alfabético: `{ data: [{ codigo, nombre }] }`. */
    public function departamentos(): JsonResponse
    {
        $departamentos = Departamento::query()
            ->orderBy('nombre')
            ->get(['codigo', 'nombre']);

        return response()->json(['data' => $departamentos]);
    }

    /** Municipios de un departamento, por orden alfabético. Un código que no existe responde 404. */
    public function municipios(string $departamento): JsonResponse
    {
        Departamento::query()->whereKey($departamento)->exists() || abort(404, 'Departamento no encontrado.');

        $municipios = Municipio::query()
            ->where('departamento_codigo', $departamento)
            ->orderBy('nombre')
            ->get(['codigo', 'nombre', 'tipo']);

        return response()->json(['data' => $municipios]);
    }
}
