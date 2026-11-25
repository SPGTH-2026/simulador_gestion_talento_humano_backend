<?php

namespace App\Services;

use App\Models\CorreccionAutorizacion;
use App\Models\CorreccionPostulacion;
use App\Models\Evaluacion;
use App\Models\Postulacion;
use App\Models\User;
use App\Services\Notificaciones\Avisos;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use App\Services\Correcciones\DecideRondas;
use App\Services\Correcciones\LeeRondas;
use App\Services\Correcciones\ResumeRondas;

/**
 * Política de rondas de corrección de una postulación.
 *
 *  - Máximo dos rondas ORDINARIAS por postulación, compartidas entre evaluadores.
 *  - Una sola solicitud pendiente y vigente a la vez.
 *  - Cada ronda tiene 48 horas desde su creación (fechas en UTC).
 *  - La 2.ª ordinaria solo se pide después de que el aspirante respondió la 1.ª. Si una ronda vence sin respuesta, se
 *    bloquean las nuevas ordinarias (aunque quede una sin usar).
 *  - Para volver a pedir corrección hace falta una AUTORIZACIÓN EXCEPCIONAL del Instructor (en sus fichas) o del
 *    Superadmin, con justificación; da derecho a una sola ronda excepcional, de 48 horas nuevas, que no consume una
 *    ordinaria. Solo puede haber una autorización sin usar por postulación.
 *
 * Todas las operaciones que escriben deben ejecutarse dentro de una transacción: bloquean la fila de la postulación,
 * revisan estado y vencimiento sobre datos reales y liberan la marca «abierta» de las rondas vencidas en ese mismo
 * momento (sin tareas programadas ni dependencia de visitas a la pantalla).
 *
 * Las solicitudes ANTIGUAS (evaluaciones en «corrección solicitada» sin ronda asociada) se leen como rondas «de
 * legado» de solo lectura y cuentan con las mismas restricciones, sin escribirse; `correcciones:migrar-legado` las
 * convierte de forma explícita. La evaluación de origen es única, así que nunca se cuentan dos veces.
 */
class RondasCorreccion
{
    use DecideRondas;
    use LeeRondas;
    use ResumeRondas;

    public const MAX_ORDINARIAS = 2;

    public const HORAS_PLAZO = 48;

    public function bloquear(Postulacion $postulacion): Postulacion
    {
        return Postulacion::query()->whereKey($postulacion->id)->lockForUpdate()->firstOrFail();
    }

    /** Cierra la marca «abierta» de las rondas cuyo plazo ya venció sin respuesta. */
    public function liberarVencidas(Postulacion $postulacion): void
    {
        CorreccionPostulacion::query()
            ->where('postulacion_id', $postulacion->id)
            ->where('abierta', 1)->whereNull('respondida_at')
            ->where('fecha_limite', '<', now())
            ->update(['abierta' => null, 'cerrada_at' => now()]);
    }

    /**
     * Convierte en rondas guardadas las solicitudes antiguas de la postulación. Idempotente: la evaluación de origen es
     * única. Conserva todos los datos y el plazo original, sin reiniciar nada.
     *
     * @return Collection<int, CorreccionPostulacion>
     */
    public function convertirLegadas(Postulacion $postulacion, ?Collection $legadas = null, bool $respetarSolapes = true): Collection
    {
        $legadas ??= $this->rondas($postulacion)->where('exists', false)->values();
        $convertidas = collect();

        // Solicitudes simultáneas de varios evaluadores: NO se agrupan ni se convierten sin revisión previa del administrador
        // (comando con --incluir-solapes). Mientras tanto siguen leyéndose como rondas separadas de solo lectura.
        if ($respetarSolapes && $legadas->count() > 1) {
            return $convertidas;
        }

        foreach ($legadas as $virtual) {
            if (CorreccionPostulacion::query()->where('evaluacion_legada_id', $virtual->evaluacion_legada_id)->exists()) {
                continue;
            }

            $numero = (int) CorreccionPostulacion::query()
                ->where('postulacion_id', $postulacion->id)->where('tipo', 'ordinaria')->max('numero') + 1;

            $guardada = CorreccionPostulacion::create(array_merge($virtual->getAttributes(), [
                'documentos_marcados' => $virtual->documentos_marcados,
                'documentos_respondidos' => $virtual->documentos_respondidos,
                'numero' => $numero,
                'abierta' => $virtual->estado() === 'vigente' ? 1 : null,
                'cerrada_at' => $virtual->estado() === 'vigente' ? null : ($virtual->respondida_at ?? $virtual->fecha_limite),
            ]));
            $convertidas->push($guardada);
        }

        return $convertidas;
    }

