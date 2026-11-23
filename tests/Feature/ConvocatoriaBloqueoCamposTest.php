<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ConvocatoriaEdicionBloqueadaAyudas;
use Tests\TestCase;

class ConvocatoriaBloqueoCamposTest extends TestCase
{
    use RefreshDatabase;
    use ConvocatoriaEdicionBloqueadaAyudas;

    public function test_publicada_sin_postulaciones_permite_cambiar_campos_bloqueados(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->conDocumentos($this->publicable($ficha, $instructor), ['hoja_de_vida' => true]);

        $this->actingAs($instructor)->patchJson('/api/convocatorias/'.$convocatoria->id, [
            'cargo' => 'Coordinador',
            'area' => 'Administración',
            'vacantes' => 5,
            'salario_min' => 5000000,
            'salario_max' => 6000000,
            'tipo_convocatoria' => 'interno',
            'tipo_empleo' => 'medio_tiempo',
            'tipo_contrato' => 'termino_indefinido',
            'modalidad' => 'remoto',
            'fecha_apertura' => now('America/Bogota')->toDateString(),
            'skills' => ['Nueva'],
            'documentos' => [['codigo' => 'libreta_militar', 'obligatorio' => false]],
        ])->assertOk();

        $fresca = $convocatoria->fresh();
        $this->assertSame('Coordinador', $fresca->cargo);
        $this->assertSame(5, $fresca->vacantes);
        $this->assertSame('interno', $fresca->tipoConvocatoria->codigo);
        $this->assertSame('remoto', $fresca->modalidad->codigo);
        $this->assertSame(['Nueva'], $fresca->skills->pluck('nombre')->all());
        $libreta = $fresca->documentos->firstWhere('codigo', 'libreta_militar');
        $this->assertNotNull($libreta);
        // libreta_militar es opcional del gestor: al incluirla queda obligatoria.
        $this->assertTrue((bool) $libreta->pivot->obligatorio);
    }

    public function test_borrador_permite_cambiar_campos_bloqueados(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->crearConvocatoria($ficha, $instructor, ['cargo' => 'Instructor', 'vacantes' => 1]);

        $this->actingAs($instructor)
            ->patchJson('/api/convocatorias/'.$convocatoria->id, ['cargo' => 'Coordinador', 'vacantes' => 3])
            ->assertOk();

        $this->assertSame('Coordinador', $convocatoria->fresh()->cargo);
    }

    // ── regla H: el borrador NO cuenta como postulación ────────────────

    public function test_solo_borradores_no_bloquean_los_campos(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->publicable($ficha, $instructor);
        $this->borrador($convocatoria, $this->aspirante($ficha));

        $this->actingAs($instructor)
            ->patchJson('/api/convocatorias/'.$convocatoria->id, ['cargo' => 'Coordinador', 'vacantes' => 5])
            ->assertOk();

        $this->assertSame('Coordinador', $convocatoria->fresh()->cargo);
        $this->assertSame(5, $convocatoria->fresh()->vacantes);
    }

    public function test_un_borrador_junto_a_una_postulada_si_bloquea(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->publicable($ficha, $instructor);
        $this->borrador($convocatoria, $this->aspirante($ficha));
        $this->postular($convocatoria, $this->aspirante($ficha));

        $respuesta = $this->actingAs($instructor)
            ->patchJson('/api/convocatorias/'.$convocatoria->id, ['cargo' => 'Otro'])
            ->assertStatus(422);

        $this->assertSame([$this->msg('cargo')], $respuesta->json('errors.cargo'));
    }

    // ── Campos bloqueados con postulada ────────────────────────────────

    public function test_postulada_bloquea_cargo(): void
    {
        [$instructor, $convocatoria] = $this->publicadaConPostulada();

        $respuesta = $this->actingAs($instructor)
            ->patchJson('/api/convocatorias/'.$convocatoria->id, ['cargo' => 'Otro'])
            ->assertStatus(422);

        $this->assertSame([$this->msg('cargo')], $respuesta->json('errors.cargo'));
        $this->assertSame('Instructor', $convocatoria->fresh()->cargo);
    }

    public function test_postulada_bloquea_area(): void
    {
        [$instructor, $convocatoria] = $this->publicadaConPostulada();

        $this->actingAs($instructor)
            ->patchJson('/api/convocatorias/'.$convocatoria->id, ['area' => 'Otra'])
            ->assertStatus(422)
            ->assertJsonPath('errors.area.0', $this->msg('area'));
    }

