<?php

namespace App\Services\Notificaciones;

use App\Enums\ConvocatoriaEstado;
use App\Enums\PostulacionEstado;
use App\Enums\TipoNotificacion;
use App\Models\Convocatoria;
use App\Models\CorreccionPostulacion;
use App\Models\Evaluacion;
use App\Models\Postulacion;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Qué avisos produce cada evento del flujo y a quién llegan. Es la única tabla de esta regla: si cambia quién debe
 * enterarse de algo, se cambia aquí.
 *
 * | Evento                       | Aspirante | Instructor de la ficha | Evaluador | Gestor de convocatorias | Seleccionador |
 * |------------------------------|-----------|------------------------|-----------|-------------------------|---------------|
 * | Postulación enviada          | sí        | sí                     | sí        | sí                      | no            |
 * | Postulación retirada         | no        | sí                     | sí        | sí                      | no            |
 * | Corrección solicitada        | sí        | sí                     | no        | no                      | no            |
 * | Corrección respondida        | no        | sí                     | solo quien la pidió | no            | no            |
 * | Evaluación finalizada        | sí        | sí                     | no        | no                      | no            |
 * | Pasa a selección             | no        | no                     | no        | no                      | sí (si pasa)  |
 * | Convocatoria publicada       | sí (toda la ficha) | no            | no        | no                      | no            |
 * | Recepción cerrada (en proceso) | sí (todos; a cada uno se le dice si pasa a evaluación) | no | no | no | no |
 * | Convocatoria cerrada         | sí (solo postulados) | no          | no        | no                      | no            |
 * | Convocatoria reabierta (plazo ampliado) | sí (solo con postulación enviada o retirada) | no | no | no | no |
 *
 * Los pendientes (por evaluar, por seleccionar, por cerrar, borradores, correcciones por vencer, cierre próximo) no
 * salen de un evento: ResumenPendientes los calcula cada día y avisa solo si hay algo pendiente.
 *
 * El general y el super admin no reciben avisos operativos: no tienen una tarea que dispare un evento. Quien dispara un
 * evento no se avisa a sí mismo (se excluye por id).
 *
 * Todos los métodos corren dentro de la transacción de quien los llama: si el flujo se deshace, los avisos también.
 */
final class Avisos
{
    public function __construct(
        private Notificador $notificador,
        private Destinatarios $destinatarios,
    ) {}

    /** El aspirante envió su postulación. */
    public function postulacionEnviada(Postulacion $postulacion): void
    {
        $postulacion->loadMissing('user', 'convocatoria:id,nombre,ficha_id');
        $conv = $postulacion->convocatoria->nombre;

        $this->notificador->enviar(
            $postulacion->user,
            TipoNotificacion::PostulacionEnviada,
            'Postulación enviada',
            "Tu postulación a {$conv} quedó enviada. Te avisaremos de los siguientes pasos.",
            '/mis-postulaciones',
        );

        $this->alPersonal(
            $postulacion->convocatoria->ficha_id,
            ['evaluador', 'gestor_convocatorias'],
            TipoNotificacion::PostulacionEnviada,
            'Nueva postulación',
            "{$postulacion->user->name} se postuló a {$conv}.",
            null,
            $postulacion->user_id,
        );
    }

    /** El aspirante retiró su postulación. */
    public function postulacionRetirada(Postulacion $postulacion): void
    {
        $postulacion->loadMissing('user', 'convocatoria:id,nombre,ficha_id');

        $this->alPersonal(
            $postulacion->convocatoria->ficha_id,
            ['evaluador', 'gestor_convocatorias'],
            TipoNotificacion::PostulacionRetirada,
            'Postulación retirada',
            "{$postulacion->user->name} retiró su postulación a {$postulacion->convocatoria->nombre}.",
            null,
            $postulacion->user_id,
        );
    }

