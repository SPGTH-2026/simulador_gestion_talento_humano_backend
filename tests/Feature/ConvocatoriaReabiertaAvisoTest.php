<?php

namespace Tests\Feature;

use App\Models\Notificacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\PostulacionDocumentoAyudas;
use Tests\TestCase;

/**
 * Si se amplía el plazo de una convocatoria que ya había cerrado la recepción por fecha, quien ya tiene una postulación se entera:
 * a quien la envió se le confirma que sigue vigente; a quien la retiró, que puede volver a postularse.
 */
class ConvocatoriaReabiertaAvisoTest extends TestCase
{
    use PostulacionDocumentoAyudas;
    use RefreshDatabase;

    private function escenario(bool $cerrada): array
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $hoy = now('America/Bogota');
        $convocatoria = $this->crearConvocatoria($ficha, $instructor, [
            'estado_id' => $this->parametroId('estado_convocatoria', 'publicada'),
            'fecha_apertura' => $hoy->copy()->subDays(20)->toDateString(),
            'fecha_cierre' => $cerrada ? $hoy->copy()->subDay()->toDateString() : $hoy->copy()->addDays(2)->toDateString(),
        ]);

        return [$ficha, $instructor, $convocatoria];
    }

    private function ampliar($instructor, $convocatoria): void
    {
        $this->actingAs($instructor)
            ->patchJson("/api/convocatorias/{$convocatoria->id}", ['fecha_cierre' => now('America/Bogota')->addDays(10)->toDateString()])
            ->assertOk();
    }

    private function aviso($usuario): ?Notificacion
    {
        return Notificacion::query()->where('user_id', $usuario->id)->where('tipo', 'convocatoria_reabierta')->first();
    }

    public function test_al_ampliar_el_plazo_el_postulado_sabe_que_sigue_postulado_y_el_que_retiro_que_puede_volver(): void
    {
        [$ficha, $instructor, $convocatoria] = $this->escenario(cerrada: true);
        $postulado = $this->aspirante($ficha);
        $retirado = $this->aspirante($ficha);
        $sinPostulacion = $this->aspirante($ficha);
        $this->crearPostulacion($convocatoria, $postulado, ['estado_id' => $this->parametroId('estado_postulacion', 'postulada')]);
        $this->crearPostulacion($convocatoria, $retirado, ['estado_id' => $this->parametroId('estado_postulacion', 'retirada')]);

        $this->ampliar($instructor, $convocatoria);

        $this->assertStringContainsString('sigue enviada y vigente', $this->aviso($postulado)->mensaje);
        $this->assertStringContainsString('puedes volver a postularte', $this->aviso($retirado)->mensaje);
        $this->assertNull($this->aviso($sinPostulacion));
    }

    public function test_si_la_convocatoria_ya_estaba_abierta_no_se_avisa(): void
    {
        [$ficha, $instructor, $convocatoria] = $this->escenario(cerrada: false);
        $postulado = $this->aspirante($ficha);
        $this->crearPostulacion($convocatoria, $postulado, ['estado_id' => $this->parametroId('estado_postulacion', 'postulada')]);

        $this->ampliar($instructor, $convocatoria);

        $this->assertNull($this->aviso($postulado));
    }
}
