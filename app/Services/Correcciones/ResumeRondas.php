<?php

namespace App\Services\Correcciones;

use App\Models\CorreccionPostulacion;
use App\Models\Postulacion;
use App\Models\User;

/**
 * Resumen de las rondas para la API: ronda actual, historial, límites, motivo y si quien consulta puede autorizar.
 */
trait ResumeRondas
{
    /** Respuesta de la API para una postulación. `$actor` decide si puede autorizar una reapertura. */
    public function resumen(Postulacion $postulacion, ?User $actor = null): array
    {
        $rondas = $this->rondas($postulacion);
        $autorizacion = $this->autorizacionLibre($postulacion);
        $decision = $this->decision($postulacion, $rondas, $autorizacion);

        $actual = $rondas->last();
        $presentadas = $rondas->map(fn (CorreccionPostulacion $r) => $this->presentar($postulacion, $r))->values();

        // La excepción procede tras un vencimiento sin respuesta o al agotarse las dos ordinarias, sin ronda pendiente y
        // sin otra autorización sin usar. Solo la dan el Instructor (en sus fichas) y el Superadmin.
        $puedeAutorizar = $actor !== null
            && in_array($actor->role, [\App\Enums\Role::Instructor, \App\Enums\Role::SuperAdmin], true)
            && in_array((int) $postulacion->convocatoria?->ficha_id, $actor->fichaIds(), true)
            && $autorizacion === null
            && in_array($decision['motivo'], ['vencida', 'sin_plazo', 'agotadas'], true);

        // Quien puede autorizar (Instructor, Superadmin) lee el motivo dirigido a él, no el que habla de «pedir» la autorización.
        $mensaje = $decision['mensaje'];
        if ($puedeAutorizar) {
            $mensaje = match ($decision['motivo']) {
                'vencida' => 'El plazo de una ronda venció sin respuesta. Puedes autorizar una ronda excepcional, con justificación, para que el evaluador vuelva a pedir la corrección; o el evaluador puede finalizar con la evidencia disponible.',
                'sin_plazo' => 'Una solicitud anterior quedó sin plazo registrado. Puedes autorizar una ronda excepcional, con justificación, para que el evaluador vuelva a pedir la corrección; o el evaluador puede finalizar con la evidencia disponible.',
                'agotadas' => 'Se agotaron las dos rondas ordinarias. Puedes autorizar una ronda excepcional, con justificación, para que el evaluador vuelva a pedir la corrección; o el evaluador puede finalizar con la evidencia disponible.',
                default => $mensaje,
            };
        }

        return [
            'estado' => $actual?->estado(),
            'ronda_actual' => $actual ? $presentadas->last() : null,
            'historial' => $presentadas,
            'ordinarias_usadas' => $decision['ordinarias_usadas'],
            'ordinarias_max' => $decision['ordinarias_max'],
            'ordinarias_restantes' => $decision['ordinarias_restantes'],
            'puede_solicitar' => $decision['puede_solicitar'],
            'tipo_siguiente' => $decision['tipo'],
            'motivo' => $decision['motivo'],
            'mensaje' => $mensaje,
            'puede_autorizar' => $puedeAutorizar,
            'autorizacion' => $autorizacion ? [
                'id' => $autorizacion->id,
                'justificacion' => $autorizacion->justificacion,
                'autorizada_por' => $autorizacion->autorizador?->only(['id', 'name']),
                'autorizada_at' => $autorizacion->created_at?->toIso8601String(),
            ] : null,
        ];
    }

    public function presentar(Postulacion $postulacion, CorreccionPostulacion $r): array
    {
        $marcados = array_map('intval', $r->documentos_marcados ?? []);

        return [
            'id' => $r->id,
            'tipo' => $r->tipo,
            'numero' => $r->numero,
            'origen' => $r->origen,
            'estado' => $r->estado(),
            'solicitada_at' => $r->solicitada_at?->toIso8601String(),
            'fecha_limite' => $r->fecha_limite?->toIso8601String(),
            'respondida_at' => $r->respondida_at?->toIso8601String(),
            'solicitada_por' => $r->solicitante?->only(['id', 'name']) ?? ($r->solicitada_por ? User::find($r->solicitada_por)?->only(['id', 'name']) : null),
            'observaciones' => $r->observaciones,
            'documentos_marcados' => $marcados,
            'documentos_reemplazados' => $this->reemplazados($postulacion, $r, $marcados),
        ];
    }
}
