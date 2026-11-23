<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Enums\VentanaPostulacion;
use App\Models\Convocatoria;
use App\Models\Parametro;
use App\Models\Postulacion;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

trait PresentaPostulaciones
{
    /** Id de un parámetro por tema+código, o 500 si falta el seeder. */
    private function parametroId(string $tema, string $codigo): int
    {
        $parametro = Parametro::porTemaYCodigo($tema, $codigo);

        abort_unless($parametro, 500, "Parámetro no encontrado: {$tema}.{$codigo}. ¿Se ejecutó ParametroSeeder?");

        return (int) $parametro->id;
    }

    /**
     * Violación del índice UNIQUE(convocatoria_id, user_id). Laravel la clasifica
     * igual en MySQL (1062) y en Postgres (23505). Cualquier otro error se relanza.
     */
    private function esDuplicado(QueryException $e): bool
    {
        return $e instanceof UniqueConstraintViolationException;
    }

    private function yaPostulado(): ValidationException
    {
        return ValidationException::withMessages([
            'postulacion' => 'Ya estás postulado en esta convocatoria.',
        ]);
    }

    /**
     * Exige que la convocatoria acepte postulaciones HOY, con las MISMAS reglas
     * y mensajes que el POST: no publicada -> 404 (no se revela su existencia);
     * antes de apertura o cerrada -> 422. La clave decide bajo que campo del
     * cuerpo viaja el error ('postulacion' en el POST, 'estado' en el PATCH).
     */
    private function exigirVentanaAbierta(Convocatoria $convocatoria, string $clave = 'postulacion'): void
    {
        switch ($convocatoria->ventanaPostulacion()) {
            case VentanaPostulacion::NoPublicada:
                abort(404, 'Convocatoria no encontrada.');

            case VentanaPostulacion::AntesDeApertura:
                throw ValidationException::withMessages([
                    $clave => 'La convocatoria aún no está abierta. Abre el '
                        .$convocatoria->fecha_apertura?->format('d/m/Y').'.',
                ]);

            case VentanaPostulacion::Cerrada:
                throw ValidationException::withMessages([
                    $clave => 'La convocatoria ya cerró el '
                        .$convocatoria->fecha_cierre?->format('d/m/Y').'.',
                ]);
        }
    }

    private function presentar(Postulacion $postulacion, User $user): array
    {
        return [
            'id' => $postulacion->id,
            'estado' => $this->parametro($postulacion->estado),
            'fecha_postulacion' => $postulacion->fecha_postulacion?->toIso8601String(),
            'fecha_retiro' => $postulacion->fecha_retiro?->toIso8601String(),
            'convocatoria' => $this->resumenConvocatoria($postulacion->convocatoria, $user),
        ];
    }

    /**
     * Resumen embebido: permite mostrar la tarjeta sin pedir el detalle.
     *
     * El `codigo` (CONV-AAAA-NNN) es un dato de gestión: solo lo recibe quien
     * está en la lista blanca (instructor, super admin y el subrol
     * gestor_convocatorias). Para el resto llega en null.
     */
    private function resumenConvocatoria(Convocatoria $convocatoria, User $user): array
    {
        return [
            'id' => $convocatoria->id,
            'codigo' => $this->veIdentificadorConvocatoria($user) ? $convocatoria->codigo : null,
            'nombre' => $convocatoria->nombre,
            'cargo' => $convocatoria->cargo,
            'area' => $convocatoria->area,
            'estado' => $this->parametro($convocatoria->estado),
            'fecha_apertura' => $convocatoria->fecha_apertura?->toDateString(),
            'fecha_cierre' => $convocatoria->fecha_cierre?->toDateString(),
        ];
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
            'integer' => 'El campo :attribute debe ser un número entero.',
            'min' => 'El campo :attribute debe ser al menos :min.',
            'max' => 'El campo :attribute no debe superar :max.',
        ];
    }

    /** @return array<string, string> */
    private function atributos(): array
    {
        return [
            'page' => 'página',
            'per_page' => 'elementos por página',
            'estado' => 'estado',
        ];
    }
}
