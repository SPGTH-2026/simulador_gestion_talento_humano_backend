<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Reglas de la convocatoria compartidas por el alta (StoreConvocatoriaRequest) y la edición (ConvocatoriaController::update).
 *
 * La autorización por alcance NO vive aquí: el controlador la resuelve antes que la validación y responde 404 fuera de
 * alcance. Si la validación corriera antes, un usuario sin alcance vería 422 en vez de 404 y sabría que la convocatoria existe.
 */
abstract class ConvocatoriaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public static function reglas(bool $esAlta): array
    {
        $maxSkills = (int) config('convocatorias.skills_max');

        return [
            'nombre' => [$esAlta ? 'required' : 'sometimes', 'filled', 'string', 'max:150'],
            'cargo' => ['nullable', 'string', 'max:120'],
            'area' => ['nullable', 'string', 'max:120'],
            'descripcion' => ['nullable', 'string'],
            'vacantes' => ['nullable', 'integer', 'min:1'],
            'salario_min' => ['nullable', 'numeric', 'min:0', 'max:999999999999.99'],
            'salario_max' => ['nullable', 'numeric', 'min:0', 'max:999999999999.99'],
            'funciones' => ['nullable', 'string'],
            'responsabilidades' => ['nullable', 'string'],
            'perfil_requerido' => ['nullable', 'string'],
            'formacion' => ['nullable', 'string'],
            'experiencia' => ['nullable', 'string'],
            'competencias' => ['nullable', 'string'],
            'conocimientos_especificos' => ['nullable', 'string'],
            'fecha_apertura' => ['nullable', 'date'],
            'fecha_cierre' => ['nullable', 'date'],
            'fecha_evaluacion' => ['nullable', 'date'],
            // Las skills se validan una por una en normalizarSkills: aquí solo se acota la cantidad.
            'skills' => ['nullable', 'array', 'max:'.$maxSkills],
            'documentos' => ['nullable', 'array'],
            'tipo_convocatoria' => ['nullable', 'string'],
            'tipo_convocatoria_id' => ['nullable', 'integer'],
            'tipo_empleo' => ['nullable', 'string'],
            'tipo_empleo_id' => ['nullable', 'integer'],
            'tipo_contrato' => ['nullable', 'string'],
            'tipo_contrato_id' => ['nullable', 'integer'],
            'modalidad' => ['nullable', 'string'],
            'modalidad_id' => ['nullable', 'integer'],
        ];
    }

    /** @return array<string, string> */
    public static function mensajes(): array
    {
        return [
            'required' => 'El campo :attribute es obligatorio.',
            'filled' => 'El campo :attribute no puede quedar vacío.',
            'exists' => 'El campo :attribute seleccionado no es válido.',
            'string' => 'El campo :attribute debe ser texto.',
            'integer' => 'El campo :attribute debe ser un número entero.',
            'numeric' => 'El campo :attribute debe ser un número.',
            'array' => 'El campo :attribute debe ser una lista.',
            'date' => 'El campo :attribute no es una fecha válida.',
            'min' => 'El campo :attribute debe ser al menos :min.',
            'max' => 'El campo :attribute no debe superar :max.',
            'gte' => 'El campo :attribute debe ser mayor o igual que :other.',
            'skills.max' => 'No puedes registrar más de :max skills.',
            'skills.*.string' => 'Cada skill debe ser texto.',
            'skills.*.max' => 'Cada skill no debe superar los :max caracteres.',
        ];
    }

    /** @return array<string, string> */
    public static function atributos(): array
    {
        return [
            'nombre' => 'nombre',
            'cargo' => 'cargo',
            'area' => 'área',
            'descripcion' => 'descripción',
            'vacantes' => 'número de vacantes',
            'salario_min' => 'salario mínimo',
            'salario_max' => 'salario máximo',
            'fecha_apertura' => 'fecha de apertura',
            'fecha_cierre' => 'fecha de cierre',
            'fecha_evaluacion' => 'fecha de evaluación',
            'tipo_convocatoria' => 'tipo de convocatoria',
            'tipo_convocatoria_id' => 'tipo de convocatoria',
            'tipo_empleo' => 'tipo de empleo',
            'tipo_empleo_id' => 'tipo de empleo',
            'tipo_contrato' => 'tipo de contrato',
            'tipo_contrato_id' => 'tipo de contrato',
            'modalidad' => 'modalidad',
            'modalidad_id' => 'modalidad',
            'page' => 'página',
            'per_page' => 'elementos por página',
            'estado' => 'estado',
            'ficha_id' => 'ficha',
            'q' => 'búsqueda',
        ];
    }

    public function messages(): array
    {
        return static::mensajes();
    }

    public function attributes(): array
    {
        return static::atributos();
    }
}
