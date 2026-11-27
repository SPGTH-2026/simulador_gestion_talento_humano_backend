<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Enums\TipoNotificacion;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Notificaciones\Notificador;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    /**
     * Reglas de ficha_id. El super admin ve todas las fichas, así que a él sí se le valida que exista. Al instructor NO:
     * «la ficha no existe» y «la ficha es de otro» deben ser indistinguibles para que no pueda averiguar qué fichas hay
     * fuera de su alcance. En su caso el alcance lo decide `enAlcanceDe`/`fichaIds()` más abajo, con una sola respuesta.
     */
    private function reglasDeFicha(User $actor): array
    {
        return $actor->role === Role::SuperAdmin
            ? ['nullable', 'integer', 'exists:fichas,id']
            : ['nullable', 'integer'];
    }

    /**
     * Lista de usuarios, paginada. El super admin ve todo; el instructor ve
     * únicamente los usuarios de sus fichas más los aspirantes sin ficha
     * (para poder traerlos a su grupo). Admite filtrar por rol (p. ej. el
     * portafolio documental pide role=aspirante).
     */
    public function index(Request $request): JsonResponse
    {
        $actor = $request->user();

        $datos = $request->validate([
            'ficha_id' => $this->reglasDeFicha($actor),
            'role' => ['nullable', Rule::enum(Role::class)],
            'search' => ['nullable', 'string', 'max:120'],
            // El portafolio documental pide solo gente que ya se haya postulado.
            'con_postulaciones' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = User::query()->with(['ficha', 'fichas:id,codigo'])->withCount('postulaciones');

        if ($datos['ficha_id'] ?? null) {
            $query->where('ficha_id', $datos['ficha_id']);
        }

        if ($datos['role'] ?? null) {
            $query->where('role', $datos['role']);
        }

        if ($request->boolean('con_postulaciones')) {
            $query->whereHas('postulaciones');
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

            // Con un rol explícito (p. ej. el portafolio pide solo aspirantes) el
            // alcance se limita a las fichas del actor: así no se listan personas
            // de las que luego no podría abrir el portafolio (daría 404).
            if ($datos['role'] ?? null) {
                $query->whereIn('ficha_id', $scope);
            } else {
                $query->enAlcanceDe($actor);
            }
        }

        // Orden global por rol (aspirante, aprendiz, instructor, resto) y nombre: la página se ordena en el servidor, no en
        // el cliente, para que cada página empiece donde termina la anterior.
        $usuarios = $query
            ->orderByRaw("CASE role WHEN 'aspirante' THEN 0 WHEN 'aprendiz' THEN 1 WHEN 'instructor' THEN 2 ELSE 3 END")
            ->orderBy('name')
            ->paginate($datos['per_page'] ?? 20);

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

    /**
     * Asigna rol/subrol/estado/ficha. La matriz de autorización:
     *  - Nadie modifica su propio usuario.
     *  - El super admin puede todo lo demás.
     *  - El instructor solo mueve aspirantes/aprendices y los deja en sus fichas;
     *    no puede tocar a instructores ni super admins.
     */
    public function update(Request $request, User $user): JsonResponse
    {
        $actor = $request->user();

        // Aislamiento antes que cualquier validación del payload: un aspirante o
        // aprendiz fuera del alcance del instructor (ficha ajena) responde 404,
        // sea válido o no el cuerpo, para no revelar que existe.
        if (
            $actor->role === Role::Instructor
            && in_array($user->role, [Role::Aspirante, Role::Aprendiz], true)
            && ! User::query()->enAlcanceDe($actor)->whereKey($user->id)->exists()
        ) {
            abort(404, 'Usuario no encontrado.');
        }

        $datos = $request->validate([
            'role' => ['required', Rule::enum(Role::class)],
            'subrole' => ['nullable', 'string'],
            'active' => ['required', 'boolean'],
            'ficha_id' => $this->reglasDeFicha($actor),
            // Solo para el rol instructor: las fichas que asigna el super admin (un instructor vive en una o más).
            'fichas' => ['nullable', 'array'],
            'fichas.*' => ['integer', 'exists:fichas,id'],
        ]);

        if ($user->id === $request->user()->id) {
            throw ValidationException::withMessages(['role' => 'No puedes modificar tu propio usuario']);
        }

        // Solo existe un super admin (la cuenta de SUPER_ADMIN_EMAIL). No se crea ni se modifica desde la aplicación.
        if ($user->role === Role::SuperAdmin) {
            throw ValidationException::withMessages(['role' => 'La cuenta de super admin no se modifica desde la aplicación']);
        }
        if ($datos['role'] === Role::SuperAdmin->value) {
            throw ValidationException::withMessages(['role' => 'El rol super admin no se asigna desde la aplicación: solo existe una cuenta']);
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
        $subrolesAprendiz = ['general', 'evaluador', 'seleccionador', 'gestor_convocatorias'];
        if ($datos['role'] === Role::Aprendiz->value) {
            if (! in_array($subrole, $subrolesAprendiz, true)) {
                throw ValidationException::withMessages(['subrole' => 'Subrol inválido para el rol aprendiz']);
            }
        } elseif ($subrole !== null) {
            throw ValidationException::withMessages(['subrole' => 'Solo los aprendices tienen subrol']);
        }

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

        // La ficha es obligatoria para el aspirante: sin ficha no puede existir una cuenta de aspirante.
        if ($datos['role'] === Role::Aspirante->value && ! $fichaId) {
            throw ValidationException::withMessages(['ficha_id' => 'El aspirante debe quedar en una ficha']);
        }
        // Un instructor puede quedar sin fichas: crea la suya desde Fichas (la ficha que registra queda a su nombre).
        $fichasInstructor = array_values(array_unique(array_map('intval', $datos['fichas'] ?? [])));
        $eraInstructor = $user->role === Role::Instructor;

        $user->forceFill([
            'role' => $datos['role'],
            'subrole' => $subrole ?: null,
            'active' => $datos['active'],
            'ficha_id' => $fichaId ?: null,
        ])->save();

        // Las fichas de un instructor viven en la tabla ficha_instructor; cualquier otro rol no las conserva.
        if ($datos['role'] === Role::Instructor->value) {
            $user->fichas()->sync($fichasInstructor);
        } else {
            $user->fichas()->detach();
        }

        // Recién convertido en instructor y sin ninguna ficha: se le avisa que cree la suya para empezar.
        if ($datos['role'] === Role::Instructor->value && ! $eraInstructor && $user->fichas()->doesntExist()) {
            app(Notificador::class)->enviar(
                $user,
                TipoNotificacion::InstructorCrearFicha,
                'Tu cuenta de instructor está activa',
                'Crea tu ficha desde la sección Fichas para empezar a administrarla.',
                '/fichas',
            );
        }

        // Al cambiar rol/estado se invalidan las sesiones y tokens antiguos: si alguien
        // era aprendiz con permisos y dejó de serlo, cerrar la pestaña no debe
        // mantenerlo dentro con privilegios viejos.
        DB::table('sessions')->where('user_id', $user->id)->delete();
        $user->tokens()->delete();

        return response()->json(['data' => $this->presentar($user->refresh()->load(['ficha', 'fichas:id,codigo']))]);
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
            // Solo el listado lo trae conCount('postulaciones'); en el resto de
            // respuestas (p. ej. update) no se ha cargado y queda 0.
            'postulaciones_count' => (int) ($u->postulaciones_count ?? 0),
            // Última actividad del registro, para la tabla del portafolio.
            'updated_at' => $u->updated_at?->toIso8601String(),
            'ficha' => $u->ficha_id
                ? [
                    'id' => $u->ficha_id,
                    'codigo' => $u->ficha?->codigo,
                    'nombre_programa' => $u->ficha?->nombre_programa,
                ]
                : null,
            // Fichas de un instructor (tabla ficha_instructor). Vacío para los demás roles.
            'fichas' => $u->relationLoaded('fichas')
                ? $u->fichas->map(fn ($f) => ['id' => $f->id, 'codigo' => $f->codigo])->values()->all()
                : [],
        ];
    }
}
