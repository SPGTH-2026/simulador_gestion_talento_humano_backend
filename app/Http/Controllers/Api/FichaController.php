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
use App\Http\Controllers\Api\Concerns\GestionaFichas;

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
    use GestionaFichas;

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

        // Un instructor no puede quedarse sin ficha: si esta es la única, primero se le asigna otra.
        $instructorAQuitar = User::query()->findOrFail($datos['usuario_id']);
        if ($instructorAQuitar->fichas()->count() <= 1 && $instructorAQuitar->fichas()->whereKey($ficha->id)->exists()) {
            throw ValidationException::withMessages([
                'usuario_id' => 'El instructor debe quedar en al menos una ficha: asígnale otra antes de quitarlo de esta.',
            ]);
        }

        $ficha->instructores()->detach($datos['usuario_id']);

        $ficha->load(['creador', 'instructores'])->loadCount(['convocatorias', 'usuarios']);

        return response()->json([
            'data' => $this->presentar($ficha, $actor),
        ]);
    }

    // ── Autorización ───────────────────────────────────────────────────

    // ── Validación ─────────────────────────────────────────────────────

    // ── Presentación ───────────────────────────────────────────────────

    // ── Mensajes en español (sin cambiar APP_LOCALE) ────────────────────

}
