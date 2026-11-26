<?php

namespace Tests\Feature;

use App\Models\Postulacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\PostulacionDocumentoAyudas;
use Tests\TestCase;

class PostulacionDocumentoVentanasTest extends TestCase
{
    use RefreshDatabase;
    use PostulacionDocumentoAyudas;

    private const MOTIVO_ENVIADA = 'Tu postulación ya fue enviada. Para cambiar algo, retírala y vuelve a postularte.';

    /** Enviada la postulación no se edita, sin importar si la convocatoria sigue abierta, ya cerró o ya empezó la evaluación. */
    #[\PHPUnit\Framework\Attributes\DataProvider('ventanasDeUnaPostulacionEnviada')]
    public function test_una_postulacion_enviada_no_se_edita_en_ninguna_ventana(int $apertura, int $cierre, ?int $evaluacion): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $hoy = now('America/Bogota');
        $convocatoria = $this->conDocumentos($this->crearConvocatoria($ficha, $instructor, [
            'estado_id' => $this->parametroId('estado_convocatoria', 'publicada'),
            'fecha_apertura' => $hoy->copy()->addDays($apertura)->toDateString(),
            'fecha_cierre' => $hoy->copy()->addDays($cierre)->toDateString(),
            'fecha_evaluacion' => $evaluacion === null ? null : $hoy->copy()->addDays($evaluacion)->toDateString(),
        ]), ['hoja_de_vida' => true]);
        $postulacion = $this->crearPostulacion($convocatoria, $aspirante, ['estado_id' => $this->parametroId('estado_postulacion', 'postulada')]);

        $url = "/api/postulaciones/{$postulacion->id}/documentos";

        $this->actingAs($aspirante)
            ->getJson($url)
            ->assertOk()
            ->assertJsonPath('data.editable', false)
            ->assertJsonPath('data.motivo_edicion', self::MOTIVO_ENVIADA);

        $this->actingAs($aspirante)->withHeaders(['Accept' => 'application/json'])
            ->post($url, ['codigo' => 'hoja_de_vida', 'archivo' => $this->pdf()])
            ->assertStatus(422)
            ->assertJsonPath('errors.documentos.0', self::MOTIVO_ENVIADA);
        $this->assertDatabaseCount('postulacion_documentos', 0);
    }

    /** @return array<string, array{int, int, ?int}> días respecto a hoy: apertura, cierre y fecha de evaluación */
    public static function ventanasDeUnaPostulacionEnviada(): array
    {
        return [
            'convocatoria abierta' => [-10, 10, null],
            'convocatoria cerrada, sin evaluación' => [-10, -1, null],
            'evaluación ya empezó' => [-10, -2, -1],
        ];
    }

    /** Una postulación retirada sí se edita mientras la convocatoria siga recibiendo postulaciones. */
    public function test_una_postulacion_retirada_se_edita_con_la_ventana_abierta(): void
    {
        $ficha = $this->ficha();
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->conDocumentos($this->convocatoriaAbierta($ficha, $this->instructor($ficha)), ['hoja_de_vida' => true]);
        $postulacion = $this->crearPostulacion($convocatoria, $aspirante, ['estado_id' => $this->parametroId('estado_postulacion', 'retirada')]);

        $this->actingAs($aspirante)->getJson("/api/postulaciones/{$postulacion->id}/documentos")
            ->assertOk()
            ->assertJsonPath('data.editable', true);

        $this->actingAs($aspirante)->withHeaders(['Accept' => 'application/json'])
            ->post("/api/postulaciones/{$postulacion->id}/documentos", ['codigo' => 'hoja_de_vida', 'archivo' => $this->pdf()])
            ->assertCreated();
    }

    public function test_no_se_puede_subir_con_convocatoria_en_proceso(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->conDocumentos($this->crearConvocatoria($ficha, $instructor, [
            'estado_id' => $this->parametroId('estado_convocatoria', 'en_proceso'),
            'fecha_apertura' => now('America/Bogota')->subDays(10)->toDateString(),
            'fecha_cierre' => now('America/Bogota')->subDay()->toDateString(),
        ]), ['hoja_de_vida' => true]);
        $postulacion = $this->crearPostulacion($convocatoria, $aspirante);

        $url = "/api/postulaciones/{$postulacion->id}/documentos";

        $this->actingAs($aspirante)->withHeaders(['Accept' => 'application/json'])
            ->post($url, ['codigo' => 'hoja_de_vida', 'archivo' => $this->pdf()])
            ->assertNotFound();
    }

    public function test_borrador_no_editable_despues_del_cierre(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->conDocumentos($this->crearConvocatoria($ficha, $instructor, [
            'estado_id' => $this->parametroId('estado_convocatoria', 'publicada'),
            'fecha_apertura' => now('America/Bogota')->subDays(10)->toDateString(),
            'fecha_cierre' => now('America/Bogota')->subDay()->toDateString(),
        ]), ['hoja_de_vida' => true]);
        // crearPostulacion por defecto crea postulada; creamos borrador manual
        $postulacion = Postulacion::create([
            'convocatoria_id' => $convocatoria->id,
            'user_id' => $aspirante->id,
            'estado_id' => $this->parametroId('estado_postulacion', 'borrador'),
            'fecha_postulacion' => now(),
        ]);

        $url = "/api/postulaciones/{$postulacion->id}/documentos";

        $this->actingAs($aspirante)
            ->getJson($url)
            ->assertOk()
            ->assertJsonPath('data.editable', false)
            ->assertJsonPath('data.motivo_edicion', 'La convocatoria ya cerró el '.$convocatoria->fecha_cierre->format('d/m/Y').'. Los borradores no se editan fuera de la ventana.');

        $this->actingAs($aspirante)->withHeaders(['Accept' => 'application/json'])
            ->post($url, ['codigo' => 'hoja_de_vida', 'archivo' => $this->pdf()])
            ->assertStatus(422);
    }

    public function test_no_se_puede_subir_antes_de_apertura(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->conDocumentos($this->crearConvocatoria($ficha, $instructor, [
            'estado_id' => $this->parametroId('estado_convocatoria', 'publicada'),
            'fecha_apertura' => now('America/Bogota')->addDays(5)->toDateString(),
            'fecha_cierre' => now('America/Bogota')->addDays(10)->toDateString(),
        ]), ['hoja_de_vida' => true]);
        $postulacion = $this->crearPostulacion($convocatoria, $aspirante);

        $url = "/api/postulaciones/{$postulacion->id}/documentos";

        $this->actingAs($aspirante)
            ->getJson($url)
            ->assertOk()
            ->assertJsonPath('data.editable', false)
            ->assertJsonPath('data.motivo_edicion', 'La convocatoria aún no está abierta. Abre el '.$convocatoria->fecha_apertura->format('d/m/Y').'.');

        $this->actingAs($aspirante)->withHeaders(['Accept' => 'application/json'])
            ->post($url, ['codigo' => 'hoja_de_vida', 'archivo' => $this->pdf()])
            ->assertStatus(422);
    }

    public function test_aspirante_sin_sesion_devuelve_401(): void
    {
        $this->postJson('/api/postulaciones/1/documentos')->assertUnauthorized();
    }

    // ── Adicionales ────────────────────────────────────────────────────

}
