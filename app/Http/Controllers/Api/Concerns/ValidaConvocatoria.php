<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Http\Requests\ConvocatoriaRequest;
use App\Http\Requests\StoreConvocatoriaRequest;
use App\Models\Convocatoria;
use App\Models\Parametro;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

trait ValidaConvocatoria
{
    /**
     * Valida y normaliza el cuerpo. Devuelve:
     *  - campos:     valores listos para forceFill (solo los enviados).
     *  - skills:     array en alta; null en edición si no se envió.
     *  - documentos: array|null (null = no tocar en edición / solo sistema en alta).
     */
    private function validar(Request $request, ?Convocatoria $actual): array
    {
        $esAlta = $actual === null;

        $reglas = ConvocatoriaRequest::reglas($esAlta);

        // Con StoreConvocatoriaRequest la validación ya la hizo el FormRequest; con la edición, aquí.
        $datos = $request instanceof ConvocatoriaRequest
            ? $request->validated()
            : $request->validate($reglas, $this->mensajes(), $this->atributos());

        $errores = [];

        // Salarios: salario_max >= salario_min (con el valor actual en edición).
        $min = array_key_exists('salario_min', $datos) ? $datos['salario_min'] : $actual?->salario_min;
        $max = array_key_exists('salario_max', $datos) ? $datos['salario_max'] : $actual?->salario_max;
        if ($min !== null && $max !== null && (float) $max < (float) $min) {
            $errores['salario_max'][] = 'El salario máximo debe ser mayor o igual que el salario mínimo.';
        }

        // Fechas: fecha_cierre >= fecha_apertura (con el valor actual en edición).
        $apertura = array_key_exists('fecha_apertura', $datos)
            ? $datos['fecha_apertura']
            : $actual?->fecha_apertura?->toDateString();
        $cierre = array_key_exists('fecha_cierre', $datos)
            ? $datos['fecha_cierre']
            : $actual?->fecha_cierre?->toDateString();
        if ($apertura !== null && $cierre !== null && strtotime((string) $cierre) < strtotime((string) $apertura)) {
            $errores['fecha_cierre'][] = 'La fecha de cierre debe ser posterior o igual a la fecha de apertura.';
        }

        // La evaluación empieza después de cerrar la recepción: si fuera antes, las postulaciones enviadas quedarían
        // bloqueadas mientras la ventana de recepción sigue abierta.
        $evaluacion = array_key_exists('fecha_evaluacion', $datos)
            ? $datos['fecha_evaluacion']
            : $actual?->fecha_evaluacion?->toDateString();
        if ($cierre !== null && $evaluacion !== null && strtotime((string) $evaluacion) < strtotime((string) $cierre)) {
            $errores['fecha_evaluacion'][] = 'La fecha de evaluación debe ser posterior o igual a la fecha de cierre.';
        }

        // Clasificación por código o id.
        $clasificacion = [];
        foreach (self::CLASIFICACIONES as $campo => $tema) {
            $id = $this->resolverClasificacion($datos, $campo, $tema, $esAlta, $errores);
            if ($id !== null) {
                $clasificacion[$campo.'_id'] = $id;
            }
        }

        // Documentos: el gestor elige opcionales; los obligatorios se agregan
        // aparte con conDocumentosObligatorios (store y edición sin postulaciones).
        if ($esAlta) {
            $documentos = $this->normalizarDocumentos($datos['documentos'] ?? null, $errores);
        } else {
            $documentos = array_key_exists('documentos', $datos)
                ? $this->normalizarDocumentos($datos['documentos'] ?? [], $errores)
                : null;
        }

        // Porcentajes: se validan sobre la lista FINAL (con los obligatorios que
        // agrega el backend) para que la suma sea siempre 100 %.
        if ($documentos !== null) {
            $this->validarPonderaciones($this->conDocumentosObligatorios($documentos), $errores);
        }

        // Skills: se normalizan ANTES de lanzar los errores, para que un
        // nombre repetido, demasiado largo o un parametro_id inválido
        // devuelvan su 422 y no se guarden de más.
        if ($esAlta) {
            $skills = $this->normalizarSkills($datos['skills'] ?? [], $errores);
        } else {
            $skills = array_key_exists('skills', $datos)
                ? $this->normalizarSkills($datos['skills'] ?? [], $errores)
                : null;
        }

        if ($errores !== []) {
            throw ValidationException::withMessages($errores);
        }

        $campos = [];
        foreach ([
            'nombre', 'cargo', 'area', 'descripcion', 'vacantes', 'salario_min', 'salario_max',
            'funciones', 'responsabilidades', 'perfil_requerido', 'formacion', 'experiencia',
            'competencias', 'conocimientos_especificos', 'fecha_apertura', 'fecha_cierre', 'fecha_evaluacion',
        ] as $campo) {
            if (array_key_exists($campo, $datos)) {
                $campos[$campo] = $datos[$campo];
            }
        }

        $campos += $clasificacion;

        return [
            'campos' => $campos,
            'skills' => $skills,
            'documentos' => $documentos,
        ];
    }

    /** Un valor de texto esta "no vacio" si al recortar espacios queda algo. */
    private function noVacio(mixed $valor): bool
    {
        return is_string($valor) ? trim($valor) !== '' : $valor !== null;
    }

    /**
     * Resuelve una clasificación por código (`$campo`) o por id (`$campo_id`).
     * En alta, si no viene ninguno, usa el es_defecto del tema.
     */
    private function resolverClasificacion(array $datos, string $campo, string $tema, bool $esAlta, array &$errores): ?int
    {
        $tieneCodigo = array_key_exists($campo, $datos) && $datos[$campo] !== null && $datos[$campo] !== '';
        $tieneId = array_key_exists($campo.'_id', $datos) && $datos[$campo.'_id'] !== null;

        if ($tieneCodigo && $tieneId) {
            $errores[$campo][] = 'Envía el código o el id, no ambos.';
            return null;
        }

        if ($tieneCodigo) {
            $parametro = Parametro::porTemaYCodigo($tema, (string) $datos[$campo]);
            if (! $parametro || ! $parametro->activo) {
                $errores[$campo][] = 'El valor seleccionado no es válido.';
                return null;
            }

            return (int) $parametro->id;
        }

        if ($tieneId) {
            $parametro = Parametro::query()
                ->whereKey($datos[$campo.'_id'])
                ->whereHas('tema', fn ($q) => $q->where('codigo', $tema))
                ->where('activo', true)
                ->first();

            if (! $parametro) {
                $errores[$campo.'_id'][] = 'El valor seleccionado no es válido.';
                return null;
            }

            return (int) $parametro->id;
        }

        if (! $esAlta) {
            return null;
        }

        $porDefecto = Parametro::query()
            ->whereHas('tema', fn ($q) => $q->where('codigo', $tema))
            ->where('es_defecto', true)
            ->where('activo', true)
            ->first();

        abort_unless($porDefecto, 500, "No hay valor por defecto para el tema {$tema}. ¿Se ejecutó ParametroSeeder?");

        return (int) $porDefecto->id;
    }
}
