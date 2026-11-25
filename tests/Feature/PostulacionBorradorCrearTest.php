<?php

namespace Tests\Feature;

use App\Models\Postulacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\PostulacionBorradorAyudas;
use Tests\Concerns\DatosPersonalesAyudas;
use Tests\TestCase;

class PostulacionBorradorCrearTest extends TestCase
{
    use RefreshDatabase;
    use DatosPersonalesAyudas;
    use PostulacionBorradorAyudas;

    public function test_el_post_crea_el_borrador_con_201(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->convocatoriaAbierta($ficha, $instructor);

        $respuesta = $this->actingAs($aspirante)
            ->postJson("/api/convocatorias/{$convocatoria->id}/postulaciones")
            ->assertCreated();

        $this->assertSame('borrador', $respuesta->json('data.estado.codigo'));
    }

    public function test_un_segundo_post_devuelve_200_y_el_mismo_borrador(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->convocatoriaAbierta($ficha, $instructor);

        $primera = $this->actingAs($aspirante)
            ->postJson("/api/convocatorias/{$convocatoria->id}/postulaciones")
            ->assertCreated();

        $segunda = $this->actingAs($aspirante)
            ->postJson("/api/convocatorias/{$convocatoria->id}/postulaciones")
            ->assertOk();

        $this->assertSame($primera->json('data.id'), $segunda->json('data.id'));
        $this->assertSame('borrador', $segunda->json('data.estado.codigo'));
        $this->assertSame(1, Postulacion::count());
    }

    // ── envío ──────────────────────────────────────────────────────────

    public function test_enviar_sin_documentos_obligatorios_pasa_a_postulada(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        // Obligatorio opcional: no bloquea el envío. Sin obligatorios, envía.
        $convocatoria = $this->convocatoriaAbierta($ficha, $instructor);
        $postulacion = $this->crearPostulacion($convocatoria, $aspirante);
        $this->conDatosPersonales($postulacion);

        $respuesta = $this->actingAs($aspirante)
            ->postJson("/api/postulaciones/{$postulacion->id}/enviar")
            ->assertOk();

        $this->assertSame('postulada', $respuesta->json('data.estado.codigo'));
        $this->assertSame(
            $this->parametroId('estado_postulacion', 'postulada'),
            $postulacion->fresh()->estado_id,
        );

        // La fecha real de postulación se fija al enviar (no la de creación).
        $this->assertTrue($postulacion->fresh()->fecha_postulacion->greaterThan(now()->subSeconds(30)));
    }

    public function test_enviar_con_obligatorio_pendiente_devuelve_422_con_faltantes(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->convocatoriaAbierta($ficha, $instructor);
        $convocatoria->documentos()->attach($this->parametroId('tipo_documento', 'hoja_de_vida'), ['obligatorio' => true]);
        $convocatoria->documentos()->attach($this->parametroId('tipo_documento', 'certificados_academicos'), ['obligatorio' => false]);
        $postulacion = $this->crearPostulacion($convocatoria, $aspirante);
        $this->conDatosPersonales($postulacion);

        $respuesta = $this->actingAs($aspirante)
            ->postJson("/api/postulaciones/{$postulacion->id}/enviar")
            ->assertStatus(422);

        $this->assertSame('hoja_de_vida', $respuesta->json('faltantes.0.codigo'));
        $this->assertSame('Hoja de vida', $respuesta->json('faltantes.0.nombre'));
        $this->assertStringContainsString('Hoja de vida', $respuesta->json('errors.documentos.0'));
        // Sigue en borrador.
        $this->assertSame($this->parametroId('estado_postulacion', 'borrador'), $postulacion->fresh()->estado_id);
    }

    public function test_el_opcional_pendiente_no_bloquea_el_envio(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->convocatoriaAbierta($ficha, $instructor);
        $convocatoria->documentos()->attach($this->parametroId('tipo_documento', 'certificados_academicos'), ['obligatorio' => false]);
        $postulacion = $this->crearPostulacion($convocatoria, $aspirante);
        $this->conDatosPersonales($postulacion);

        $this->actingAs($aspirante)
            ->postJson("/api/postulaciones/{$postulacion->id}/enviar")
            ->assertOk()
            ->assertJsonPath('data.estado.codigo', 'postulada');
    }

    public function test_enviar_con_los_obligatorios_subidos_pasa_a_postulada(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->convocatoriaAbierta($ficha, $instructor);
        $convocatoria->documentos()->attach($this->parametroId('tipo_documento', 'hoja_de_vida'), ['obligatorio' => true]);
        $postulacion = $this->crearPostulacion($convocatoria, $aspirante);
        $this->conDatosPersonales($postulacion);

        $this->subirDocumento($aspirante, $postulacion, 'hoja_de_vida');

        $this->actingAs($aspirante)
            ->postJson("/api/postulaciones/{$postulacion->id}/enviar")
            ->assertOk()
            ->assertJsonPath('data.estado.codigo', 'postulada');
    }

}
