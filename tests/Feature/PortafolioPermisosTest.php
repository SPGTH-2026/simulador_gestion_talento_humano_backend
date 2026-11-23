<?php

namespace Tests\Feature;

use App\Enums\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\PortafolioAyudas;
use Tests\TestCase;

class PortafolioPermisosTest extends TestCase
{
    use RefreshDatabase;
    use PortafolioAyudas;

    public function test_un_aspirante_sin_permiso_no_puede_abrir_portafolios(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $otro = $this->aspirante($ficha);
        $this->crearPostulacion($this->crearConvocatoria($ficha, $instructor), $aspirante);

        $this->actingAs($otro)
            ->getJson("/api/portafolios/{$aspirante->id}")
            ->assertForbidden();
    }

    public function test_un_usuario_sin_ficha_no_es_visible_para_el_instructor(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $sinFicha = $this->usuario(Role::Aspirante);
        $this->crearPostulacion($this->crearConvocatoria($ficha, $instructor), $sinFicha);

        $this->actingAs($instructor)
            ->getJson("/api/portafolios/{$sinFicha->id}")
            ->assertNotFound()
            ->assertJsonPath('message', 'Portafolio no encontrado.');
    }

    public function test_un_instructor_no_abre_el_portafolio_de_otra_ficha(): void
    {
        $fichaA = $this->ficha('3173334');
        $fichaB = $this->ficha('2661234');
        $instructorA = $this->instructor($fichaA);
        $instructorB = $this->instructor($fichaB);
        $aspiranteB = $this->aspirante($fichaB);
        $this->crearPostulacion($this->crearConvocatoria($fichaB, $instructorB), $aspiranteB);

        $this->actingAs($instructorA)
            ->getJson("/api/portafolios/{$aspiranteB->id}")
            ->assertNotFound()
            ->assertJsonPath('message', 'Portafolio no encontrado.');
    }

    public function test_un_instructor_abre_el_portafolio_de_su_ficha(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->crearConvocatoria($ficha, $instructor);
        $this->crearPostulacion($convocatoria, $aspirante);

        $this->actingAs($instructor)
            ->getJson("/api/portafolios/{$aspirante->id}")
            ->assertOk()
            ->assertJsonPath('data.usuario.id', $aspirante->id);
    }

    public function test_un_portafolio_inexistente_devuelve_404(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);

        $this->actingAs($instructor)
            ->getJson('/api/portafolios/999')
            ->assertNotFound();
    }

}
