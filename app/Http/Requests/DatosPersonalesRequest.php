<?php

namespace App\Http\Requests;

use App\Models\PostulacionDatosPersonales;
use Carbon\Carbon;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Datos personales de una postulación. Se guardan a medida que se completan (no exigen la autorización de tratamiento, que se registra aparte y es lo que habilita el envío). La pertenencia de la postulación y si es editable las resuelve el controlador
 * antes que esta validación (una postulación ajena responde 404, no 422).
 *
 * Reglas de edad: con menos de 16 años no se puede continuar; con 16 o 17 el representante legal es obligatorio.
 */
class DatosPersonalesRequest extends FormRequest
{
    private const DOCUMENTO = '/^[A-Za-z0-9]{5,20}$/';

    private const TELEFONO = '/^\+?[0-9 ]{7,15}$/';

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'sexo' => ['required', 'string'],
            'tipo_documento_identidad' => ['required', 'string'],
            'numero_documento' => ['required', 'string', 'regex:'.self::DOCUMENTO],
            'nombres' => ['required', 'string', 'min:2', 'max:100'],
            'apellidos' => ['required', 'string', 'min:2', 'max:100'],
            'fecha_nacimiento' => ['required', 'date_format:Y-m-d', 'before:today', 'after:1900-01-01'],
            'telefono' => ['required', 'string', 'regex:'.self::TELEFONO],
            // Códigos oficiales del DANE (DIVIPOLA): 2 dígitos el departamento y 5 el municipio, que debe ser de ese departamento.
            'departamento' => ['required', 'string', 'exists:departamentos,codigo'],
            'municipio' => [
                'required', 'string',
                Rule::exists('municipios', 'codigo')->where('departamento_codigo', (string) $this->input('departamento')),
            ],
            'direccion' => ['nullable', 'string', 'max:150'],
            'nivel_formacion' => ['required', 'string'],
            'representante_nombre' => ['nullable', 'string', 'min:3', 'max:150'],
            'representante_documento' => ['nullable', 'string', 'regex:'.self::DOCUMENTO],
            'representante_telefono' => ['nullable', 'string', 'regex:'.self::TELEFONO],
            'representante_parentesco' => ['nullable', 'string', 'min:3', 'max:40'],
            // La autorización se registra aparte de los datos: puede llegar marcada o no. Una vez dada no se revoca por aquí.
            'acepta_tratamiento_datos' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Edad y representante: se validan solo si la fecha ya pasó las reglas de formato, para no mezclar errores.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->has('fecha_nacimiento') || ! $this->filled('fecha_nacimiento')) {
                return;
            }

            $edad = Carbon::createFromFormat('Y-m-d', $this->input('fecha_nacimiento'))->age;

            if ($edad < PostulacionDatosPersonales::EDAD_MINIMA) {
                $validator->errors()->add(
                    'fecha_nacimiento',
                    'Eres menor de la edad permitida para postularte ('.PostulacionDatosPersonales::EDAD_MINIMA.' años).',
                );

                return;
            }

            if ($edad >= PostulacionDatosPersonales::MAYORIA_DE_EDAD) {
                return;
            }

            foreach (['representante_nombre', 'representante_documento', 'representante_telefono', 'representante_parentesco'] as $campo) {
                if (! $this->filled($campo) && ! $validator->errors()->has($campo)) {
                    $validator->errors()->add($campo, 'Como eres menor de edad, este dato del representante legal es obligatorio.');
                }
            }
        }];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'required' => 'El campo :attribute es obligatorio.',
            'string' => 'El campo :attribute debe ser texto.',
            'min' => 'El campo :attribute es demasiado corto.',
            'max' => 'El campo :attribute es demasiado largo.',
            'regex' => 'El formato de :attribute no es válido.',
            'date_format' => 'La fecha de nacimiento debe tener formato AAAA-MM-DD.',
            'before' => 'La fecha de nacimiento debe ser anterior a hoy.',
            'after' => 'La fecha de nacimiento no es válida.',
            'departamento.exists' => 'Selecciona un departamento de la lista.',
            'municipio.exists' => 'Selecciona un municipio de la lista que pertenezca al departamento elegido.',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'sexo' => 'sexo',
            'tipo_documento_identidad' => 'tipo de documento',
            'numero_documento' => 'número de documento',
            'fecha_nacimiento' => 'fecha de nacimiento',
            'telefono' => 'teléfono',
            'nivel_formacion' => 'nivel de formación',
            'representante_nombre' => 'nombre del representante',
            'representante_documento' => 'documento del representante',
            'representante_telefono' => 'teléfono del representante',
            'representante_parentesco' => 'parentesco',
            'acepta_tratamiento_datos' => 'autorización de datos',
        ];
    }
}
