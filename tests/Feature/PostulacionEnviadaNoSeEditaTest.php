<?php

namespace Tests\Feature;

use App\Models\ConvocatoriaSkill;
use App\Models\PostulacionDocumento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\PostulacionDocumentoAyudas;
use Tests\TestCase;

/**
 * Enviada la postulación no se cambia nada: ni documentos ni habilidades. Para cambiar algo se
 * retira y se vuelve a postular. (La única excepción, una corrección vigente, se prueba en PostulacionDocumentoCorreccionTest.)
 */
class PostulacionEnviadaNoSeEditaTest extends TestCase
{
    use PostulacionDocumentoAyudas;
    use RefreshDatabase;

    private const MOTIVO = 'Tu postulación ya fue enviada. Para cambiar algo, retírala y vuelve a postularte.';

    /** @return array{0: \App\Models\User, 1: \App\Models\Postulacion, 2: ConvocatoriaSkill} */
    private function escenario(string $estado): array
    {
        $ficha = $this->ficha();
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->conDocumentos($this->convocatoriaAbierta($ficha, $this->instructor($ficha)), ['hoja_de_vida' => true]);
        $skill = ConvocatoriaSkill::create(['convocatoria_id' => $convocatoria->id, 'nombre' => 'Excel avanzado']);
        $postulacion = $this->crearPostulacion($convocatoria, $aspirante, ['estado_id' => $this->parametroId('estado_postulacion', $estado)]);

        return [$aspirante, $postulacion, $skill];
    }

    private function subir($aspirante, $postulacion, string $ruta, array $datos)
    {
        return $this->actingAs($aspirante)->withHeaders(['Accept' => 'application/json'])
            ->post("/api/postulaciones/{$postulacion->id}/{$ruta}", $datos + ['archivo' => $this->pdf()]);
    }

    public function test_enviada_no_deja_subir_ni_reemplazar_un_documento_requerido(): void
    {
        [$aspirante, $postulacion] = $this->escenario('postulada');

        $this->subir($aspirante, $postulacion, 'documentos', ['codigo' => 'hoja_de_vida'])
            ->assertStatus(422)
            ->assertJsonPath('errors.documentos.0', self::MOTIVO);

        $this->assertDatabaseCount('postulacion_documentos', 0);
    }

    public function test_enviada_no_deja_eliminar_un_documento_ya_cargado(): void
    {
        [$aspirante, $postulacion] = $this->escenario('retirada');
        $this->subir($aspirante, $postulacion, 'documentos', ['codigo' => 'hoja_de_vida'])->assertCreated();
        $cargado = PostulacionDocumento::sole();

        // La persona vuelve a postularse: desde ahí no se toca nada.
        $this->enviada($postulacion);

        $this->actingAs($aspirante)
            ->deleteJson("/api/postulaciones/{$postulacion->id}/documentos/{$cargado->id}")
            ->assertStatus(422)
            ->assertJsonPath('errors.documentos.0', self::MOTIVO);

        $this->assertDatabaseCount('postulacion_documentos', 1);
    }

    public function test_enviada_no_deja_declarar_habilidades(): void
    {
        [$aspirante, $postulacion, $skill] = $this->escenario('postulada');

        $this->actingAs($aspirante)
            ->postJson("/api/postulaciones/{$postulacion->id}/skills", ['convocatoria_skill_id' => $skill->id])
            ->assertStatus(422)
            ->assertJsonPath('errors.skills.0', self::MOTIVO);

        $this->assertDatabaseCount('postulacion_skills', 0);
    }

    public function test_el_estado_de_documentos_de_una_enviada_llega_como_no_editable_con_su_motivo(): void
    {
        [$aspirante, $postulacion] = $this->escenario('postulada');

        $this->actingAs($aspirante)->getJson("/api/postulaciones/{$postulacion->id}/documentos")
            ->assertOk()
            ->assertJsonPath('data.editable', false)
            ->assertJsonPath('data.motivo_edicion', self::MOTIVO);
    }

    public function test_retirada_si_deja_editar_y_al_volver_a_postularse_queda_fija_otra_vez(): void
    {
        [$aspirante, $postulacion, $skill] = $this->escenario('retirada');

        $this->subir($aspirante, $postulacion, 'documentos', ['codigo' => 'hoja_de_vida'])->assertCreated();
        $this->actingAs($aspirante)
            ->postJson("/api/postulaciones/{$postulacion->id}/skills", ['convocatoria_skill_id' => $skill->id])
            ->assertCreated();

        $this->enviada($postulacion);

        $this->subir($aspirante, $postulacion, 'documentos', ['codigo' => 'hoja_de_vida'])->assertStatus(422);
        $this->assertDatabaseCount('postulacion_documentos', 1);
    }
}
