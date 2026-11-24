<?php

namespace Tests\Feature;

use App\Models\Ficha;
use App\Models\Notificacion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ConvocatoriaEstadoAyudas;
use Tests\TestCase;

/**
 * Publicar una convocatoria por la API avisa a los aspirantes de SU ficha (la prueba de estado se hace con el
 * controlador real, con sus validaciones de publicación).
 */
class NotificacionesPublicarTest extends TestCase
{
    use RefreshDatabase;
    use ConvocatoriaEstadoAyudas;

    public function test_publicar_avisa_a_los_aspirantes_de_la_ficha_y_no_a_los_de_otra(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->crearConvocatoria($ficha, $instructor, $this->publicable());

        $dentro = $this->aspirante($ficha);
        $fuera = $this->aspirante(Ficha::create(['codigo' => '9999999', 'nombre_programa' => 'Otra']));

        $this->actingAs($instructor)
            ->patchJson('/api/convocatorias/'.$convocatoria->id.'/estado', ['estado' => 'publicada'])
            ->assertOk();

        $avisados = Notificacion::query()->where('tipo', 'convocatoria_publicada')->pluck('user_id')->all();
        $this->assertSame([$dentro->id], $avisados);
        $this->assertNotContains($fuera->id, $avisados);
    }
}
