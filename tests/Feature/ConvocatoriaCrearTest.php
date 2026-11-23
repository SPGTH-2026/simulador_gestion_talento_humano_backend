<?php

namespace Tests\Feature;

use App\Enums\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ConvocatoriaGestionAyudas;
use Tests\TestCase;

class ConvocatoriaCrearTest extends TestCase
{
    use RefreshDatabase;
    use ConvocatoriaGestionAyudas;

    public function test_instructor_sin_ficha_no_puede_crear(): void
    {
        $instructor = $this->usuario(Role::Instructor);

        $this->actingAs($instructor)
            ->postJson('/api/convocatorias', ['nombre' => 'Sin ficha'])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['ficha']]);
    }

    public function test_instructor_con_dos_fichas_y_sin_elegir_no_puede_crear(): void
    {
        $instructor = $this->instructor($this->ficha('3173334'), $this->ficha('2631620'));

        $this->actingAs($instructor)
            ->postJson('/api/convocatorias', ['nombre' => 'Dos fichas'])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['ficha']]);
    }

    public function test_instructor_con_dos_fichas_elige_en_cual_crea(): void
    {
        $primera = $this->ficha('3173334');
        $segunda = $this->ficha('2631620');
        $instructor = $this->instructor($primera, $segunda);

        $respuesta = $this->actingAs($instructor)
            ->postJson('/api/convocatorias', ['nombre' => 'Elegida', 'ficha_id' => $segunda->id])
            ->assertCreated();

        $this->assertSame($segunda->id, $respuesta->json('data.ficha.id'));
    }

    public function test_no_se_puede_crear_en_una_ficha_ajena(): void
    {
        $ajena = $this->ficha('2631620');
        $instructor = $this->instructor($this->ficha('3173334'));

        $this->actingAs($instructor)
            ->postJson('/api/convocatorias', ['nombre' => 'Ajena', 'ficha_id' => $ajena->id])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['ficha_id']]);
    }

    public function test_no_se_puede_mover_la_ficha_de_una_convocatoria_al_editar(): void
    {
        $ficha = $this->ficha('3173334');
        $otra = $this->ficha('2631620');
        $instructor = $this->instructor($ficha, $otra);
        $convocatoria = $this->crearConvocatoria($ficha, $instructor);

        $this->actingAs($instructor)
            ->patchJson("/api/convocatorias/{$convocatoria->id}", [
                'nombre' => 'Con ficha cambiada',
                'ficha_id' => $otra->id,
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['campos']]);
    }

    // ── Crear: caso mínimo y defaults ──────────────────────────────────

    public function test_crear_con_solo_nombre_aplica_defaults(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);

        $respuesta = $this->actingAs($instructor)
            ->postJson('/api/convocatorias', ['nombre' => 'Instructor de inglés'])
            ->assertCreated();

        $this->assertSame(sprintf('CONV-%d-001', now('America/Bogota')->year), $respuesta->json('data.codigo'));
        $this->assertSame('borrador', $respuesta->json('data.estado.codigo'));
        $this->assertSame('externo', $respuesta->json('data.tipo_convocatoria.codigo'));
        $this->assertSame('tiempo_completo', $respuesta->json('data.tipo_empleo.codigo'));
        $this->assertSame('termino_fijo', $respuesta->json('data.tipo_contrato.codigo'));
        $this->assertSame('presencial', $respuesta->json('data.modalidad.codigo'));
        $this->assertSame(1, $respuesta->json('data.vacantes'));
        $this->assertSame('3173334', $respuesta->json('data.ficha.codigo'));

        // Los obligatorios se agregan solos aunque no se envíen.
        $this->assertEqualsCanonicalizing(
            ['hoja_de_vida', 'documento_identidad', 'certificados_academicos', 'certificados_laborales'],
            $this->codigosDocumentos($respuesta->json('data.documentos')),
        );
        foreach ($respuesta->json('data.documentos') as $documento) {
            $this->assertTrue($documento['obligatorio']);
        }
    }

    public function test_crear_con_cuerpo_completo(): void
    {
        $instructor = $this->instructor($this->ficha());

        $respuesta = $this->actingAs($instructor)->postJson('/api/convocatorias', [
            'nombre' => 'Instructor de inglés',
            'cargo' => 'Instructor',
            'area' => 'Bilingüismo',
            'descripcion' => 'Buscamos instructor con nivel B2.',
            'vacantes' => 2,
            'salario_min' => 3500000,
            'salario_max' => 4200000,
            'funciones' => 'Dictar formación técnica.',
            'responsabilidades' => 'Planear y ejecutar sesiones.',
            'perfil_requerido' => 'Profesional en idiomas.',
            'formacion' => 'Profesional',
            'experiencia' => '2 años',
            'competencias' => 'Comunicación',
            'conocimientos_especificos' => 'Inglés B2',
            'tipo_convocatoria' => 'interno',
            'tipo_empleo' => 'medio_tiempo',
            'tipo_contrato' => 'prestacion_servicios',
            'modalidad' => 'remoto',
            'fecha_apertura' => '2026-10-15',
            'fecha_cierre' => '2026-11-15',
            'skills' => ['Excel avanzado', 'Power BI'],
            'documentos' => ['documento_identidad'],
        ])->assertCreated();

        $respuesta->assertJsonStructure(['data' => [
            'id', 'codigo', 'ficha' => ['id', 'codigo', 'nombre_programa'],
            'nombre', 'cargo', 'area', 'descripcion', 'estado' => ['id', 'codigo', 'nombre'],
            'vacantes', 'salario_min', 'salario_max',
            'tipo_convocatoria' => ['id', 'codigo', 'nombre'],
            'tipo_empleo' => ['id', 'codigo', 'nombre'],
            'tipo_contrato' => ['id', 'codigo', 'nombre'],
            'modalidad' => ['id', 'codigo', 'nombre'],
            'funciones', 'responsabilidades', 'perfil_requerido', 'formacion', 'experiencia',
            'competencias', 'conocimientos_especificos',
            'fecha_apertura', 'fecha_cierre', 'fecha_evaluacion', 'skills', 'documentos',
        ]]);

        $this->assertEquals(3500000.0, $respuesta->json('data.salario_min'));
        $this->assertSame('2026-10-15', $respuesta->json('data.fecha_apertura'));
        $this->assertSame('2026-11-15', $respuesta->json('data.fecha_cierre'));
        $this->assertSame('interno', $respuesta->json('data.tipo_convocatoria.codigo'));
        $this->assertSame('medio_tiempo', $respuesta->json('data.tipo_empleo.codigo'));
        $this->assertSame('prestacion_servicios', $respuesta->json('data.tipo_contrato.codigo'));
        $this->assertSame('remoto', $respuesta->json('data.modalidad.codigo'));
        $this->assertSame(['Excel avanzado', 'Power BI'], $respuesta->json('data.skills'));

        $documentos = $respuesta->json('data.documentos');
        $this->assertCount(4, $documentos);
        $this->assertEqualsCanonicalizing(
            ['hoja_de_vida', 'documento_identidad', 'certificados_academicos', 'certificados_laborales'],
            $this->codigosDocumentos($documentos),
        );
        foreach ($documentos as $documento) {
            $this->assertTrue($documento['obligatorio']);
        }
    }

    public function test_crear_con_clasificacion_por_id(): void
    {
        $instructor = $this->instructor($this->ficha());

        $respuesta = $this->actingAs($instructor)->postJson('/api/convocatorias', [
            'nombre' => 'Con id',
            'tipo_empleo_id' => $this->parametroId('tipo_empleo', 'por_horas'),
        ])->assertCreated();

        $this->assertSame('por_horas', $respuesta->json('data.tipo_empleo.codigo'));
    }

    public function test_codigo_es_secuencial_por_ficha_y_anio(): void
    {
        $fichaA = $this->ficha('3173334');
        $fichaB = $this->ficha('2631620');
        $instructorA = $this->instructor($fichaA);
        $instructorB = $this->instructor($fichaB);

        $anio = now('America/Bogota')->year;

        $primera = $this->actingAs($instructorA)->postJson('/api/convocatorias', ['nombre' => 'A1'])->assertCreated();
        $segunda = $this->actingAs($instructorA)->postJson('/api/convocatorias', ['nombre' => 'A2'])->assertCreated();
        $otraFicha = $this->actingAs($instructorB)->postJson('/api/convocatorias', ['nombre' => 'B1'])->assertCreated();

        $this->assertSame("CONV-{$anio}-001", $primera->json('data.codigo'));
        $this->assertSame("CONV-{$anio}-002", $segunda->json('data.codigo'));
        $this->assertSame("CONV-{$anio}-001", $otraFicha->json('data.codigo'));
    }

    public function test_codigo_sigue_la_secuencia_al_pasar_de_999_a_1000(): void
    {
        $ficha = $this->ficha('3173334');
        $instructor = $this->instructor($ficha);
        $anio = now('America/Bogota')->year;

        $this->crearConvocatoria($ficha, $instructor, ['codigo' => "CONV-{$anio}-999"]);

        $primera = $this->actingAs($instructor)->postJson('/api/convocatorias', ['nombre' => 'Mil'])->assertCreated();
        $segunda = $this->actingAs($instructor)->postJson('/api/convocatorias', ['nombre' => 'Mil uno'])->assertCreated();

        $this->assertSame("CONV-{$anio}-1000", $primera->json('data.codigo'));
        $this->assertSame("CONV-{$anio}-1001", $segunda->json('data.codigo'));
    }

    // ── Crear: validaciones ────────────────────────────────────────────

}
