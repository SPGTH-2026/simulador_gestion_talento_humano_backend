<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Ficha;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * API del módulo de fichas (grupo de formación).
 *
 * Reglas de negocio:
 *  - El CRUD exige el permiso 'usuarios:gestionar' (lo tienen super admin e
 *    instructor). El super admin ve TODAS las fichas; el resto solo las que
 *    tiene en su alcance.
 *  - Auto-ownership: la ficha que registra un instructor queda a su nombre
 *    (fichas.creado_por). Ese instructor es su DUEÑO: la ve, la edita y la
 *    borra aunque nadie se la asigne por el pivot.
 *  - El pivot ficha_instructor (asignar/quitar instructor) solo lo mueve el
 *    super admin: es quien reparte los grupos.
 *  - Fuera del alcance se responde 404, sin revelar que la ficha existe.
 *  - No se puede borrar una ficha que ya tiene convocatorias o personas: la
 *    primera la bloquea la FK (restrictOnDelete) y la segunda dejaría a los
 *    aspirantes sin grupo.
 */
class FichaController extends Controller
{
    /** Estados válidos de una ficha. */
    private const ESTADOS = ['activa', 'cerrada'];

    /** Campos que asigna el sistema y que nunca se aceptan en el cuerpo. */
    private const RESERVADOS = ['id', 'creado_por'];

    /**
     * Listado de fichas del alcance del usuario. La respuesta conserva la
     * forma que usan los selectores del frontend: { data: [ { id, codigo,
     * nombre_programa, estado, ... } ] }.
     */
    public function index(Request $request): JsonResponse
    {
        $actor = $request->user();

        $fichas = Ficha::query()
            ->whereIn('id', $actor->fichaIds())
            ->with(['creador', 'instructores'])
            ->withCount(['convocatorias', 'usuarios'])
            ->orderBy('codigo')
            ->get();

        return response()->json([
            'data' => $fichas->map(fn (Ficha $ficha) => $this->presentar($ficha, $actor))->values(),
        ]);
    }

    /**
     * Alta de ficha. La registra el usuario autenticado, que queda como
     * creador (dueño). El código es único.
     */
    public function store(Request $request): JsonResponse
    {
        $actor = $request->user();

        $this->rechazarReservados($request);
        $this->recortarTexto($request, 'codigo');
        $this->recortarTexto($request, 'nombre_programa');

        $datos = $request->validate([
            'codigo' => ['required', 'string', 'max:50', Rule::unique('fichas', 'codigo')],
            'nombre_programa' => ['required', 'string', 'max:150'],
            'estado' => ['sometimes', 'required', 'string', Rule::in(self::ESTADOS)],
        ], $this->mensajes(), $this->atributos());

        $ficha = new Ficha();
        $ficha->forceFill([
            'codigo' => $datos['codigo'],
            'nombre_programa' => $datos['nombre_programa'],
            'estado' => $datos['estado'] ?? 'activa',
            'creado_por' => $actor->id,
        ])->save();

        $ficha->load('creador');

        return response()->json([
            'data' => $this->presentar($ficha, $actor),
        ], 201);
    }

    /**
     * Detalle de una ficha. Fuera del alcance (otra ficha) se responde 404,
     * sin revelar que existe.
     */
    public function show(Request $request, Ficha $ficha): JsonResponse
    {
        $actor = $request->user();
        $this->autorizarLectura($ficha, $actor);

        $ficha->load(['creador', 'instructores'])->loadCount(['convocatorias', 'usuarios']);

        return response()->json([
            'data' => $this->presentar($ficha, $actor),
        ]);
    }

    /**
     * Edición parcial (código, nombre del programa y estado). Solo el super
     * admin y el dueño de la ficha pueden editarla: un instructor asignado por
     * el pivot tiene alcance sobre la ficha, pero no la gestiona.
     */
    public function update(Request $request, Ficha $ficha): JsonResponse
    {
        $actor = $request->user();

        $this->autorizarGestion($ficha, $actor);
        $this->rechazarReservados($request);
        $this->recortarTexto($request, 'codigo');
        $this->recortarTexto($request, 'nombre_programa');

        $datos = $request->validate([
            'codigo' => ['sometimes', 'required', 'string', 'max:50', Rule::unique('fichas', 'codigo')->ignore($ficha->id)],
            'nombre_programa' => ['sometimes', 'required', 'string', 'max:150'],
            'estado' => ['sometimes', 'required', 'string', Rule::in(self::ESTADOS)],
        ], $this->mensajes(), $this->atributos());

        $ficha->forceFill($datos)->save();

        $ficha->load(['creador', 'instructores'])->loadCount(['convocatorias', 'usuarios']);

        return response()->json([
            'data' => $this->presentar($ficha, $actor),
        ]);
    }

    /**
     * Baja de ficha. Se bloquea (422) si tiene convocatorias o personas
     * asignadas: borrarla dejaría procesos y aspirantes sin grupo.
     */
    public function destroy(Request $request, Ficha $ficha): JsonResponse
    {
        $actor = $request->user();
        $this->autorizarGestion($ficha, $actor);

        if ($ficha->convocatorias()->exists()) {
            throw ValidationException::withMessages([
                'ficha' => 'No se puede eliminar una ficha que tiene convocatorias.',
            ]);
        }

        if ($ficha->usuarios()->exists()) {
            throw ValidationException::withMessages([
                'ficha' => 'No se puede eliminar una ficha que tiene personas asignadas.',
            ]);
        }

        $id = (int) $ficha->id;
        $ficha->delete();

        return response()->json([
            'data' => ['id' => $id, 'eliminada' => true],
        ]);
    }

