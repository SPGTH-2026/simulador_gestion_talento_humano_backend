<?php

namespace App\Services\Notificaciones;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Quién forma parte del personal de una ficha, por rol. Solo cuentan los usuarios activos.
 *
 *  - Instructor: los que acompañan la ficha (pivote ficha_instructor) o la registraron.
 *  - Aprendiz: los de la ficha (users.ficha_id) con el subrol pedido (evaluador, seleccionador, gestor_convocatorias).
 *
 * El super admin no entra aquí: no recibe avisos operativos de las fichas.
 */
final class Destinatarios
{
    /**
     * Aspirantes activos de una ficha: los que reciben los avisos de la convocatoria de esa ficha.
     *
     * @return Collection<int, User>
     */
    public function aspirantesDeFicha(int $fichaId): Collection
    {
        return User::query()
            ->where('active', true)
            ->where('role', Role::Aspirante->value)
            ->where('ficha_id', $fichaId)
            ->get();
    }

    /**
     * @param  list<string>  $subroles  subroles de aprendiz que reciben el aviso (vacío = ninguno)
     * @param  bool  $instructores  si también reciben los instructores de la ficha
     * @return Collection<int, User>
     */
    public function personalDeFicha(int $fichaId, array $subroles = [], bool $instructores = true): Collection
    {
        // Sin destinatarios posibles, no se consulta: un grupo de condiciones vacío no filtraría nada y traería a todos.
        if (! $instructores && $subroles === []) {
            return collect();
        }

        $consulta = User::query()
            ->where('active', true)
            ->where(function (Builder $q) use ($fichaId, $subroles, $instructores) {
                if ($instructores) {
                    $q->orWhere(function (Builder $instructor) use ($fichaId) {
                        $instructor->where('role', Role::Instructor->value)
                            ->where(function (Builder $vinculo) use ($fichaId) {
                                // Mismo alcance que User::fichaIds(): pivote, ficha que registró, o ficha_id del propio usuario.
                                $vinculo->whereHas('fichas', fn (Builder $f) => $f->where('fichas.id', $fichaId))
                                    ->orWhere('users.ficha_id', $fichaId)
                                    ->orWhereIn('users.id', fn ($sub) => $sub->select('creado_por')
                                        ->from('fichas')->where('id', $fichaId)->whereNotNull('creado_por'));
                            });
                    });
                }

                if ($subroles !== []) {
                    $q->orWhere(function (Builder $aprendiz) use ($fichaId, $subroles) {
                        $aprendiz->where('role', Role::Aprendiz->value)
                            ->where('ficha_id', $fichaId)
                            ->whereIn('subrole', $subroles);
                    });
                }
            });

        return $consulta->get();
    }
}
