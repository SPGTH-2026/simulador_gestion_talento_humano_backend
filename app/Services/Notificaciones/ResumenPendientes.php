<?php

namespace App\Services\Notificaciones;

use App\Enums\ConvocatoriaEstado;
use App\Enums\PostulacionEstado;
use App\Enums\Role;
use App\Enums\TipoNotificacion;
use App\Models\Convocatoria;
use App\Models\CorreccionPostulacion;
use App\Models\Evaluacion;
use App\Models\Ficha;
use App\Models\Notificacion;
use App\Models\Postulacion;
use App\Models\SeleccionDecision;
use App\Models\User;
use Carbon\CarbonInterface;

/**
 * Resumen diario de lo que cada persona tiene pendiente. No responde a un evento: se calcula con el estado real y avisa
 * solo cuando hay algo que atender. Se ejecuta una vez al día (ver routes/console.php); si se vuelve a ejecutar el mismo
 * día, no repite los avisos ya enviados.
 *
 * Qué es «pendiente» (la misma lógica que usan las pantallas):
 *  - Por evaluar: postulación enviada (postulada) de una persona activa, sin evaluación finalizada. El evaluador cuenta
 *    solo la suya; el instructor cuenta la de la ficha.
 *  - Por seleccionar: postulación cuya evaluación finalizada pasó el umbral y que aún no tiene decisión de selección.
 *  - Por cerrar: convocatoria de la ficha en proceso (el gestor la cierra a mano cuando termina).
 *  - Aspirante: borrador de una convocatoria publicada; corrección abierta que vence en menos de 24 h; convocatoria
 *    publicada que cierra en 3 días o menos y a la que aún no se ha postulado.
 */
final class ResumenPendientes
{
    private const DIAS_ANTES_DEL_CIERRE = 3;

    private const HORAS_PARA_VENCER = 24;

    public function __construct(
        private Notificador $notificador,
        private Destinatarios $destinatarios,
    ) {}

    /** Envía los resúmenes del día. Devuelve cuántos avisos se crearon. */
    public function ejecutar(?CarbonInterface $ahora = null): int
    {
        $ahora = ($ahora ?? now())->copy()->setTimezone('America/Bogota');
        // Inicio del día en Colombia, expresado en UTC (así se compara con created_at).
        $inicioDia = $ahora->copy()->startOfDay()->utc();

        $creados = 0;

        foreach (Ficha::query()->get() as $ficha) {
            $personal = $this->destinatarios->personalDeFicha($ficha->id, ['evaluador', 'seleccionador', 'gestor_convocatorias'], true);
            foreach ($personal as $usuario) {
                $creados += $this->resumenDePersonal($usuario, $ficha, $ahora, $inicioDia);
            }

            foreach ($this->destinatarios->aspirantesDeFicha($ficha->id) as $aspirante) {
                $creados += $this->resumenDeAspirante($aspirante, $ficha, $ahora, $inicioDia);
            }
        }

        return $creados;
    }

    private function resumenDePersonal(User $usuario, Ficha $ficha, CarbonInterface $ahora, CarbonInterface $inicioDia): int
    {
        $instructor = $usuario->role === Role::Instructor;
        $codigo = $ficha->codigo;
        $creados = 0;

        if ($instructor || $usuario->subrole === 'evaluador') {
            $n = $this->porEvaluar($ficha->id, $instructor ? null : $usuario->id);
            $creados += $this->enviarSiHay($usuario, TipoNotificacion::PendientesEvaluar, $n, $inicioDia,
                'Aspirantes por evaluar',
                fn () => "Tienes {$this->cuenta($n, 'aspirante pendiente', 'aspirantes pendientes')} por evaluar en la ficha {$codigo}.",
                '/evaluacion');
        }

        if ($instructor || $usuario->subrole === 'seleccionador') {
            $n = $this->porSeleccionar($ficha->id);
            $creados += $this->enviarSiHay($usuario, TipoNotificacion::PendientesSeleccion, $n, $inicioDia,
                'Aspirantes por seleccionar',
                fn () => "{$this->cuenta($n, 'aspirante aprobado', 'aspirantes aprobados')} en evaluación ".$this->verbo($n, 'espera', 'esperan')." decisión de selección en la ficha {$codigo}.",
                '/seleccion/decidir');
        }

        if ($instructor || $usuario->subrole === 'gestor_convocatorias') {
            $n = $this->convocatoriasEnProceso($ficha->id);
            $creados += $this->enviarSiHay($usuario, TipoNotificacion::ConvocatoriasPorCerrar, $n, $inicioDia,
                'Convocatorias por cerrar',
                fn () => "{$this->cuenta($n, 'convocatoria en proceso', 'convocatorias en proceso')} de la ficha {$codigo} ".$this->verbo($n, 'espera', 'esperan')." cierre.",
                '/convocatorias');
        }

        return $creados;
    }