    /**
     * Pide una ronda de corrección. Exige al menos un documento marcado «No cumple» (de la convocatoria) y una
     * observación. Comprueba bajo bloqueo el estado y el vencimiento; falla con 422 si no corresponde.
     *
     * @param  list<int>  $marcados  ids de parámetro de los documentos que no cumplen
     */
    public function solicitar(Postulacion $postulacion, User $actor, Evaluacion $evaluacion, array $marcados, string $observaciones): CorreccionPostulacion
    {
        $postulacion = $this->bloquear($postulacion);
        $this->liberarVencidas($postulacion);
        $this->exigirSinSolapes($postulacion);
        $this->convertirLegadas($postulacion);

        $decision = $this->decision($postulacion);
        if (! $decision['puede_solicitar']) {
            $this->rechazar($decision['mensaje']);
        }

        $marcados = array_values(array_unique(array_map('intval', $marcados)));
        $validos = $postulacion->convocatoria->documentos()->pluck('parametros.id')->map(fn ($id) => (int) $id)->all();
        // La autorización del representante de un menor también se puede devolver para corregir (requisito por edad).
        if ($autorizacion = app(DocumentosDePostulacion::class)->autorizacionRequerida($postulacion)) {
            $validos[] = (int) $autorizacion->id;
        }
        if ($marcados === [] || array_diff($marcados, $validos) !== []) {
            $this->rechazar('Marca con "No cumple" al menos un documento de la convocatoria que el aspirante deba corregir.');
        }
        if (trim($observaciones) === '') {
            $this->rechazar('Debes escribir en las observaciones qué documentos o datos debe corregir el aspirante.');
        }

        $tipo = $decision['tipo'];
        $numero = (int) CorreccionPostulacion::query()
            ->where('postulacion_id', $postulacion->id)->where('tipo', $tipo)->max('numero') + 1;

        $ronda = CorreccionPostulacion::create([
            'postulacion_id' => $postulacion->id,
            'tipo' => $tipo,
            'numero' => $numero,
            'solicitada_por' => $actor->id,
            'evaluacion_id' => $evaluacion->id,
            'documentos_marcados' => $marcados,
            'documentos_base' => $postulacion->documentos()->whereIn('parametro_id', $marcados)->pluck('ruta', 'parametro_id')->all(),
            'observaciones' => $observaciones,
            'solicitada_at' => now(),
            'fecha_limite' => now()->addHours(self::HORAS_PLAZO),
            'abierta' => 1,
            'origen' => 'nueva',
        ]);

        if ($tipo === 'excepcional' && ($autorizacion = $this->autorizacionLibre($postulacion))) {
            $autorizacion->update(['usada_en_correccion_id' => $ronda->id, 'usada_at' => now(), 'sin_usar' => null]);
            $ronda->update(['autorizacion_id' => $autorizacion->id]);
        }

        // Dentro de la misma transacción: si la solicitud se deshace, los avisos también.
        app(Avisos::class)->correccionSolicitada($postulacion, count($marcados), $actor);

        return $ronda;
    }

    /**
     * Registra la respuesta del aspirante. Bajo bloqueo: comprueba que la ronda siga vigente y que todos los documentos
     * marcados se hayan reemplazado después de la solicitud. Un reenvío ya registrado responde igual (idempotente).
     *
     * @return array{0: CorreccionPostulacion, 1: list<int>} la ronda y los documentos que aún faltan
     */
    public function responder(Postulacion $postulacion): array
    {
        $postulacion = $this->bloquear($postulacion);
        $this->liberarVencidas($postulacion);
        $this->exigirSinSolapes($postulacion);

        $ronda = $this->rondas($postulacion)->last();
        if (! $ronda) {
            $this->rechazar('No hay una corrección solicitada para esta postulación.');
        }

        $estado = $ronda->estado();
        if ($estado === 'respondida') {
            return [$ronda, []];
        }
        if ($estado === 'sin_plazo') {
            $this->rechazar('Esta solicitud no tiene plazo registrado y no se puede responder. El evaluador puede finalizar con la evidencia disponible.');
        }
        if ($estado === 'vencida') {
            $vencio = $ronda->fecha_limite->copy()->setTimezone('America/Bogota')->format('d/m/Y H:i');
            $this->rechazar("El plazo para corregir venció el {$vencio} (hora de Colombia). Ya no puedes enviar la corrección.");
        }

        if (! $ronda->exists) {
            $ronda = $this->convertirLegadas($postulacion)->firstWhere('evaluacion_legada_id', $ronda->evaluacion_legada_id) ?? $ronda;
        }

        $marcados = array_map('intval', $ronda->documentos_marcados ?? []);
        $faltan = array_values(array_diff($marcados, $this->reemplazados($postulacion, $ronda, $marcados)));
        if ($faltan !== []) {
            return [$ronda, $faltan];
        }

        $ronda->update([
            'respondida_at' => now(),
            'documentos_respondidos' => $marcados,
            'abierta' => null,
            'cerrada_at' => now(),
        ]);

        app(Avisos::class)->correccionRespondida($postulacion, $ronda);

        return [$ronda, []];
    }

    /** Autorización excepcional: solo tras un vencimiento sin respuesta o al agotarse las ordinarias. */
    public function autorizar(Postulacion $postulacion, User $actor, string $justificacion): CorreccionAutorizacion
    {
        $postulacion = $this->bloquear($postulacion);
        $this->liberarVencidas($postulacion);
        $this->exigirSinSolapes($postulacion);
        $this->convertirLegadas($postulacion);

        $largo = mb_strlen(trim($justificacion));
        if ($largo < 10 || $largo > 500) {
            $this->rechazar('La justificación debe tener entre 10 y 500 caracteres.');
        }

        if ($this->autorizacionLibre($postulacion)) {
            $this->rechazar('Ya hay una autorización excepcional sin usar para esta postulación.');
        }

        $decision = $this->decision($postulacion);
        if (! in_array($decision['motivo'], ['vencida', 'sin_plazo', 'agotadas'], true)) {
            $this->rechazar($decision['motivo'] === 'pendiente'
                ? 'Hay una corrección vigente: no se puede autorizar una excepción hasta que responda o venza.'
                : 'Todavía se puede pedir una ronda ordinaria: la excepción procede solo tras un vencimiento sin respuesta o al agotarse las dos rondas.');
        }

        return CorreccionAutorizacion::create([
            'postulacion_id' => $postulacion->id,
            'autorizada_por' => $actor->id,
            'justificacion' => trim($justificacion),
            'sin_usar' => 1,
        ]);
    }

    private function rechazar(string $mensaje): never
    {
        throw ValidationException::withMessages(['correccion' => $mensaje]);
    }
}
