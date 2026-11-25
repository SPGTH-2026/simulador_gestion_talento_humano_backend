<?php

namespace Tests\Concerns;

use App\Models\Convocatoria;
use App\Models\Postulacion;
use App\Models\User;

/** Ayudas de las pruebas de citaciones (se suman a SeleccionCalificacionAyudas). */
trait CitacionesAyudas
{
    private function urlCita(Convocatoria $c, Postulacion $p, string $extra = ''): string
    {
        return "/api/seleccion/convocatorias/{$c->id}/candidatos/{$p->id}/citaciones{$extra}";
    }

    /** Cuerpo válido: una fecha futura lejana (hora de Colombia). */
    private function cuerpo(array $cambios = []): array
    {
        return $cambios + ['actividad' => 'entrevista', 'fecha' => now('America/Bogota')->addDays(10)->format('Y-m-d'), 'hora' => '14:30', 'lugar_o_enlace' => 'Sala 3, sede principal', 'indicaciones' => 'Llega 10 minutos antes.'];
    }

    private function programar(User $actor, Convocatoria $c, Postulacion $p, array $cambios = [])
    {
        return $this->actingAs($actor)->postJson($this->urlCita($c, $p), $this->cuerpo($cambios));
    }
}
