<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\PostulacionBorradorAyudas;
use Tests\Concerns\DatosPersonalesAyudas;
use Tests\TestCase;

class PostulacionEnvioRequisitosTest extends TestCase
{
    use RefreshDatabase;
    use DatosPersonalesAyudas;
    use PostulacionBorradorAyudas;

    public function test_sin_sexo_y_libreta_pedida_el_faltante_explica_indicar_el_sexo(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->convocatoriaAbierta($ficha, $instructor);
        $convocatoria->documentos()->attach($this->parametroId('tipo_documento', 'libreta_militar'), ['obligatorio' => true]);
        $postulacion = $this->crearPostulacion($convocatoria, $aspirante);
        $this->conDatosPersonales($postulacion);

        $respuesta = $this->actingAs($aspirante)
            ->postJson("/api/postulaciones/{$postulacion->id}/enviar")
            ->assertStatus(422);

        $this->assertSame('libreta_militar', $respuesta->json('faltantes.0.codigo'));
        $this->assertSame(
            'Llena tus datos personales para saber si aplica la libreta militar.',
            $respuesta->json('faltantes.0.motivo'),
        );
        $this->assertStringContainsString(
            'Llena tus datos personales para saber si aplica la libreta militar.',
            $respuesta->json('errors.documentos.0'),
        );
    }

    public function test_mujer_con_libreta_pedida_envia_sin_subirla(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->convocatoriaAbierta($ficha, $instructor);
        $convocatoria->documentos()->attach($this->parametroId('tipo_documento', 'libreta_militar'), ['obligatorio' => true]);
        $postulacion = $this->crearPostulacion($convocatoria, $aspirante);
        $this->conDatosPersonales($postulacion);

        $this->actingAs($aspirante)->putJson('/api/perfil/sexo', ['sexo' => 'mujer'])->assertOk();

        $this->actingAs($aspirante)
            ->postJson("/api/postulaciones/{$postulacion->id}/enviar")
            ->assertOk()
            ->assertJsonPath('data.estado.codigo', 'postulada');
    }

    public function test_hombre_con_libreta_pedida_debe_subirla(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->convocatoriaAbierta($ficha, $instructor);
        $convocatoria->documentos()->attach($this->parametroId('tipo_documento', 'libreta_militar'), ['obligatorio' => true]);
        $postulacion = $this->crearPostulacion($convocatoria, $aspirante);
        $this->conDatosPersonales($postulacion);

        $this->actingAs($aspirante)->putJson('/api/perfil/sexo', ['sexo' => 'hombre'])->assertOk();

        $respuesta = $this->actingAs($aspirante)
            ->postJson("/api/postulaciones/{$postulacion->id}/enviar")
            ->assertStatus(422);

        $this->assertSame('libreta_militar', $respuesta->json('faltantes.0.codigo'));
        // Con sexo registrado ya no se pide indicarlo.
        $this->assertNull($respuesta->json('faltantes.0.motivo'));

        $this->subirDocumento($aspirante, $postulacion, 'libreta_militar');

        $this->actingAs($aspirante)
            ->postJson("/api/postulaciones/{$postulacion->id}/enviar")
            ->assertOk()
            ->assertJsonPath('data.estado.codigo', 'postulada');
    }

    public function test_enviar_una_postulada_devuelve_422(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->convocatoriaAbierta($ficha, $instructor);
        $postulacion = $this->crearPostulacion($convocatoria, $aspirante, [
            'estado_id' => $this->parametroId('estado_postulacion', 'postulada'),
        ]);

        $this->actingAs($aspirante)
            ->postJson("/api/postulaciones/{$postulacion->id}/enviar")
            ->assertStatus(422)
            ->assertJsonPath('errors.postulacion.0', 'Solo se puede enviar una postulación en borrador.');
    }

    public function test_enviar_una_postulacion_ajena_devuelve_404(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspiranteA = $this->aspirante($ficha);
        $aspiranteB = $this->aspirante($ficha);
        $convocatoria = $this->convocatoriaAbierta($ficha, $instructor);
        $postulacionB = $this->crearPostulacion($convocatoria, $aspiranteB);

        $this->actingAs($aspiranteA)
            ->postJson("/api/postulaciones/{$postulacionB->id}/enviar")
            ->assertNotFound()
            ->assertJsonPath('message', 'Postulación no encontrada.');
    }

}
