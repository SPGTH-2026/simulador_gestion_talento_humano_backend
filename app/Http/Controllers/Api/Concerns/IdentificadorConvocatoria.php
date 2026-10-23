<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Enums\Role;
use App\Models\User;

/**
 * Identificador de la convocatoria (CONV-AAAA-NNN): es un dato de GESTIÓN.
 *
 * Lista blanca de quién lo ve:
 *  - instructor;
 *  - super admin;
 *  - aprendiz con el subrol 'gestor_convocatorias'.
 *
 * El permiso 'convocatorias:gestionar' NO alcanza por sí solo: el aprendiz con
 * el subrol 'general' también lo tiene (ver config/permissions.php) y no debe
 * ver el identificador. Por eso se comprueban las DOS cosas: el permiso Y la
 * lista blanca de rol/subrol. No se usa "role !== aspirante": esa comprobación
 * negativa dejaría pasar al aprendiz general.
 *
 * Para el resto de usuarios la clave 'codigo' llega en null: el contrato del
 * JSON no cambia (la clave sigue existiendo), solo el dato.
 */
trait IdentificadorConvocatoria
{
    protected function veIdentificadorConvocatoria(User $user): bool
    {
        return $this->esGestorDeConvocatorias($user);
    }

    /**
     * Predicado estricto de gestión de convocatorias: el permiso Y la lista
     * blanca de rol/subrol (instructor, super admin, gestor_convocatorias).
     * Lo usan el identificador y la visibilidad de convocatorias cerradas.
     */
    protected function esGestorDeConvocatorias(User $user): bool
    {
        if (! $user->can('convocatorias:gestionar')) {
            return false;
        }

        if (in_array($user->role, [Role::Instructor, Role::SuperAdmin], true)) {
            return true;
        }

        return $user->role === Role::Aprendiz
            && $user->subrole === 'gestor_convocatorias';
    }
}
