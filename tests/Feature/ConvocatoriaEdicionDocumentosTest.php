<?php

namespace Tests\Feature;

use App\Models\Convocatoria;
use App\Models\Parametro;
use App\Models\ParametroTema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ConvocatoriaGestionAyudas;
use Tests\TestCase;

class ConvocatoriaEdicionDocumentosTest extends TestCase
{
    use RefreshDatabase;
    use ConvocatoriaGestionAyudas;

    public function test_documentos_obligatorios_se_agregan_siempre(): void
    {
        $instructor = $this->instructor($this->ficha());

        // Aunque el gestor no seleccione nada, los obligatorios siempre van.
        $sinDocs = $this->actingAs($instructor)->postJson('/api/convocatorias', [
            'nombre' => 'Sin documentos',
        ])->assertCreated();

        $this->assertEqualsCanonicalizing(
            ['hoja_de_vida', 'documento_identidad', 'certificados_academicos', 'certificados_laborales'],
            $this->codigosDocumentos($sinDocs->json('data.documentos')),
        );
        $this->assertTrue(
            collect($sinDocs->json('data.documentos'))->every(fn ($d) => $d['obligatorio'] === true)
        );

        // Si el gestor los reenvía no se duplican ni cambian el resultado.
        $conDocs = $this->actingAs($instructor)->postJson('/api/convocatorias', [
            'nombre' => 'Con documentos',
            'documentos' => ['hoja_de_vida', 'certificados_laborales'],
        ])->assertCreated();

        $this->assertEqualsCanonicalizing(
            ['hoja_de_vida', 'documento_identidad', 'certificados_academicos', 'certificados_laborales'],
            $this->codigosDocumentos($conDocs->json('data.documentos')),
        );
    }

    public function test_documentos_opcionales_quedan_obligatorios_al_incluirlos(): void
    {
        $instructor = $this->instructor($this->ficha());

        // libreta_militar y tarjeta_profesional se agregan solo si el gestor las
        // incluye, y quedan como obligatorias (regla de config/convocatorias.php).
        $respuesta = $this->actingAs($instructor)->postJson('/api/convocatorias', [
            'nombre' => 'Con libreta y tarjeta',
            'documentos' => ['libreta_militar', 'tarjeta_profesional'],
        ])->assertCreated();

        $documentos = collect($respuesta->json('data.documentos'))->keyBy('codigo');

        $this->assertTrue($documentos['libreta_militar']['obligatorio']);
        $this->assertTrue($documentos['tarjeta_profesional']['obligatorio']);

        // Los obligatorios fijos siguen presentes.
        $this->assertEqualsCanonicalizing(
            [
                'hoja_de_vida', 'documento_identidad', 'certificados_academicos',
                'certificados_laborales', 'libreta_militar', 'tarjeta_profesional',
            ],
            $this->codigosDocumentos($respuesta->json('data.documentos')),
        );
    }

    public function test_documento_nuevo_del_catalogo_queda_obligatorio_y_conserva_su_porcentaje(): void
    {
        $instructor = $this->instructor($this->ficha());

        // Documento nuevo del catálogo (sin código), como una especialización.
        $tema = ParametroTema::where('codigo', 'tipo_documento')->firstOrFail();
        $nuevo = Parametro::create([
            'tema_id' => $tema->id,
            'codigo' => null,
            'nombre' => 'Especialización en SST',
            'orden' => 99,
            'activo' => true,
        ]);

        // Los 4 fijos + el nuevo, con porcentajes que suman 100 %.
        $respuesta = $this->actingAs($instructor)->postJson('/api/convocatorias', [
            'nombre' => 'Con documento nuevo',
            'documentos' => [
                ['codigo' => 'hoja_de_vida', 'ponderacion' => 20],
                ['codigo' => 'documento_identidad', 'ponderacion' => 20],
                ['codigo' => 'certificados_academicos', 'ponderacion' => 20],
                ['codigo' => 'certificados_laborales', 'ponderacion' => 20],
                ['id' => $nuevo->id, 'ponderacion' => 20],
            ],
        ])->assertCreated();

        $documentos = collect($respuesta->json('data.documentos'))->keyBy('id');

        // El documento nuevo cuenta como obligatorio, igual que los fijos.
        $this->assertTrue($documentos[$nuevo->id]['obligatorio']);
        $this->assertEquals(20.0, (float) $documentos[$nuevo->id]['ponderacion']);

        $pivote = Convocatoria::find($respuesta->json('data.id'))
            ->documentos()
            ->where('parametros.id', $nuevo->id)
            ->firstOrFail()
            ->pivot;

        $this->assertTrue((bool) $pivote->obligatorio);
        $this->assertEquals(20.0, (float) $pivote->ponderacion);
    }

    public function test_edicion_no_puede_quitar_los_obligatorios(): void
    {
        $instructor = $this->instructor($this->ficha());

        $creada = $this->actingAs($instructor)->postJson('/api/convocatorias', [
            'nombre' => 'Docs',
            'documentos' => ['hoja_de_vida', 'certificados_laborales'],
        ])->assertCreated();

        // El gestor envía una lista vacía, pero los obligatorios se reinyectan.
        $respuesta = $this->actingAs($instructor)->patchJson('/api/convocatorias/'.$creada->json('data.id'), [
            'documentos' => [],
        ])->assertOk();

        $this->assertEqualsCanonicalizing(
            ['hoja_de_vida', 'documento_identidad', 'certificados_academicos', 'certificados_laborales'],
            $this->codigosDocumentos($respuesta->json('data.documentos')),
        );
        $this->assertSame(4, Convocatoria::find($creada->json('data.id'))->documentos()->count());
    }

    public function test_edicion_valida_salario_contra_el_valor_actual(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->crearConvocatoria($ficha, $instructor, ['salario_min' => 5000000]);

        $this->actingAs($instructor)
            ->patchJson('/api/convocatorias/'.$convocatoria->id, ['salario_max' => 1000000])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['salario_max']]);
    }

    public function test_edicion_nombre_vacio_responde_422(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->crearConvocatoria($ficha, $instructor);

        $this->actingAs($instructor)
            ->patchJson('/api/convocatorias/'.$convocatoria->id, ['nombre' => ''])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['nombre']]);
    }

    public function test_edicion_fuera_de_alcance_responde_404(): void
    {
        $fichaA = $this->ficha('3173334');
        $fichaB = $this->ficha('2631620');
        $instructorA = $this->instructor($fichaA);
        $instructorB = $this->instructor($fichaB);
        $ajena = $this->crearConvocatoria($fichaB, $instructorB, ['codigo' => 'B-1']);

        $this->actingAs($instructorA)
            ->patchJson('/api/convocatorias/'.$ajena->id, ['nombre' => 'X'])
            ->assertNotFound();
    }

    public function test_aspirante_no_puede_editar(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->crearConvocatoria($ficha, $instructor);

        $this->actingAs($aspirante)
            ->patchJson('/api/convocatorias/'.$convocatoria->id, ['nombre' => 'X'])
            ->assertForbidden();
    }

    // ── Retiro del endpoint de fichas ──────────────────────────────────

    public function test_endpoint_de_fichas_retirado(): void
    {
        $instructor = $this->instructor($this->ficha());

        $this->actingAs($instructor)->getJson('/api/convocatorias/fichas')->assertNotFound();
    }

    // ── Buscador ?q= ───────────────────────────────────────────────────

}
