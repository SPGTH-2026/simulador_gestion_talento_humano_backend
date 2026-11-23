<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Enums\ConvocatoriaEstado;
use App\Enums\PostulacionEstado;
use App\Enums\Role;
use App\Models\Parametro;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

trait FiltraConvocatorias
{
    /** Filtra por un parámetro de catálogo dado su código de tema y su código. */
    private function filtrarPorParametro(Builder $query, string $columna, string $tema, ?string $codigo): void
    {
        if ($codigo === null || $codigo === '') {
            return;
        }

        $parametro = Parametro::porTemaYCodigo($tema, $codigo);

        if ($parametro === null) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where($columna, $parametro->id);
    }

    /**
     * Orden del listado. 'reciente' conserva el criterio original.
     * Siempre desempata por id para que la paginación sea estable.
     */
    private function aplicarOrden(Builder $query, string $orden): void
    {
        match ($orden) {
            'antigua' => $query->orderBy('fecha_apertura')->orderBy('id'),
            'nombre' => $query->orderBy('nombre')->orderBy('id'),
            // Las convocatorias sin fecha de cierre van al final.
            'cierre' => $query->orderByRaw('fecha_cierre IS NULL')->orderBy('fecha_cierre')->orderBy('id'),
            default => $query->orderByDesc('fecha_apertura')->orderByDesc('id'),
        };
    }

    private function aplicarBuscador(Builder $query, ?string $q, bool $buscaPorCodigo = true): void
    {
        $termino = trim((string) $q);

        if ($termino === '') {
            return;
        }

        // Se usa '!' como carácter de escape: es válido en MySQL, SQLite y
        // Postgres y no obliga a pelear con el escapado de la barra invertida.
        // LOWER en ambos lados: LIKE de Postgres distingue mayúsculas (MySQL no).
        $escapado = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $termino);
        $patron = '%'.$escapado.'%';

        $query->where(function (Builder $sub) use ($patron, $buscaPorCodigo) {
            $sub->whereRaw("LOWER(nombre) LIKE LOWER(?) ESCAPE '!'", [$patron]);

            if ($buscaPorCodigo) {
                $sub->orWhereRaw("LOWER(codigo) LIKE LOWER(?) ESCAPE '!'", [$patron]);
            }

            $sub->orWhereRaw("LOWER(cargo) LIKE LOWER(?) ESCAPE '!'", [$patron]);
        });
    }

    /** Quita de la consulta las cerradas (ver estaCerrada), en SQL y antes de paginar. */
    private function excluirCerradas(Builder $query): void
    {
        $hoy = now('America/Bogota')->toDateString();

        $query
            ->where('estado_id', '!=', $this->parametroId('estado_convocatoria', ConvocatoriaEstado::Cerrada->value))
            ->where(function (Builder $sub) use ($hoy) {
                $sub->where('estado_id', '!=', $this->parametroId('estado_convocatoria', ConvocatoriaEstado::Publicada->value))
                    ->orWhereNull('fecha_cierre')
                    ->orWhereDate('fecha_cierre', '>=', $hoy);
            });
    }

    /**
     * Prepara la consulta del listado con los campos de postulación que le
     * corresponden al usuario, SIN N+1:
     *  - quien gestiona: `postulantes_count` como subconsulta (withCount).
     *  - aspirante: sus propias postulaciones de la página (1 consulta extra).
     *  - cualquier otro: no se añade nada.
     *
     * El id del estado se resuelve FUERA del closure: dentro de él, `$this` es
     * el Query Builder, no este controlador.
     */
    private function aplicarCamposDePostulacion(Builder $query, User $user): void
    {
        if ($user->can('convocatorias:gestionar')) {
            $estadoPostulada = $this->parametroId('estado_postulacion', PostulacionEstado::Postulada->value);

            // Solo cuentan los postulantes activos, para que el número coincida con
            // lo que el gestor puede ver en el portafolio.
            $query->withCount([
                'postulaciones as postulantes_count' => fn ($sub) => $sub
                    ->where('estado_id', $estadoPostulada)
                    ->whereHas('user', fn ($u) => $u->where('active', true)),
            ]);

            return;
        }

        if ($user->role === Role::Aspirante) {
            $query->with([
                'postulaciones' => fn ($sub) => $sub->where('user_id', $user->id)->with('estado'),
            ]);
        }
    }
}
