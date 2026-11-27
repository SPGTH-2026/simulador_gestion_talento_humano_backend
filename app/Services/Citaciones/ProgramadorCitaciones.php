<?php

namespace App\Services\Citaciones;

use App\Enums\ConvocatoriaEstado;
use App\Enums\PostulacionEstado;
use App\Http\Controllers\Api\Concerns\ResultadosDocumentales;
use App\Models\Convocatoria;
use App\Models\Parametro;
use App\Models\Postulacion;
use App\Models\SeleccionCitacion;
use App\Models\SeleccionConfiguracion;
use App\Models\User;
use App\Services\Notificaciones\AvisosCitaciones;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Programar, reprogramar y cancelar citas de Selección. Todo ocurre en una transacción con la convocatoria bloqueada (la misma fila
 * que usan las calificaciones y la configuración): dos solicitudes simultáneas o un doble clic no duplican la cita vigente. Reenviar
 * lo mismo no hace nada (ni historial ni aviso). Las citas no condicionan las calificaciones ni la elegibilidad.
 */
final class ProgramadorCitaciones
{
    use ResultadosDocumentales;

    public function __construct(private AvisosCitaciones $avisos) {}

    /** @param array{fecha_hora: \Carbon\CarbonInterface, lugar_o_enlace: string, indicaciones: ?string} $d @return array{0: SeleccionCitacion, 1: bool} cita y si se creó */
    public function programar(User $actor, int $convocatoriaId, int $postulacionId, string $actividad, array $d): array
    {
        try {
            return DB::transaction(function () use ($actor, $convocatoriaId, $postulacionId, $actividad, $d) {
                $postulacion = $this->contexto($convocatoriaId, $postulacionId, $actividad);
                $vigente = SeleccionCitacion::query()
                    ->where('postulacion_id', $postulacion->id)->where('actividad', $actividad)->where('vigente', true)
                    ->lockForUpdate()->first();
                if ($vigente !== null) {
                    return $this->igual($vigente, $d) ? [$vigente, false] : $this->duplicada();
                }

                $cita = SeleccionCitacion::create([
                    'convocatoria_id' => $convocatoriaId, 'postulacion_id' => $postulacion->id, 'actividad' => $actividad,
                    'fecha_hora' => $d['fecha_hora'], 'lugar_o_enlace' => $d['lugar_o_enlace'], 'indicaciones' => $d['indicaciones'],
                    'estado' => SeleccionCitacion::PROGRAMADA, 'vigente' => true, 'creada_por' => $actor->id,
                ]);
                $cita->eventos()->create(['tipo' => 'programada', 'usuario_id' => $actor->id, 'detalle' => $this->instantanea($cita)]);
                $this->avisos->programada($cita);

                return [$cita, true];
            });
        } catch (UniqueConstraintViolationException) {
            $this->duplicada();
        }
    }

    /** @return array{0: SeleccionCitacion, 1: bool} cita y si cambió algo */
    public function reprogramar(User $actor, int $convocatoriaId, int $postulacionId, int $citacionId, array $d): array
    {
        return DB::transaction(function () use ($actor, $convocatoriaId, $postulacionId, $citacionId, $d) {
            $cita = $this->citaBloqueada($convocatoriaId, $postulacionId, $citacionId);
            $this->exigirProgramada($cita);
            $this->contexto($convocatoriaId, $postulacionId, $cita->actividad);
            if ($this->igual($cita, $d)) {
                return [$cita, false];
            }

            $anterior = $this->instantanea($cita);
            $cita->update(['fecha_hora' => $d['fecha_hora'], 'lugar_o_enlace' => $d['lugar_o_enlace'], 'indicaciones' => $d['indicaciones']]);
            $cita->eventos()->create(['tipo' => 'reprogramada', 'usuario_id' => $actor->id, 'detalle' => ['anterior' => $anterior, 'nuevo' => $this->instantanea($cita)]]);
            $this->avisos->reprogramada($cita);

            return [$cita, true];
        });
    }