    /** El evaluador pidió corregir documentos. Recibe el aspirante y el personal de la ficha, salvo el evaluador. */
    public function correccionSolicitada(Postulacion $postulacion, int $documentos, User $evaluador): void
    {
        $postulacion->loadMissing('user', 'convocatoria:id,nombre,ficha_id');
        $conv = $postulacion->convocatoria->nombre;
        $plural = $documentos === 1 ? 'documento' : 'documentos';

        $this->notificador->enviar(
            $postulacion->user,
            TipoNotificacion::CorreccionSolicitada,
            'Debes corregir documentos',
            "El evaluador revisó tu postulación a {$conv} y te pidió corregir {$documentos} {$plural}. Tienes 48 horas para responder.",
            '/mis-postulaciones',
        );

        $this->alPersonal(
            $postulacion->convocatoria->ficha_id,
            [],
            TipoNotificacion::CorreccionSolicitada,
            'Corrección solicitada',
            "Se pidió a {$postulacion->user->name} corregir {$documentos} {$plural} de su postulación a {$conv}.",
            null,
            $evaluador->id,
        );
    }

    /**
     * El aspirante respondió la corrección. Avisa al instructor de la ficha y al evaluador que la pidió (si es distinto).
     */
    public function correccionRespondida(Postulacion $postulacion, CorreccionPostulacion $ronda): void
    {
        $postulacion->loadMissing('user', 'convocatoria:id,nombre,ficha_id');
        $conv = $postulacion->convocatoria->nombre;

        $destinatarios = $this->destinatarios->personalDeFicha($postulacion->convocatoria->ficha_id, [], true);
        $solicitante = $ronda->solicitante;
        if ($solicitante && $solicitante->active) {
            $destinatarios = $destinatarios->push($solicitante);
        }

        $this->aVarios(
            $destinatarios->unique('id')->reject(fn (User $u) => $u->id === $postulacion->user_id),
            TipoNotificacion::CorreccionRespondida,
            'Corrección respondida',
            "{$postulacion->user->name} respondió la corrección de su postulación a {$conv}.",
            null,
        );
    }

    /**
     * La evaluación quedó finalizada. Avisa al aspirante y al personal de la ficha (salvo quien la finalizó). Si la
     * evaluación pasa el umbral, además avisa a los seleccionadores.
     */
    public function evaluacionFinalizada(Evaluacion $evaluacion, Postulacion $postulacion, User $actor, bool $pasa): void
    {
        $postulacion->loadMissing('user', 'convocatoria:id,nombre,ficha_id');
        $conv = $postulacion->convocatoria->nombre;
        $fichaId = $postulacion->convocatoria->ficha_id;

        $this->notificador->enviar(
            $postulacion->user,
            TipoNotificacion::EvaluacionFinalizada,
            'Tu evaluación fue finalizada',
            "La evaluación de tu postulación a {$conv} ya tiene resultado. Puedes consultarlo en Mis evaluaciones.",
            '/mis-evaluaciones',
        );

        $this->alPersonal(
            $fichaId,
            [],
            TipoNotificacion::EvaluacionFinalizada,
            'Evaluación finalizada',
            "Se finalizó la evaluación de {$postulacion->user->name} en {$conv}.",
            null,
            $actor->id,
        );

        if ($pasa) {
            $this->alPersonal(
                $fichaId,
                ['seleccionador'],
                TipoNotificacion::PasaSeleccion,
                'Aspirante listo para selección',
                "{$postulacion->user->name} pasó la evaluación de {$conv} y está disponible para selección.",
                null,
                $actor->id,
                instructores: false,
            );
        }
    }