    private function resumenDeAspirante(User $aspirante, Ficha $ficha, CarbonInterface $ahora, CarbonInterface $inicioDia): int
    {
        $creados = 0;

        $borradores = Postulacion::query()
            ->where('user_id', $aspirante->id)
            ->whereHas('estado', fn ($q) => $q->where('codigo', PostulacionEstado::Borrador->value))
            ->whereHas('convocatoria.estado', fn ($q) => $q->where('codigo', ConvocatoriaEstado::Publicada->value))
            ->count();
        $creados += $this->enviarSiHay($aspirante, TipoNotificacion::BorradorPendiente, $borradores, $inicioDia,
            'Postulación sin enviar',
            fn () => "Tienes {$this->cuenta($borradores, 'postulación en borrador', 'postulaciones en borrador')} sin enviar. Completa los documentos y envíala antes del cierre.",
            '/mis-postulaciones');

        $vence = CorreccionPostulacion::query()
            ->where('abierta', 1)
            ->where('fecha_limite', '>', $ahora->copy()->utc())
            ->where('fecha_limite', '<=', $ahora->copy()->addHours(self::HORAS_PARA_VENCER)->utc())
            ->whereHas('postulacion', fn ($q) => $q->where('user_id', $aspirante->id))
            ->orderBy('fecha_limite')
            ->first();
        if ($vence) {
            $cuando = $vence->fecha_limite->copy()->setTimezone('America/Bogota')->format('d/m/Y H:i');
            $creados += $this->enviarSiHay($aspirante, TipoNotificacion::CorreccionPorVencer, 1, $inicioDia,
                'Tu corrección vence pronto',
                fn () => "Tu plazo para corregir documentos vence el {$cuando} (hora de Colombia). Envía los documentos corregidos a tiempo.",
                '/mis-postulaciones');
        }

        $cierranPronto = Convocatoria::query()
            ->where('ficha_id', $ficha->id)
            ->whereHas('estado', fn ($q) => $q->where('codigo', ConvocatoriaEstado::Publicada->value))
            ->whereBetween('fecha_cierre', [$ahora->toDateString(), $ahora->copy()->addDays(self::DIAS_ANTES_DEL_CIERRE)->toDateString()])
            ->whereDoesntHave('postulaciones', fn ($q) => $q->where('user_id', $aspirante->id))
            ->count();
        $creados += $this->enviarSiHay($aspirante, TipoNotificacion::CierreProximo, $cierranPronto, $inicioDia,
            'Convocatoria por cerrar',
            fn () => "{$this->cuenta($cierranPronto, 'convocatoria de tu ficha cierra', 'convocatorias de tu ficha cierran')} en los próximos {$this->cuenta(self::DIAS_ANTES_DEL_CIERRE, 'día', 'días')} y aún no te has postulado.",
            '/convocatorias');

        return $creados;
    }

    /** Postulaciones enviadas, de personas activas, sin evaluación finalizada (por el evaluador, o por nadie si es null). */
    private function porEvaluar(int $fichaId, ?int $evaluadorId): int
    {
        return Postulacion::query()
            ->whereHas('estado', fn ($q) => $q->where('codigo', PostulacionEstado::Postulada->value))
            ->whereHas('convocatoria', fn ($q) => $q->where('ficha_id', $fichaId))
            ->whereHas('user', fn ($q) => $q->where('active', true))
            ->whereNotIn('id', Evaluacion::query()
                ->where('estado', 'finalizada')
                ->when($evaluadorId !== null, fn ($q) => $q->where('evaluador_id', $evaluadorId))
                ->select('postulacion_id'))
            ->count();
    }

    /** Aprobados en evaluación (pasa_seleccion) que aún no tienen decisión de selección. */
    private function porSeleccionar(int $fichaId): int
    {
        return Postulacion::query()
            ->whereHas('estado', fn ($q) => $q->where('codigo', PostulacionEstado::Postulada->value))
            ->whereHas('convocatoria', fn ($q) => $q->where('ficha_id', $fichaId))
            ->whereIn('id', Evaluacion::query()
                ->where('estado', 'finalizada')
                ->where('pasa_seleccion', true)
                ->select('postulacion_id'))
            ->whereNotIn('id', SeleccionDecision::query()->select('postulacion_id'))
            ->count();
    }

    private function convocatoriasEnProceso(int $fichaId): int
    {
        return Convocatoria::query()
            ->where('ficha_id', $fichaId)
            ->whereHas('estado', fn ($q) => $q->where('codigo', ConvocatoriaEstado::EnProceso->value))
            ->count();
    }

    /**
     * Crea el aviso si hay algo pendiente y no se envió ya hoy. Devuelve 1 si lo creó, 0 si no.
     *
     * @param  \Closure(): string  $mensaje
     */
    private function enviarSiHay(User $usuario, TipoNotificacion $tipo, int $cantidad, CarbonInterface $inicioDia, string $titulo, \Closure $mensaje, string $url): int
    {
        if ($cantidad <= 0) {
            return 0;
        }

        $yaEnviado = Notificacion::query()
            ->where('user_id', $usuario->id)
            ->where('tipo', $tipo->value)
            ->where('created_at', '>=', $inicioDia)
            ->exists();
        if ($yaEnviado) {
            return 0;
        }

        $this->notificador->enviar($usuario, $tipo, $titulo, $mensaje(), $url);

        return 1;
    }

    /** El verbo en singular o plural según la cantidad («1 espera» / «3 esperan»). */
    private function verbo(int $n, string $singular, string $plural): string
    {
        return $n === 1 ? $singular : $plural;
    }

    /** «1 aspirante» / «3 aspirantes»: el número y la palabra en singular o plural. */
    private function cuenta(int $n, string $singular, string $plural): string
    {
        return $n.' '.($n === 1 ? $singular : $plural);
    }
}