    /**
     * Asigna un instructor a la ficha (pivot ficha_instructor). Solo el super
     * admin reparte los grupos: el instructor no se asigna a sí mismo.
     */
    public function asignarInstructor(Request $request, Ficha $ficha): JsonResponse
    {
        $actor = $request->user();
        $this->exigirSuperAdmin($actor);

        $datos = $request->validate([
            'usuario_id' => ['required', 'integer', 'exists:users,id'],
        ], $this->mensajes(), $this->atributos());

        $instructor = User::query()->findOrFail($datos['usuario_id']);

        if ($instructor->role !== Role::Instructor) {
            throw ValidationException::withMessages([
                'usuario_id' => 'El usuario debe tener el rol instructor.',
            ]);
        }

        $ficha->instructores()->syncWithoutDetaching([$instructor->id]);

        $ficha->load(['creador', 'instructores'])->loadCount(['convocatorias', 'usuarios']);

        return response()->json([
            'data' => $this->presentar($ficha, $actor),
        ]);
    }

    /** Quita un instructor de la ficha. Solo el super admin. */
    public function quitarInstructor(Request $request, Ficha $ficha): JsonResponse
    {
        $actor = $request->user();
        $this->exigirSuperAdmin($actor);

        $datos = $request->validate([
            'usuario_id' => ['required', 'integer', 'exists:users,id'],
        ], $this->mensajes(), $this->atributos());

        $ficha->instructores()->detach($datos['usuario_id']);

        $ficha->load(['creador', 'instructores'])->loadCount(['convocatorias', 'usuarios']);

        return response()->json([
            'data' => $this->presentar($ficha, $actor),
        ]);
    }

    // ── Autorización ───────────────────────────────────────────────────

    /**
     * Frontera de aislamiento: una ficha fuera del alcance del usuario se
     * responde 404 (no se revela que existe).
     */
    private function autorizarLectura(Ficha $ficha, User $actor): void
    {
        $alcance = array_map('intval', $actor->fichaIds());

        if (! in_array((int) $ficha->id, $alcance, true)) {
            abort(404, 'Ficha no encontrada.');
        }
    }

    /**
     * Gestión (editar/borrar): el super admin o el dueño de la ficha (quien la
     * registró). Un instructor asignado por el pivot tiene alcance, pero no
     * gestiona: responde 403.
     */
    private function autorizarGestion(Ficha $ficha, User $actor): void
    {
        $this->autorizarLectura($ficha, $actor);

        if ($actor->role === Role::SuperAdmin) {
            return;
        }

        if ((int) $ficha->creado_por === (int) $actor->id) {
            return;
        }

        abort(403, 'Solo el super admin o el creador de la ficha pueden gestionarla.');
    }

    /** El reparto de instructores es exclusiva del super admin. */
    private function exigirSuperAdmin(User $actor): void
    {
        if ($actor->role !== Role::SuperAdmin) {
            abort(403, 'Solo el super admin puede asignar o quitar instructores de una ficha.');
        }
    }

    // ── Validación ─────────────────────────────────────────────────────

    /** Recorta un campo de texto antes de validarlo (para que el único no se evada con espacios). */
    private function recortarTexto(Request $request, string $campo): void
    {
        $valor = $request->input($campo);

        if (is_string($valor)) {
            $request->merge([$campo => trim($valor)]);
        }
    }

    private function rechazarReservados(Request $request): void
    {
        $presentes = array_values(array_filter(
            self::RESERVADOS,
            fn (string $campo) => $request->has($campo),
        ));

        if ($presentes !== []) {
            throw ValidationException::withMessages([
                'campos' => 'Estos campos los asigna el sistema y no se pueden enviar: '.implode(', ', $presentes).'.',
            ]);
        }
    }

    // ── Presentación ───────────────────────────────────────────────────

    private function presentar(Ficha $ficha, User $actor): array
    {
        return [
            'id' => (int) $ficha->id,
            'codigo' => $ficha->codigo,
            'nombre_programa' => $ficha->nombre_programa,
            'estado' => $ficha->estado,
            'creado_por' => $ficha->creador
                ? [
                    'id' => (int) $ficha->creador->id,
                    'name' => $ficha->creador->name,
                    'email' => $ficha->creador->email,
                ]
                : null,
            'instructores' => $ficha->instructores->map(fn (User $u) => [
                'id' => (int) $u->id,
                'name' => $u->name,
                'email' => $u->email,
            ])->values(),
            'convocatorias_count' => (int) ($ficha->convocatorias_count ?? 0),
            'usuarios_count' => (int) ($ficha->usuarios_count ?? 0),
            'puede_gestionar' => $actor->role === Role::SuperAdmin || (int) $ficha->creado_por === (int) $actor->id,
            'puede_asignar_instructores' => $actor->role === Role::SuperAdmin,
            'updated_at' => $ficha->updated_at?->toIso8601String(),
        ];
    }

    // ── Mensajes en español (sin cambiar APP_LOCALE) ────────────────────

    /** @return array<string, string> */
    private function mensajes(): array
    {
        return [
            'required' => 'El campo :attribute es obligatorio.',
            'string' => 'El campo :attribute debe ser texto.',
            'integer' => 'El campo :attribute debe ser un número entero.',
            'max' => 'El campo :attribute no debe superar los :max caracteres.',
            'unique' => 'Ya existe una ficha con este código.',
            'exists' => 'El campo :attribute seleccionado no es válido.',
            'in' => 'El campo :attribute seleccionado no es válido.',
        ];
    }

    /** @return array<string, string> */
    private function atributos(): array
    {
        return [
            'codigo' => 'código',
            'nombre_programa' => 'nombre del programa',
            'estado' => 'estado',
            'usuario_id' => 'instructor',
        ];
    }
}