    /** @return array{0: SeleccionCitacion, 1: bool} cita y si cambió algo */
    public function cancelar(User $actor, int $convocatoriaId, int $postulacionId, int $citacionId, string $motivo): array
    {
        return DB::transaction(function () use ($actor, $convocatoriaId, $postulacionId, $citacionId, $motivo) {
            $cita = $this->citaBloqueada($convocatoriaId, $postulacionId, $citacionId);
            if ($cita->estado === SeleccionCitacion::CANCELADA) {
                // Reenviar la misma cancelación no hace nada; con otro motivo, es un error claro.
                return $cita->motivo_cancelacion === $motivo ? [$cita, false] : $this->rechazar('citacion', 'La cita ya está cancelada.');
            }

            $cita->update(['estado' => SeleccionCitacion::CANCELADA, 'vigente' => null, 'motivo_cancelacion' => $motivo]);
            $cita->eventos()->create(['tipo' => 'cancelada', 'usuario_id' => $actor->id, 'detalle' => ['motivo' => $motivo]]);
            $this->avisos->cancelada($cita);

            return [$cita, true];
        });
    }

    /** Bloquea convocatoria y postulación y comprueba las condiciones para citar. Devuelve la postulación. */
    private function contexto(int $convocatoriaId, int $postulacionId, string $actividad): Postulacion
    {
        $conv = Convocatoria::query()->whereKey($convocatoriaId)->lockForUpdate()->first();
        $enProceso = (int) Parametro::porTemaYCodigo('estado_convocatoria', ConvocatoriaEstado::EnProceso->value)->id;
        if (! $conv || (int) $conv->estado_id !== $enProceso) {
            $this->rechazar('convocatoria', 'Solo se pueden programar citas con la convocatoria en proceso.');
        }

        $postulacion = Postulacion::query()->where('convocatoria_id', $conv->id)->whereKey($postulacionId)->lockForUpdate()->first();
        $postulada = (int) Parametro::porTemaYCodigo('estado_postulacion', PostulacionEstado::Postulada->value)->id;
        if (! $postulacion || (int) $postulacion->estado_id !== $postulada) {
            $this->rechazar('postulacion', 'La postulación ya no está en estado postulada.');
        }

        $configuracion = SeleccionConfiguracion::query()->where('convocatoria_id', $conv->id)->first();
        if ($configuracion === null || ! $configuracion->{$actividad.'_activa'}) {
            $this->rechazar('actividad', 'Esta actividad no está activa en la configuración de la convocatoria.');
        }

        [$estadoDocumental] = $this->estadoDocumentalDe($postulacion->id);
        if ($estadoDocumental !== 'habilitado') {
            $this->rechazar('postulacion', 'El candidato no está habilitado documentalmente (estado: '.$estadoDocumental.').');
        }

        return $postulacion;
    }

    private function citaBloqueada(int $convocatoriaId, int $postulacionId, int $citacionId): SeleccionCitacion
    {
        return SeleccionCitacion::query()
            ->where('convocatoria_id', $convocatoriaId)->where('postulacion_id', $postulacionId)
            ->lockForUpdate()->find($citacionId) ?? abort(404, 'Cita no encontrada.');
    }

    private function exigirProgramada(SeleccionCitacion $cita): void
    {
        if ($cita->estado !== SeleccionCitacion::PROGRAMADA) {
            $this->rechazar('citacion', 'La cita está cancelada: programa una nueva.');
        }
    }

    private function igual(SeleccionCitacion $cita, array $d): bool
    {
        return $cita->fecha_hora->equalTo($d['fecha_hora'])
            && $cita->lugar_o_enlace === $d['lugar_o_enlace']
            && ($cita->indicaciones ?? null) === ($d['indicaciones'] ?? null);
    }

    private function instantanea(SeleccionCitacion $cita): array
    {
        return [
            'fecha_hora' => $cita->fecha_hora->copy()->utc()->toIso8601String(),
            'lugar_o_enlace' => $cita->lugar_o_enlace,
            'indicaciones' => $cita->indicaciones,
        ];
    }

    private function duplicada(): never
    {
        $this->rechazar('actividad', 'Ya hay una cita vigente para esta actividad: reprográmala o cancélala.');
    }

    private function rechazar(string $campo, string $mensaje): never
    {
        throw ValidationException::withMessages([$campo => $mensaje]);
    }
}
