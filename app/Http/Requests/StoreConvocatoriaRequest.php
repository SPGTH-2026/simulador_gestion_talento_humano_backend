<?php

namespace App\Http\Requests;

use Illuminate\Validation\ValidationException;

/**
 * Alta de convocatoria. Los campos que asigna el sistema (id, codigo, estado...) se rechazan ANTES de validar, igual que
 * antes de extraer esta clase: así la respuesta a un envío con campos reservados no cambia.
 */
class StoreConvocatoriaRequest extends ConvocatoriaRequest
{
    /** Campos que asigna el sistema; enviarlos es un error. */
    public const RESERVADOS = ['id', 'codigo', 'estado', 'estado_id', 'creado_por'];

    public function rules(): array
    {
        return static::reglas(true);
    }

    protected function prepareForValidation(): void
    {
        $presentes = array_values(array_filter(
            self::RESERVADOS,
            fn (string $campo) => $this->has($campo),
        ));

        if ($presentes !== []) {
            throw ValidationException::withMessages([
                'campos' => 'Estos campos los asigna el sistema y no se pueden enviar: '.implode(', ', $presentes).'.',
            ]);
        }
    }
}
