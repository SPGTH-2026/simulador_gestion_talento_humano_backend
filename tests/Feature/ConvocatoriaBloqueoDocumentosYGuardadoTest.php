<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ConvocatoriaEdicionBloqueadaAyudas;
use Tests\TestCase;

class ConvocatoriaBloqueoDocumentosYGuardadoTest extends TestCase
{
    use RefreshDatabase;
    use ConvocatoriaEdicionBloqueadaAyudas;

    public function test_con_postulacion_no_se_pueden_agregar_documentos(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->conDocumentos($this->publicable($ficha, $instructor), ['hoja_de_vida' => true]);
        $this->postular($convocatoria, $this->aspirante($ficha));

        $this->actingAs($instructor)
            ->patchJson('/api/convocatorias/'.$convocatoria->id, [
                'documentos' => [
                    ['codigo' => 'hoja_de_vida', 'obligatorio' => true],
                    ['codigo' => 'libreta_militar', 'obligatorio' => false],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.documentos.0', 'No se pueden agregar, quitar ni cambiar la obligatoriedad de los documentos porque la convocatoria ya tiene postulaciones.');

        $this->assertSame(['hoja_de_vida'], $convocatoria->fresh()->documentos->pluck('codigo')->all());
    }

    public function test_con_postulacion_no_se_pueden_quitar_documentos(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->conDocumentos($this->publicable($ficha, $instructor), [
            'hoja_de_vida' => true,
            'libreta_militar' => false,
        ]);
        $this->postular($convocatoria, $this->aspirante($ficha));

        $this->actingAs($instructor)
            ->patchJson('/api/convocatorias/'.$convocatoria->id, [
                'documentos' => [['codigo' => 'hoja_de_vida', 'obligatorio' => true]],
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.documentos.0', 'No se pueden agregar, quitar ni cambiar la obligatoriedad de los documentos porque la convocatoria ya tiene postulaciones.');

        $this->assertCount(2, $convocatoria->fresh()->documentos);
    }

    public function test_con_borrador_tampoco_se_pueden_agregar_documentos(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->conDocumentos($this->publicable($ficha, $instructor), ['hoja_de_vida' => true]);
        $this->borrador($convocatoria, $this->aspirante($ficha));

        $this->actingAs($instructor)
            ->patchJson('/api/convocatorias/'.$convocatoria->id, [
                'documentos' => [
                    ['codigo' => 'hoja_de_vida', 'obligatorio' => true],
                    ['codigo' => 'libreta_militar', 'obligatorio' => false],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.documentos.0', 'No se pueden agregar, quitar ni cambiar la obligatoriedad de los documentos porque la convocatoria ya tiene postulaciones.');
    }

    public function test_con_postulacion_el_mismo_conjunto_de_documentos_se_acepta(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->conDocumentos($this->publicable($ficha, $instructor), ['hoja_de_vida' => true]);
        $this->postular($convocatoria, $this->aspirante($ficha));

        $this->actingAs($instructor)
            ->patchJson('/api/convocatorias/'.$convocatoria->id, [
                'nombre' => 'Nombre nuevo',
                'documentos' => [['codigo' => 'hoja_de_vida', 'obligatorio' => true]],
            ])
            ->assertOk();

        $this->assertSame('Nombre nuevo', $convocatoria->fresh()->nombre);
    }

    public function test_sin_postulaciones_se_pueden_agregar_documentos(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->conDocumentos($this->publicable($ficha, $instructor), ['hoja_de_vida' => true]);

        $this->actingAs($instructor)
            ->patchJson('/api/convocatorias/'.$convocatoria->id, [
                'documentos' => [
                    ['codigo' => 'hoja_de_vida', 'obligatorio' => true],
                    ['codigo' => 'libreta_militar', 'obligatorio' => false],
                ],
            ])
            ->assertOk();

        // libreta_militar entra; los obligatorios del backend también quedan.
        $this->assertContains('libreta_militar', $convocatoria->fresh()->documentos->pluck('codigo')->all());
    }

    public function test_obligatoriedad_enviada_se_ignora_y_no_bloquea(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->publicable($ficha, $instructor);
        $this->conDocumentos($convocatoria, ['hoja_de_vida' => false]);
        $this->postular($convocatoria, $this->aspirante($ficha));

        // Se envía obligatorio = true, pero ese valor se ignora (lo decide el
        // backend) y, con postulaciones, no se reinyectan los obligatorios: el
        // conjunto de ids no cambia, la edición no se bloquea y lo guardado se
        // respeta.
        $respuesta = $this->actingAs($instructor)
            ->patchJson('/api/convocatorias/'.$convocatoria->id, [
                'documentos' => [['codigo' => 'hoja_de_vida', 'obligatorio' => true]],
            ])
            ->assertOk();

        $this->assertFalse($respuesta->json('data.documentos.0.obligatorio'));
    }

    public function test_con_postulaciones_no_se_reinyectan_los_obligatorios(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->publicable($ficha, $instructor);
        // A propósito, solo un documento extra (no obligatorio), como una
        // convocatoria creada antes de esta regla.
        $this->documentoExtra('documento_adicional');
        $this->conDocumentos($convocatoria, ['documento_adicional' => false]);
        $this->postular($convocatoria, $this->aspirante($ficha));

        $respuesta = $this->actingAs($instructor)
            ->patchJson('/api/convocatorias/'.$convocatoria->id, [
                'documentos' => [['codigo' => 'documento_adicional', 'obligatorio' => false]],
            ])
            ->assertOk();

        // No se agregan los obligatorios: se respeta lo guardado.
        $documentos = $respuesta->json('data.documentos');
        $this->assertCount(1, $documentos);
        $this->assertSame('documento_adicional', $documentos[0]['codigo']);
    }

    public function test_retirada_tambien_bloquea(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->publicable($ficha, $instructor);
        $this->retirar($convocatoria, $this->aspirante($ficha));

        $this->actingAs($instructor)
            ->patchJson('/api/convocatorias/'.$convocatoria->id, ['cargo' => 'Otro'])
            ->assertStatus(422)
            ->assertJsonPath('errors.cargo.0', $this->msg('cargo'));
    }

    public function test_varios_campos_bloqueados_se_reportan_juntos(): void
    {
        [$instructor, $convocatoria] = $this->publicadaConPostulada();

        $respuesta = $this->actingAs($instructor)
            ->patchJson('/api/convocatorias/'.$convocatoria->id, [
                'cargo' => 'Otro',
                'area' => 'Otra',
                'vacantes' => 7,
                'salario_min' => 1,
            ])
            ->assertStatus(422);

        $errores = $respuesta->json('errors');
        $this->assertSame([$this->msg('cargo')], $errores['cargo']);
        $this->assertSame([$this->msg('area')], $errores['area']);
        $this->assertSame([$this->msg('vacantes')], $errores['vacantes']);
        $this->assertSame([$this->msg('salario_min')], $errores['salario_min']);
        $this->assertArrayNotHasKey('salario_max', $errores);
    }

    public function test_bloqueo_no_guarda_ningun_cambio(): void
    {
        [$instructor, $convocatoria] = $this->publicadaConPostulada(['cargo' => 'Instructor', 'vacantes' => 2]);

        $this->actingAs($instructor)
            ->patchJson('/api/convocatorias/'.$convocatoria->id, ['cargo' => 'Otro', 'nombre' => 'Nuevo nombre'])
            ->assertStatus(422);

        $fresca = $convocatoria->fresh();
        $this->assertSame('Instructor', $fresca->cargo);
        // El nombre (editable) tampoco se guarda: un 422 no persiste nada.
        $this->assertSame('Instructor de software', $fresca->nombre);
    }

    // ── Campos permitidos ──────────────────────────────────────────────

}
