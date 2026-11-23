<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ConvocatoriaEdicionBloqueadaAyudas;
use Tests\TestCase;

class ConvocatoriaEdicionPermitidaTest extends TestCase
{
    use RefreshDatabase;
    use ConvocatoriaEdicionBloqueadaAyudas;

    public function test_nombre_textos_y_fecha_evaluacion_se_editan_con_postulaciones(): void
    {
        [$instructor, $convocatoria] = $this->publicadaConPostulada();

        $this->actingAs($instructor)
            ->patchJson('/api/convocatorias/'.$convocatoria->id, [
                'nombre' => 'Nombre editado',
                'descripcion' => 'Descripción editada',
                'funciones' => 'Funciones editadas',
                'responsabilidades' => 'Responsabilidades editadas',
                'perfil_requerido' => 'Perfil editado',
                'formacion' => 'Formación editada',
                'experiencia' => 'Experiencia editada',
                'competencias' => 'Competencias editadas',
                'conocimientos_especificos' => 'Conocimientos editados',
                'fecha_evaluacion' => now('America/Bogota')->addDays(30)->toDateString(),
            ])
            ->assertOk();

        $fresca = $convocatoria->fresh();
        $this->assertSame('Nombre editado', $fresca->nombre);
        $this->assertSame('Descripción editada', $fresca->descripcion);
        $this->assertSame(
            now('America/Bogota')->addDays(30)->toDateString(),
            $fresca->fecha_evaluacion->toDateString()
        );
    }

    public function test_reenviar_formulario_completo_con_mismos_valores_devuelve_200(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->publicable($ficha, $instructor);
        $this->conSkills($convocatoria, ['PHP', 'Laravel']);
        $this->documentoExtra('documento_adicional');
        $this->conDocumentos($convocatoria, ['hoja_de_vida' => true, 'documento_adicional' => false]);
        $this->postular($convocatoria, $this->aspirante($ficha));

        $payload = $this->payloadCompleto($convocatoria);
        // Mismo conjunto de skills, otro orden y otras mayúsculas: no debe bloquear.
        $payload['skills'] = array_reverse(array_map('mb_strtoupper', $payload['skills']));

        $this->actingAs($instructor)
            ->patchJson('/api/convocatorias/'.$convocatoria->id, $payload)
            ->assertOk();

        $fresca = $convocatoria->fresh();
        $this->assertSame('Instructor', $fresca->cargo);
        $this->assertSame(2, $fresca->vacantes);
    }

    public function test_reenvio_identico_no_reescribe_skills_ni_documentos(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->publicable($ficha, $instructor);
        $this->conSkills($convocatoria, ['PHP', 'Laravel']);
        $this->conDocumentos($convocatoria, ['hoja_de_vida' => true]);
        $this->postular($convocatoria, $this->aspirante($ficha));

        $skillsAntes = $convocatoria->skills()->orderBy('id')->pluck('id')->all();
        $documentosAntes = $convocatoria->documentos()->get()
            ->mapWithKeys(fn ($d) => [(int) $d->id => (bool) $d->pivot->obligatorio])
            ->all();

        $this->actingAs($instructor)
            ->patchJson('/api/convocatorias/'.$convocatoria->id, $this->payloadCompleto($convocatoria))
            ->assertOk();

        // Las filas de skills NO se borran/crean de nuevo: mismos ids.
        $skillIdsDespues = $convocatoria->fresh()->skills()->orderBy('id')->pluck('id')->all();
        $this->assertSame($skillsAntes, $skillIdsDespues);

        // El pivote de documentos conserva los mismos ids y obligatoriedad.
        $documentosDespues = $convocatoria->fresh()->documentos()->get()
            ->mapWithKeys(fn ($p) => [(int) $p->id => (bool) $p->pivot->obligatorio])
            ->all();
        $this->assertSame($documentosAntes, $documentosDespues);
    }

    // ── fecha_cierre: solo ampliar ─────────────────────────────────────

    public function test_fecha_cierre_ampliada_devuelve_200(): void
    {
        [$instructor, $convocatoria] = $this->publicadaConPostulada();

        $nueva = $convocatoria->fecha_cierre->copy()->addDays(5)->toDateString();

        $this->actingAs($instructor)
            ->patchJson('/api/convocatorias/'.$convocatoria->id, ['fecha_cierre' => $nueva])
            ->assertOk();

        $this->assertSame($nueva, $convocatoria->fresh()->fecha_cierre->toDateString());
    }

    public function test_fecha_cierre_igual_a_la_actual_devuelve_200(): void
    {
        [$instructor, $convocatoria] = $this->publicadaConPostulada();

        $this->actingAs($instructor)
            ->patchJson('/api/convocatorias/'.$convocatoria->id, [
                'fecha_cierre' => $convocatoria->fecha_cierre->toDateString(),
            ])
            ->assertOk();
    }

    public function test_fecha_cierre_reducida_responde_422(): void
    {
        [$instructor, $convocatoria] = $this->publicadaConPostulada();

        $reducida = $convocatoria->fecha_cierre->copy()->subDays(3)->toDateString();

        $this->actingAs($instructor)
            ->patchJson('/api/convocatorias/'.$convocatoria->id, ['fecha_cierre' => $reducida])
            ->assertStatus(422)
            ->assertJsonPath('errors.fecha_cierre.0', 'La fecha de cierre solo se puede ampliar.');
    }

    // ── Estados que siguen bloqueados ──────────────────────────────────

    public function test_en_proceso_sigue_respondiendo_422(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->publicable($ficha, $instructor);
        $convocatoria->forceFill(['estado_id' => $this->parametroId('estado_convocatoria', 'en_proceso')])->save();

        $this->actingAs($instructor)
            ->patchJson('/api/convocatorias/'.$convocatoria->id, ['nombre' => 'X'])
            ->assertStatus(422)
            ->assertJsonPath('errors.estado.0', 'No se puede editar una convocatoria en proceso.');
    }

    public function test_cerrada_sigue_respondiendo_422(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->publicable($ficha, $instructor);
        $convocatoria->forceFill(['estado_id' => $this->parametroId('estado_convocatoria', 'cerrada')])->save();

        $this->actingAs($instructor)
            ->patchJson('/api/convocatorias/'.$convocatoria->id, ['nombre' => 'X'])
            ->assertStatus(422)
            ->assertJsonPath('errors.estado.0', 'No se puede editar una convocatoria cerrada.');
    }

    // ── Utilidad ───────────────────────────────────────────────────────

}
