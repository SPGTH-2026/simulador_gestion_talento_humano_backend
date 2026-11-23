<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Enums\ConvocatoriaEstado;
use App\Models\Convocatoria;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

trait ReglasDeConvocatoria
{
    /**
     * Requisitos para publicar (borrador -> publicada): nombre, cargo, area,
     * descripcion, fecha_apertura y fecha_cierre no vacios; fecha_cierre no
     * anterior a hoy (America/Bogota) ni anterior a fecha_apertura; y
     * salario_max >= salario_min. Acumula un error POR CAMPO.
     */
    private function validarPublicacion(Convocatoria $convocatoria): void
    {
        $errores = [];

        foreach ([
            'nombre' => 'El nombre es obligatorio para publicar.',
            'cargo' => 'El cargo es obligatorio para publicar.',
            'area' => 'El área es obligatoria para publicar.',
            'descripcion' => 'La descripción es obligatoria para publicar.',
        ] as $campo => $mensaje) {
            if (! $this->noVacio($convocatoria->{$campo})) {
                $errores[$campo][] = $mensaje;
            }
        }

        foreach ([
            'fecha_apertura' => 'La fecha de apertura es obligatoria para publicar.',
            'fecha_cierre' => 'La fecha de cierre es obligatoria para publicar.',
        ] as $campo => $mensaje) {
            if ($convocatoria->{$campo} === null) {
                $errores[$campo][] = $mensaje;
            }
        }

        // Sin cambio automatico por fecha, pero al publicar la fecha de cierre no
        // puede estar en el pasado, comparando en la zona de Colombia.
        if ($convocatoria->fecha_cierre !== null
            && $convocatoria->fecha_cierre->toDateString() < now('America/Bogota')->toDateString()) {
            $errores['fecha_cierre'][] = 'La fecha de cierre no puede estar en el pasado.';
        }

        // Coherencia entre fechas: fecha_cierre >= fecha_apertura (misma regla
        // que en alta/edición).
        if ($convocatoria->fecha_apertura !== null && $convocatoria->fecha_cierre !== null
            && $convocatoria->fecha_cierre->toDateString() < $convocatoria->fecha_apertura->toDateString()) {
            $errores['fecha_cierre'][] = 'La fecha de cierre debe ser posterior o igual a la fecha de apertura.';
        }

        // Coherencia de salarios: salario_max >= salario_min (misma regla que en
        // alta/edición).
        if ($convocatoria->salario_min !== null && $convocatoria->salario_max !== null
            && (float) $convocatoria->salario_max < (float) $convocatoria->salario_min) {
            $errores['salario_max'][] = 'El salario máximo debe ser mayor o igual que el salario mínimo.';
        }

        if ($errores !== []) {
            throw ValidationException::withMessages($errores);
        }
    }

