<?php

namespace Tests\Feature;

use App\Models\Postulacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\PostulacionRetiradaEditableAyudas;
use Tests\TestCase;

class PostulacionReactivarTest extends TestCase
{
    use RefreshDatabase;
    use PostulacionRetiradaEditableAyudas;

    public function test_eliminar_obligatorio_en_retirada_se_rechaza_y_la_postulacion_sigue_completa(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->conDocumentos($this->convocatoriaAbierta($ficha, $instructor), ['hoja_de_vida' => true]);
        $postulacion = $this->postulacionRetirada($convocatoria, $aspirante);
        $antes = $this->estadoDe($postulacion);

        $subida = $this->actingAs($aspirante)
            ->postJson("/api/postulaciones/{$postulacion->id}/documentos", [
                'codigo' => 'hoja_de_vida',
                'archivo' => $this->pdf(),
            ])
            ->assertCreated();

        $this->actingAs($aspirante)
            ->deleteJson("/api/postulaciones/{$postulacion->id}/documentos/{$subida->json('data.id')}")
            ->assertStatus(422)
            ->assertJsonPath('errors.documento.0', 'Este documento es obligatorio y no se puede eliminar: reemplázalo por otro archivo.');

        $this->actingAs($aspirante)
            ->getJson("/api/postulaciones/{$postulacion->id}/documentos")
            ->assertOk()
            ->assertJsonPath('data.completo', true);

        $this->assertSame($antes, $this->estadoDe($postulacion));
    }

    // ── Reactivación: "Volver a postularme" (retirada -> postulada) ────

    public function test_reactivar_sin_obligatorios_da_422_con_faltantes_y_sigue_retirada(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->conDocumentos($this->convocatoriaAbierta($ficha, $instructor), ['hoja_de_vida' => true]);
        $postulacion = $this->postulacionRetirada($convocatoria, $aspirante);
        $antes = $this->estadoDe($postulacion);

        $this->actingAs($aspirante)
            ->patchJson("/api/postulaciones/{$postulacion->id}/estado", ['estado' => 'postulada'])
            ->assertStatus(422)
            ->assertJsonPath('faltantes.0.codigo', 'hoja_de_vida');

        $this->assertSame($antes, $this->estadoDe($postulacion));
    }

    public function test_reactivar_con_obligatorios_completos_pasa_a_postulada(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->conDocumentos($this->convocatoriaAbierta($ficha, $instructor), ['hoja_de_vida' => true]);
        $postulacion = $this->postulacionRetirada($convocatoria, $aspirante);

        $this->actingAs($aspirante)
            ->postJson("/api/postulaciones/{$postulacion->id}/documentos", [
                'codigo' => 'hoja_de_vida',
                'archivo' => $this->pdf(),
            ])
            ->assertCreated();

        $this->actingAs($aspirante)
            ->patchJson("/api/postulaciones/{$postulacion->id}/estado", ['estado' => 'postulada'])
            ->assertOk()
            ->assertJsonPath('data.estado.codigo', 'postulada')
            ->assertJsonPath('data.fecha_retiro', null);
    }

    public function test_reactivar_fuera_de_la_ventana_da_422(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->convocatoriaCerrada($ficha, $instructor);
        $postulacion = $this->postulacionRetirada($convocatoria, $aspirante);

        $this->actingAs($aspirante)
            ->patchJson("/api/postulaciones/{$postulacion->id}/estado", ['estado' => 'postulada'])
            ->assertStatus(422)
            ->assertJsonPath(
                'errors.estado.0',
                'La convocatoria ya cerró el '.$convocatoria->fecha_cierre->format('d/m/Y').'.',
            );
    }

    public function test_postulada_directa_desde_borrador_sigue_redirigiendo_al_envio(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->convocatoriaAbierta($ficha, $instructor);
        $postulacion = Postulacion::create([
            'convocatoria_id' => $convocatoria->id,
            'user_id' => $aspirante->id,
            'estado_id' => $this->parametroId('estado_postulacion', 'borrador'),
            'fecha_postulacion' => now(),
        ]);

        $this->actingAs($aspirante)
            ->patchJson("/api/postulaciones/{$postulacion->id}/estado", ['estado' => 'postulada'])
            ->assertStatus(422)
            ->assertJsonPath('errors.estado.0', 'Para enviar tu postulación usa el endpoint de envío.');
    }

}