    public function test_postulada_bloquea_vacantes(): void
    {
        [$instructor, $convocatoria] = $this->publicadaConPostulada();

        $this->actingAs($instructor)
            ->patchJson('/api/convocatorias/'.$convocatoria->id, ['vacantes' => 9])
            ->assertStatus(422)
            ->assertJsonPath('errors.vacantes.0', $this->msg('vacantes'));
    }

    public function test_postulada_bloquea_salario_min(): void
    {
        [$instructor, $convocatoria] = $this->publicadaConPostulada();

        $this->actingAs($instructor)
            ->patchJson('/api/convocatorias/'.$convocatoria->id, ['salario_min' => 100])
            ->assertStatus(422)
            ->assertJsonPath('errors.salario_min.0', $this->msg('salario_min'));
    }

    public function test_postulada_bloquea_salario_max(): void
    {
        [$instructor, $convocatoria] = $this->publicadaConPostulada();

        $this->actingAs($instructor)
            ->patchJson('/api/convocatorias/'.$convocatoria->id, ['salario_max' => 9999999])
            ->assertStatus(422)
            ->assertJsonPath('errors.salario_max.0', $this->msg('salario_max'));
    }

    public function test_postulada_bloquea_las_cuatro_clasificaciones(): void
    {
        [$instructor, $convocatoria] = $this->publicadaConPostulada();

        $respuesta = $this->actingAs($instructor)
            ->patchJson('/api/convocatorias/'.$convocatoria->id, [
                'tipo_convocatoria' => 'interno',
                'tipo_empleo' => 'por_horas',
                'tipo_contrato' => 'obra_labor',
                'modalidad' => 'hibrido',
            ])
            ->assertStatus(422);

        $this->assertSame([$this->msg('tipo_convocatoria')], $respuesta->json('errors.tipo_convocatoria'));
        $this->assertSame([$this->msg('tipo_empleo')], $respuesta->json('errors.tipo_empleo'));
        $this->assertSame([$this->msg('tipo_contrato')], $respuesta->json('errors.tipo_contrato'));
        $this->assertSame([$this->msg('modalidad')], $respuesta->json('errors.modalidad'));
    }

    public function test_bloqueo_de_clasificacion_por_id_reporta_clave_canonica(): void
    {
        [$instructor, $convocatoria] = $this->publicadaConPostulada();

        $respuesta = $this->actingAs($instructor)
            ->patchJson('/api/convocatorias/'.$convocatoria->id, [
                'tipo_convocatoria_id' => $this->parametroId('tipo_convocatoria', 'interno'),
            ])
            ->assertStatus(422);

        $this->assertSame([$this->msg('tipo_convocatoria')], $respuesta->json('errors.tipo_convocatoria'));
        $this->assertNull($respuesta->json('errors.tipo_convocatoria_id'));
    }

    public function test_postulada_bloquea_fecha_apertura(): void
    {
        [$instructor, $convocatoria] = $this->publicadaConPostulada();

        $this->actingAs($instructor)
            ->patchJson('/api/convocatorias/'.$convocatoria->id, [
                'fecha_apertura' => now('America/Bogota')->addDays(2)->toDateString(),
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.fecha_apertura.0', $this->msg('fecha_apertura'));
    }

    public function test_postulada_bloquea_skills(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->publicable($ficha, $instructor);
        $this->conSkills($convocatoria, ['PHP', 'Laravel']);
        $this->postular($convocatoria, $this->aspirante($ficha));

        $this->actingAs($instructor)
            ->patchJson('/api/convocatorias/'.$convocatoria->id, ['skills' => ['PHP', 'Laravel', 'MySQL']])
            ->assertStatus(422)
            ->assertJsonPath('errors.skills.0', $this->msg('skills'));
    }

    public function test_postulada_bloquea_documentos(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->publicable($ficha, $instructor);
        $this->conDocumentos($convocatoria, ['hoja_de_vida' => true]);
        $this->postular($convocatoria, $this->aspirante($ficha));

        $this->actingAs($instructor)
            ->patchJson('/api/convocatorias/'.$convocatoria->id, [
                'documentos' => [['codigo' => 'tarjeta_profesional', 'obligatorio' => true]],
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.documentos.0', 'No se pueden agregar, quitar ni cambiar la obligatoriedad de los documentos porque la convocatoria ya tiene postulaciones.');
    }

}
