<?php

namespace Tests\Feature;

use App\Models\Convocatoria;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ConvocatoriaEdicionBloqueadaAyudas;
use Tests\TestCase;

class ConvocatoriaDetalleBloqueoTest extends TestCase
{
    use RefreshDatabase;
    use ConvocatoriaEdicionBloqueadaAyudas;

    /** @return array{0: User, 1: Convocatoria} */
    public function test_detalle_del_gestor_indica_si_hay_postulaciones(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $sinPostulaciones = $this->publicable($ficha, $instructor);

        $this->actingAs($instructor)
            ->getJson('/api/convocatorias/'.$sinPostulaciones->id)
            ->assertOk()
            ->assertJsonPath('data.tiene_postulaciones', false);

        $this->postular($sinPostulaciones, $this->aspirante($ficha));

        $this->actingAs($instructor)
            ->getJson('/api/convocatorias/'.$sinPostulaciones->id)
            ->assertOk()
            ->assertJsonPath('data.tiene_postulaciones', true);
    }

    public function test_el_aspirante_no_recibe_tiene_postulaciones(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->publicable($ficha, $instructor);
        $this->postular($convocatoria, $this->aspirante($ficha));

        $aspirante = $this->aspirante($ficha);

        $this->actingAs($aspirante)
            ->getJson('/api/convocatorias/'.$convocatoria->id)
            ->assertOk()
            ->assertJsonPath('data.tiene_postulaciones', false);
    }

}
