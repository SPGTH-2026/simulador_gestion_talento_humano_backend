<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Parametro;
use App\Models\PerfilAspirante;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Perfil mínimo del aspirante. Por ahora expone solo el sexo, que se captura en
 * el paso de documentos (pida o no la convocatoria la libreta militar) y queda
 * prellenado entre postulaciones. El sexo NO crea postulación.
 */
class PerfilAspiranteController extends Controller
{
    /**
     * Perfil del aspirante autenticado: `{ "data": { "sexo": {...} | null } }`.
     * Funciona sin ninguna postulación (es del usuario, no de la convocatoria).
     */
    public function show(Request $request): JsonResponse
    {
        $this->exigirAspirante($request);

        $perfil = PerfilAspirante::query()
            ->where('user_id', $request->user()->id)
            ->with('sexo')
            ->first();

        return response()->json([
            'data' => ['sexo' => $this->parametro($perfil?->sexo)],
        ]);
    }

    /**
     * Alta/actualización del sexo del aspirante autenticado. Es idempotente: la
     * primera vez crea el perfil y después lo actualiza. El sexo se resuelve por
     * CÓDIGO del tema 'sexo' (hombre/mujer). No depende de tener postulación.
     */
    public function sexo(Request $request): JsonResponse
    {
        $this->exigirAspirante($request);

        $datos = $request->validate([
            'sexo' => ['required', 'string'],
        ], $this->mensajes(), $this->atributos());

        $parametro = Parametro::porTemaYCodigo('sexo', $datos['sexo']);

        if (! $parametro) {
            throw ValidationException::withMessages([
                'sexo' => 'El sexo debe ser masculino o femenino.',
            ]);
        }

        $perfil = PerfilAspirante::updateOrCreate(
            ['user_id' => $request->user()->id],
            ['sexo_id' => $parametro->id],
        );

        return response()->json([
            'data' => ['sexo' => $this->parametro($perfil->load('sexo')->sexo)],
        ]);
    }

    /**
     * El perfil es del aspirante. Instructor/super admin pasan el permiso
     * 'propio:documentos' pero no tienen perfil de aspirante.
     */
    private function exigirAspirante(Request $request): void
    {
        if ($request->user()->role !== Role::Aspirante) {
            abort(403, 'Solo los aspirantes tienen perfil de aspirante.');
        }
    }

    private function parametro(?Parametro $parametro): ?array
    {
        return $parametro
            ? ['id' => $parametro->id, 'codigo' => $parametro->codigo, 'nombre' => $parametro->nombre]
            : null;
    }

    /** @return array<string, string> */
    private function mensajes(): array
    {
        return [
            'required' => 'El campo :attribute es obligatorio.',
            'string' => 'El campo :attribute debe ser texto.',
        ];
    }

    /** @return array<string, string> */
    private function atributos(): array
    {
        return ['sexo' => 'sexo'];
    }
}
