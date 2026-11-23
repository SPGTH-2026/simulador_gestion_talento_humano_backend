<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Enums\PostulacionEstado;
use App\Enums\VentanaPostulacion;
use App\Models\CorreccionPostulacion;
use App\Models\Postulacion;
use App\Services\RondasCorreccion;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Reglas compartidas por el paso de documentos y skills de una postulación:
 * aislamiento del dueño, editabilidad según la ventana y resolución de
 * parámetros por tema+código. Todo en español y con el mismo criterio que el
 * resto del módulo de postulaciones.
 */
trait GestionaPostulacionPropia
{
    /** Frontera de aislamiento: una postulación ajena (o inexistente) -> 404. */
    private function exigirPropia(Request $request, Postulacion $postulacion): void
    {
        if ($postulacion->user_id !== $request->user()->id) {
            abort(404, 'Postulación no encontrada.');
        }
    }

    /**
     * Determina si la postulación es editable según las reglas de negocio:
     * - Borrador y Retirada: solo dentro de la ventana de recepción
     *   (apertura..cierre). Una retirada sigue siendo editable por su dueño y
     *   editar NO cambia el estado: sigue retirada hasta que la reactive.
     * - Postulada: convocatoria publicada, después de apertura, y antes de fecha_evaluacion (si existe).
     * - Convocatoria no publicada: no editable (404 en exigirEditable, salvo
     *   retirada, que recibe 422 con el motivo).
     */
    private function puedeEditar(Postulacion $postulacion): bool
    {
        $postulacion->loadMissing(['convocatoria.estado', 'estado']);
        $convocatoria = $postulacion->convocatoria;
        $hoy = now('America/Bogota')->toDateString();

        if ($convocatoria->estado?->codigo !== 'publicada') {
            return false;
        }

        if ($convocatoria->fecha_apertura && $hoy < $convocatoria->fecha_apertura->toDateString()) {
            return false;
        }

        $codigo = $postulacion->estado?->codigo;

        if ($codigo === PostulacionEstado::Borrador->value || $codigo === PostulacionEstado::Retirada->value) {
            return $convocatoria->ventanaPostulacion() === VentanaPostulacion::Abierta;
        }

        if ($codigo !== PostulacionEstado::Postulada->value) {
            return false;
        }

        return $convocatoria->fecha_evaluacion === null
            || $hoy < $convocatoria->fecha_evaluacion->toDateString();
    }

    /**
     * Ronda de corrección vigente: pedida por el evaluador, sin responder y con plazo vigente (guardada o antigua aún sin
     * convertir). Solo aplica a postulaciones aún postuladas; no depende del estado de la convocatoria ni de la fecha de
     * evaluación.
     */
    private function correccionVigente(Postulacion $postulacion): ?CorreccionPostulacion
    {
        $postulacion->loadMissing('estado');

        if ($postulacion->estado?->codigo !== PostulacionEstado::Postulada->value) {
            return null;
        }

        return app(RondasCorreccion::class)->rondaVigente($postulacion);
    }

    /**
     * Durante una corrección vigente solo se reemplazan los documentos marcados
     * por el evaluador: no se suben adicionales ni se borran documentos.
     */
    private function exigirSinCorreccionVigente(Postulacion $postulacion): void
    {
        if ($this->correccionVigente($postulacion) !== null) {
            throw ValidationException::withMessages([
                'documentos' => 'Durante la corrección solo puedes reemplazar los documentos que el evaluador marcó.',
            ]);
        }
    }

    /**
     * Devuelve el motivo legible de por qué no es editable (string vacío si sí lo es).
     */
    private function motivoNoEditable(Postulacion $postulacion): string
    {
        $postulacion->loadMissing(['convocatoria.estado', 'estado']);
        $convocatoria = $postulacion->convocatoria;
        $hoy = now('America/Bogota')->toDateString();

        if ($convocatoria->estado?->codigo !== 'publicada') {
            return 'La convocatoria no está publicada.';
        }

        if ($convocatoria->fecha_apertura && $hoy < $convocatoria->fecha_apertura->toDateString()) {
            return 'La convocatoria aún no está abierta. Abre el '
                .$convocatoria->fecha_apertura?->format('d/m/Y').'.';
        }

        $codigo = $postulacion->estado?->codigo;

        if ($codigo === PostulacionEstado::Retirada->value) {
            if ($convocatoria->ventanaPostulacion() !== VentanaPostulacion::Abierta) {
                return 'La convocatoria ya cerró el '
                    .$convocatoria->fecha_cierre?->format('d/m/Y')
                    .'. Tu postulación retirada queda solo para consulta.';
            }

            return '';
        }

        if ($codigo === PostulacionEstado::Borrador->value) {
            if ($convocatoria->ventanaPostulacion() !== VentanaPostulacion::Abierta) {
                return 'La convocatoria ya cerró el '
                    .$convocatoria->fecha_cierre?->format('d/m/Y')
                    .'. Los borradores no se editan fuera de la ventana.';
            }
            return '';
        }

        if ($codigo !== PostulacionEstado::Postulada->value) {
            return 'Esta postulación no está en estado editable.';
        }

        if ($convocatoria->fecha_evaluacion && $hoy >= $convocatoria->fecha_evaluacion->toDateString()) {
            return 'La evaluación ya comenzó el '
                .$convocatoria->fecha_evaluacion->format('d/m/Y')
                .'. Los documentos quedaron solo para consulta.';
        }

        return '';
    }

    /**
     * Exige que la postulación sea editable; lanza ValidationException con el motivo
     * o abort(404) si la convocatoria no está publicada.
     */
    private function exigirEditable(Postulacion $postulacion, string $clave): void
    {
        // Corrección vigente: el aspirante puede reemplazar sus documentos aunque
        // la convocatoria ya esté en proceso o la evaluación haya empezado.
        if ($clave === 'documentos' && $this->correccionVigente($postulacion) !== null) {
            return;
        }

        $motivo = $this->motivoNoEditable($postulacion);

        if ($motivo === '') {
            return;
        }

        $postulacion->loadMissing(['convocatoria.estado', 'estado']);

        // La postulación ajena ya se filtró con 404 en exigirPropia(). Aquí el
        // dueño de una retirada siempre recibe el motivo (422), aunque la
        // convocatoria haya pasado a proceso o cerrada: es SU postulación y
        // necesita saber por qué no puede editar. Las demás conservan el 404
        // histórico cuando la convocatoria no está publicada.
        $esRetirada = $postulacion->estado?->codigo === PostulacionEstado::Retirada->value;

        if (! $esRetirada && $postulacion->convocatoria->estado?->codigo !== 'publicada') {
            abort(404, 'Postulación no encontrada.');
        }

        throw ValidationException::withMessages([
            $clave => $motivo,
        ]);
    }

    /**
     * Alcance por ficha para revisores (solo lectura): fuera de su ficha -> 404
     * (no revela). Un borrador todavía no es un postulante: no entra a revisión.
     */
    private function exigirRevision(Request $request, Postulacion $postulacion): void
    {
        $postulacion->loadMissing(['convocatoria', 'estado']);

        if (! in_array($postulacion->convocatoria->ficha_id, $request->user()->fichaIds(), true)) {
            abort(404, 'Postulación no encontrada.');
        }

        if ($postulacion->estado?->codigo === PostulacionEstado::Borrador->value) {
            abort(404, 'Postulación no encontrada.');
        }
    }
}
