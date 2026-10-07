<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Ficha;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    /**
     * Lista de usuarios, paginada. El super admin ve todo; el instructor ve
     * únicamente los usuarios de sus fichas más los aspirantes sin ficha
     * (para poder traerlos a su grupo).
     */
    public function index(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'ficha_id' => ['nullable', 'integer', 'exists:fichas,id'],
            'search' => ['nullable', 'string', 'max:120'],
        ]);

        $actor = $request->user();
        $query = User::query()->with('ficha');

        if ($datos['ficha_id'] ?? null) {
            $query->where('ficha_id', $datos['ficha_id']);
        }

        if ($datos['search'] ?? null) {
            $b = $datos['search'];
            $query->where(fn ($q) => $q->where('name', 'like', "%{$b}%")->orWhere('email', 'like', "%{$b}%"));
        }

        // El instructor no ve las demás fichas: solo lo suyo más los aspirantes
        // libres (los que todavía no pertenecen a ninguna ficha, para poder
        // traerlos a su grupo). El super admin no tiene restricción de alcance.
        if ($actor->role !== Role::SuperAdmin) {
            $scope = $actor->fichaIds();
            $query->where(function ($q) use ($scope) {
                $q->where(function ($a) use ($scope) {
                    $a->whereIn('ficha_id', $scope)->where('role', '!=', Role::Aspirante->value);
                })->orWhere('role', Role::Aspirante->value);
            });
        }

        $usuarios = $query->orderBy('role')->orderBy('name')->paginate(20);

        return response()->json([
            'data' => $usuarios->getCollection()->map(fn (User $u) => $this->presentar($u)),
            'meta' => [
                'current_page' => $usuarios->currentPage(),
                'last_page' => $usuarios->lastPage(),
                'per_page' => $usuarios->perPage(),
                'total' => $usuarios->total(),
            ],
        ]);
    }

    /** Fichas sobre las que este usuario tiene alcance (para selects y filtros). */
    public function fichas(Request $request): JsonResponse
    {
        $fichas = Ficha::query()
            ->whereIn('id', $request->user()->fichaIds())
            ->orderBy('codigo')
            ->get(['id', 'codigo', 'nombre_programa', 'estado']);

        return response()->json(['data' => $fichas]);
    }

    /**
     * Asigna rol/subrol/estado/ficha. La matriz de autorización:
     *  - Nadie modifica su propio usuario.
     *  - El super admin puede todo lo demás.
     *  - El instructor solo mueve aspirantes/aprendices y los deja en sus fichas;
     *    no puede tocar a instructores ni super admins.
     */
    public function update(Request $request, User $user): JsonResponse
    {
        $datos = $request->validate([
            'role' => ['required', Rule::enum(Role::class)],
            'subrole' => ['nullable', 'string'],
            'active' => ['required', 'boolean'],
            'ficha_id' => ['nullable', 'integer', 'exists:fichas,id'],
        ]);

        if ($user->id === $request->user()->id) {
            throw ValidationException::withMessages(['role' => 'No puedes modificar tu propio usuario']);
        }

        // 'nullable' valida pero no incluye la clave en $datos si el request no la manda.
        $subrole = $datos['subrole'] ?? null;
        $fichaId = $datos['ficha_id'] ?? null;

        // Un aprendiz siempre pertenece a una ficha; instructor y super admin a ninguna.
        if ($datos['role'] === Role::Aprendiz->value && ! $fichaId) {
            throw ValidationException::withMessages(['ficha_id' => 'El aprendiz debe quedar en una ficha']);
        }
        if (! in_array($datos['role'], [Role::Aprendiz->value, Role::Aspirante->value], true) && $fichaId !== null) {
            throw ValidationException::withMessages(['ficha_id' => 'Instructor y super admin no pertenecen a una ficha']);
        }

        // Solo los aprendices llevan subrol.
        $subrolesAprendiz = ['general', 'evaluador', 'seleccionador', 'revisor_documental', 'gestor_convocatorias'];
        if ($datos['role'] === Role::Aprendiz->value) {
            if (! in_array($subrole, $subrolesAprendiz, true)) {
                throw ValidationException::withMessages(['subrole' => 'Subrol inválido para el rol aprendiz']);
            }
        } elseif ($subrole !== null) {
            throw ValidationException::withMessages(['subrole' => 'Solo los aprendices tienen subrol']);
        }

        $actor = $request->user();
        if ($actor->role === Role::Instructor) {
            if (in_array($user->role, [Role::Instructor, Role::SuperAdmin], true)) {
                throw ValidationException::withMessages(['role' => 'No puedes modificar a un instructor o super admin']);
            }

            if (! in_array($datos['role'], [Role::Aspirante->value, Role::Aprendiz->value], true)) {
                throw ValidationException::withMessages(['role' => 'El instructor solo asigna aprendiz o revierte a aspirante']);
            }

            // Sea cual sea el rol, el instructor mueve a la persona solo dentro de sus fichas.
            if ($fichaId !== null && ! in_array((int) $fichaId, $actor->fichaIds(), true)) {
                throw ValidationException::withMessages(['ficha_id' => 'La ficha debe ser una de las tuyas']);
            }
        }

        $user->forceFill([
            'role' => $datos['role'],
            'subrole' => $subrole ?: null,
            'active' => $datos['active'],
            'ficha_id' => $fichaId ?: null,
        ])->save();

        // Al cambiar rol/estado se invalidan las sesiones y tokens antiguos: si alguien
        // era aprendiz con permisos y dejó de serlo, cerrar la pestaña no debe
        // mantenerlo dentro con privilegios viejos.
        DB::table('sessions')->where('user_id', $user->id)->delete();
        $user->tokens()->delete();

        return response()->json(['data' => $this->presentar($user->refresh()->load('ficha'))]);
    }

    private function presentar(User $u): array
    {
        return [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'role' => $u->role->value,
            'subrole' => $u->subrole,
            'active' => (bool) $u->active,
            'email_verified' => (bool) $u->email_verified_at,
            'ficha' => $u->ficha_id
                ? [
                    'id' => $u->ficha_id,
                    'codigo' => $u->ficha?->codigo,
                    'nombre_programa' => $u->ficha?->nombre_programa,
                ]
                : null,
        ];
    }
}