    /**
     * La convocatoria cambió de estado. Publicada: avisa a todos los aspirantes de la ficha. En proceso (recepción
     * cerrada): avisa a todos, y dice a cada quien si su postulación pasa a evaluación. Cerrada: avisa solo a quien se
     * postuló. Los demás estados no avisan.
     */
    public function convocatoriaCambio(Convocatoria $convocatoria, ConvocatoriaEstado $destino): void
    {
        $convocatoria->loadMissing('ficha');
        $nombre = $convocatoria->nombre;
        $cierre = $convocatoria->fecha_cierre?->format('d/m/Y');
        $aspirantes = $this->destinatarios->aspirantesDeFicha($convocatoria->ficha_id);

        if ($destino === ConvocatoriaEstado::Publicada) {
            $this->aVarios(
                $aspirantes,
                TipoNotificacion::ConvocatoriaPublicada,
                'Nueva convocatoria abierta',
                "«{$nombre}» ya recibe postulaciones".($cierre ? " hasta el {$cierre}." : '.'),
                '/convocatorias',
            );

            return;
        }

        $postulados = $this->postuladosDe($convocatoria->id);

        if ($destino === ConvocatoriaEstado::EnProceso) {
            foreach ($aspirantes as $aspirante) {
                $postulado = $postulados->contains($aspirante->id);
                $this->notificador->enviar(
                    $aspirante,
                    TipoNotificacion::ConvocatoriaRecepcionCerrada,
                    'Recepción de postulaciones cerrada',
                    $postulado
                        ? "Se cerró la recepción de «{$nombre}». Tu postulación pasa a evaluación."
                        : "Se cerró la recepción de «{$nombre}». Ya no se reciben postulaciones.",
                    $postulado ? '/mis-postulaciones' : '/convocatorias',
                );
            }

            return;
        }

        if ($destino === ConvocatoriaEstado::Cerrada) {
            $this->aVarios(
                $aspirantes->whereIn('id', $postulados),
                TipoNotificacion::ConvocatoriaCerrada,
                'Convocatoria cerrada',
                "«{$nombre}» fue cerrada. Puedes consultar el resultado de tu evaluación en Mis evaluaciones.",
                '/mis-evaluaciones',
            );
        }
    }

    /**
     * Se amplió el plazo y la convocatoria (publicada) volvió a recibir postulaciones. Avisa solo a quien ya tiene una
     * postulación: a quien la envió le confirma que sigue vigente; a quien la retiró le dice que puede reabrirla.
     */
    public function convocatoriaReabierta(Convocatoria $convocatoria): void
    {
        $nombre = $convocatoria->nombre;
        $cierre = $convocatoria->fecha_cierre?->format('d/m/Y');
        $hasta = $cierre ? " hasta el {$cierre}" : '';

        $porEstado = fn (PostulacionEstado $estado) => Postulacion::query()
            ->where('convocatoria_id', $convocatoria->id)
            ->whereHas('estado', fn ($q) => $q->where('codigo', $estado->value))
            ->with('user')
            ->get()
            ->pluck('user')
            ->filter();

        foreach ($porEstado(PostulacionEstado::Postulada) as $usuario) {
            $this->notificador->enviar(
                $usuario,
                TipoNotificacion::ConvocatoriaReabierta,
                'Convocatoria reabierta',
                "«{$nombre}» volvió a recibir postulaciones{$hasta}. Tu postulación sigue enviada y vigente: no tienes que hacer nada.",
                '/mis-postulaciones',
            );
        }

        foreach ($porEstado(PostulacionEstado::Retirada) as $usuario) {
            $this->notificador->enviar(
                $usuario,
                TipoNotificacion::ConvocatoriaReabierta,
                'Convocatoria reabierta',
                "«{$nombre}» volvió a recibir postulaciones{$hasta}. Retiraste tu postulación: si quieres, puedes volver a postularte.",
                '/convocatorias',
            );
        }
    }

    /** Ids de los aspirantes con una postulación enviada (estado postulada) en la convocatoria. */
    private function postuladosDe(int $convocatoriaId): Collection
    {
        return Postulacion::query()
            ->where('convocatoria_id', $convocatoriaId)
            ->whereHas('estado', fn ($q) => $q->where('codigo', PostulacionEstado::Postulada->value))
            ->pluck('user_id');
    }

    /**
     * Aviso al personal de una ficha (instructores y/o subroles), sin el usuario que disparó el evento.
     *
     * @param  list<string>  $subroles
     */
    private function alPersonal(
        int $fichaId,
        array $subroles,
        TipoNotificacion $tipo,
        string $titulo,
        string $mensaje,
        ?string $url,
        int $excluirId,
        bool $instructores = true,
    ): void {
        $this->aVarios(
            $this->destinatarios->personalDeFicha($fichaId, $subroles, $instructores)->reject(fn (User $u) => $u->id === $excluirId),
            $tipo,
            $titulo,
            $mensaje,
            $url,
        );
    }

    /** @param  Collection<int, User>  $usuarios */
    private function aVarios(Collection $usuarios, TipoNotificacion $tipo, string $titulo, string $mensaje, ?string $url): void
    {
        foreach ($usuarios as $usuario) {
            $this->notificador->enviar($usuario, $tipo, $titulo, $mensaje, $url);
        }
    }
}
