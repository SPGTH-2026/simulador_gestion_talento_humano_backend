<?php

namespace App\Services;

use App\Models\User;

/**
 * Estado de la contraseña de una cuenta. La marca solo cambia con eventos comprobados (ver la migración) y no interviene en la
 * autenticación. Las cuentas históricas tienen NULL y se muestran como «desconocido».
 */
class ContrasenaDeCuenta
{
    public const DEFINIDA = 'definida';

    public const SIN_DEFINIR = 'sin_definir';

    public const DESCONOCIDO = 'desconocido';

    /** Valor que se expone: nunca se infiere; NULL (o cualquier otro valor) es «desconocido». */
    public function estado(User $user): string
    {
        return match ($user->password_estado) {
            self::DEFINIDA => self::DEFINIDA,
            self::SIN_DEFINIR => self::SIN_DEFINIR,
            default => self::DESCONOCIDO,
        };
    }

    /** Métodos de acceso de la PROPIA cuenta (solo se expone en la sesión de su dueño). */
    public function acceso(User $user): array
    {
        return [
            'google' => $user->socialAccounts()->exists(),
            'contrasena' => $this->estado($user),
        ];
    }
}
