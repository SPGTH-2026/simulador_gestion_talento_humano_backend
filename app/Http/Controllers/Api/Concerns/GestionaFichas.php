<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Enums\Role;
use App\Models\Ficha;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

trait GestionaFichas
{
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