    /**
     * Con CUALQUIER postulación (postulada o retirada) y la convocatoria
     * publicada, estos campos quedan bloqueados: cargo, area, vacantes,
     * salario_min, salario_max, tipo_convocatoria, tipo_empleo, tipo_contrato,
     * modalidad, fecha_apertura y skills. Los documentos se bloquean aparte, con
     * cualquier postulación (bloquearDocumentosConPostulaciones). Siempre editables: nombre,
     * texts descriptivos y fecha_evaluacion. fecha_cierre: solo ampliar.
     *
     * Solo se bloquea si el valor enviado DIFIERE del guardado (el wizard
     * reenvía todo el formulario). Acumula un error por campo y lanza un único
     * 422, con todos los campos que cambiaron.
     */
    private function bloquearCamposConPostulaciones(Convocatoria $actual, array $normalizado): void
    {
        $campos = $normalizado['campos'];
        $errores = [];
        $bloqueado = 'No se puede modificar %s porque la convocatoria ya tiene postulaciones.';

        // Texto exacto (recortando espacios).
        foreach (['cargo', 'area'] as $campo) {
            if (array_key_exists($campo, $campos)
                && trim((string) $campos[$campo]) !== trim((string) $actual->{$campo})) {
                $errores[$campo][] = sprintf($bloqueado, $campo);
            }
        }

        // Vacantes.
        if (array_key_exists('vacantes', $campos)
            && $this->enteroDistinto($campos['vacantes'], $actual->vacantes)) {
            $errores['vacantes'][] = sprintf($bloqueado, 'vacantes');
        }

        // Salarios (decimal vs string).
        foreach (['salario_min', 'salario_max'] as $campo) {
            if (array_key_exists($campo, $campos)
                && $this->decimalDistinto($campos[$campo], $actual->{$campo})) {
                $errores[$campo][] = sprintf($bloqueado, $campo);
            }
        }

        // Clasificaciones: se compara el id ya resuelto (aceptan código o id) y el
        // error se reporta bajo la clave canónica, aunque el cliente use *_id.
        foreach (self::CLASIFICACIONES as $campo => $tema) {
            $clave = $campo.'_id';
            if (array_key_exists($clave, $campos)
                && (int) $campos[$clave] !== (int) $actual->{$clave}) {
                $errores[$campo][] = sprintf($bloqueado, $campo);
            }
        }

        // Fecha de apertura (solo día).
        if (array_key_exists('fecha_apertura', $campos)
            && $this->fechaDia($campos['fecha_apertura']) !== $actual->fecha_apertura?->toDateString()) {
            $errores['fecha_apertura'][] = sprintf($bloqueado, 'fecha_apertura');
        }

        // Fecha de cierre: solo se bloquea si la REDUCEN (igualar o ampliar se acepta).
        if (array_key_exists('fecha_cierre', $campos)) {
            $nueva = $this->fechaDia($campos['fecha_cierre']);
            $actualCierre = $actual->fecha_cierre?->toDateString();
            if ($nueva !== null && $actualCierre !== null && $nueva < $actualCierre) {
                $errores['fecha_cierre'][] = 'La fecha de cierre solo se puede ampliar.';
            }
        }

        // Skills: conjunto sin distinguir orden ni mayúsculas.
        if ($normalizado['skills'] !== null
            && $this->conjuntoSkills($normalizado['skills']) !== $this->conjuntoSkills($actual->skills()->pluck('nombre')->all())) {
            $errores['skills'][] = sprintf($bloqueado, 'skills');
        }

        if ($errores !== []) {
            throw ValidationException::withMessages($errores);
        }
    }

    /** Entero nullable distinto (null vs null = igual). */
    private function enteroDistinto(mixed $nuevo, mixed $actual): bool
    {
        $a = $nuevo === null || $nuevo === '' ? null : (int) $nuevo;
        $b = $actual === null ? null : (int) $actual;

        return $a !== $b;
    }

    /** Decimal nullable distinto (acepta "3500000" o 3500000.0). */
    private function decimalDistinto(mixed $nuevo, mixed $actual): bool
    {
        $a = $nuevo === null || $nuevo === '' ? null : (float) $nuevo;
        $b = $actual === null ? null : (float) $actual;

        return $a !== $b;
    }

    /**
     * Fecha reducida a día. Un valor solo-fecha (Y-m-d) es ese día calendario;
     * una fecha-hora se lleva a America/Bogota. Esto evita el corrimiento de un
     * día que produce interpretar "2026-10-11" como medianoche UTC y convertir a
     * Bogotá (UTC-5), que daría 2026-10-10.
     */
    private function fechaDia(mixed $valor): ?string
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        $texto = trim((string) $valor);

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $texto) === 1) {
            return $texto;
        }

        return Carbon::parse($texto, 'America/Bogota')->toDateString();
    }

    /**
     * Cerrada para quien no gestiona: estado 'cerrada', o 'publicada' con la
     * fecha de cierre pasada (mismo día que Convocatoria::ventanaPostulacion,
     * en America/Bogota). Sin fecha de cierre no se considera cerrada.
     */
    private function estaCerrada(Convocatoria $convocatoria): bool
    {
        $codigo = $convocatoria->estado?->codigo;

        if ($codigo === ConvocatoriaEstado::Cerrada->value) {
            return true;
        }

        return $codigo === ConvocatoriaEstado::Publicada->value
            && $convocatoria->fecha_cierre !== null
            && $convocatoria->fecha_cierre->toDateString() < now('America/Bogota')->toDateString();
    }

    /** Frontera de aislamiento: fuera del alcance (o estado no visible) -> 404. */
    private function autorizar(Convocatoria $convocatoria, Request $request): void
    {
        $user = $request->user();

        if (! in_array($convocatoria->ficha_id, $user->fichaIds(), true)) {
            abort(404, 'Convocatoria no encontrada.');
        }

        if (! $user->can('convocatorias:gestionar')
            && $convocatoria->estado?->codigo !== ConvocatoriaEstado::Publicada->value) {
            abort(404, 'Convocatoria no encontrada.');
        }

        if (! $this->esGestorDeConvocatorias($user) && $this->estaCerrada($convocatoria)) {
            abort(404, 'Convocatoria no encontrada.');
        }
    }
}
