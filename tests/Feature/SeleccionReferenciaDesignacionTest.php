<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Evaluacion;
use App\Models\ReferenciaDocumental;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeleccionReferenciaAyudas;
use Tests\TestCase;

class SeleccionReferenciaDesignacionTest extends TestCase
{
    use RefreshDatabase;
    use SeleccionReferenciaAyudas;

    public function test_la_consulta_lista_solo_evaluaciones_elegibles_sin_datos_sensibles(): void
    {
        [$instructor, $convocatoria, $postulacion, $a, $b] = $this->casoContradictorio();
        $this->evaluacion($postulacion, 'en_proceso', 50, null);
        $this->evaluacion($postulacion, 'finalizada', null, null);

        $respuesta = $this->actingAs($instructor)->getJson($this->url($convocatoria, $postulacion))->assertOk();
        $datos = $respuesta->json('data');

        $this->assertEqualsCanonicalizing([$a->id, $b->id], collect($datos['evaluaciones'])->pluck('id')->all());
        $this->assertSame(['id', 'evaluador', 'cumplimiento', 'resultado', 'actualizada_en'], array_keys($datos['evaluaciones'][0]));
        $this->assertSame('requiere_revision', $datos['estado_documental']);
        $this->assertNull($datos['referencia']);

        foreach (['email', 'observaciones', 'documentos', 'detalles', 'perfil'] as $prohibido) {
            $this->assertStringNotContainsString($prohibido, $respuesta->getContent());
        }
    }

    // ── Designación ────────────────────────────────────────────────────

    public function test_el_listado_usa_exactamente_la_evaluacion_que_eligio_el_instructor(): void
    {
        [$instructor, $convocatoria, $postulacion, $a, $b] = $this->casoContradictorio();

        $this->assertSame('requiere_revision', $this->fila($instructor, $convocatoria, $postulacion)['estado_documental']);

        $this->designar($instructor, $convocatoria, $postulacion, $b)->assertCreated()
            ->assertJsonPath('data.estado_documental', 'no_habilitado')
            ->assertJsonPath('data.referencia.vigente', true)
            ->assertJsonPath('data.referencia.designada_por', $instructor->name);

        $fila = $this->fila($instructor, $convocatoria, $postulacion);
        $this->assertSame(['no_habilitado', 30], [$fila['estado_documental'], $fila['cumplimiento']]);
        $this->assertSame(['id', 'aspirante', 'estado_documental', 'cumplimiento'], array_slice(array_keys($fila), 0, 4));

        // También el seleccionador, que solo consulta, ve el resultado elegido.
        $seleccionador = $this->usuario(Role::Aprendiz, 'seleccionador', ['ficha_id' => $postulacion->convocatoria->ficha_id]);
        $this->assertSame('no_habilitado', $this->fila($seleccionador, $convocatoria, $postulacion)['estado_documental']);
    }

    public function test_la_justificacion_es_obligatoria_y_acotada(): void
    {
        [$instructor, $convocatoria, $postulacion, $a] = $this->casoContradictorio();

        $this->designar($instructor, $convocatoria, $postulacion, $a, null)->assertUnprocessable()->assertJsonValidationErrors('justificacion');
        $this->designar($instructor, $convocatoria, $postulacion, $a, '   ')->assertUnprocessable()->assertJsonValidationErrors('justificacion');
        $this->designar($instructor, $convocatoria, $postulacion, $a, 'corta')->assertUnprocessable()->assertJsonValidationErrors('justificacion');
        $this->designar($instructor, $convocatoria, $postulacion, $a, str_repeat('x', 501))->assertUnprocessable()->assertJsonValidationErrors('justificacion');
        $this->actingAs($instructor)->postJson($this->url($convocatoria, $postulacion), ['justificacion' => self::JUSTIFICACION])
            ->assertUnprocessable()->assertJsonValidationErrors('evaluacion_id');

        $this->assertSame(0, ReferenciaDocumental::count());

        // Se recorta antes de guardar.
        $this->designar($instructor, $convocatoria, $postulacion, $a, '  '.self::JUSTIFICACION.'  ')->assertCreated();
        $this->assertSame(self::JUSTIFICACION, ReferenciaDocumental::first()->justificacion);
    }

    public function test_rectificar_conserva_ambas_designaciones_y_vale_la_ultima(): void
    {
        [$instructor, $convocatoria, $postulacion, $a, $b] = $this->casoContradictorio();

        $this->designar($instructor, $convocatoria, $postulacion, $a)->assertCreated();
        $this->assertSame('habilitado', $this->fila($instructor, $convocatoria, $postulacion)['estado_documental']);

        $this->designar($instructor, $convocatoria, $postulacion, $b, 'Corrijo: el soporte válido es el segundo.')->assertCreated();

        $eventos = ReferenciaDocumental::query()->orderBy('id')->get();
        $this->assertCount(2, $eventos);
        $this->assertSame([$a->id, $b->id], $eventos->pluck('evaluacion_id')->all());
        $this->assertSame([90, 30], $eventos->pluck('cumplimiento')->all());
        $this->assertSame([$instructor->id, $instructor->id], $eventos->pluck('designado_por')->all());
        $this->assertSame('no_habilitado', $this->fila($instructor, $convocatoria, $postulacion)['estado_documental']);
    }

    public function test_sin_necesidad_de_referencia_se_rechaza_la_designacion(): void
    {
        $ficha = $this->ficha('2896411');
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->convocatoria($ficha);
        $postulacion = $this->postulacion($convocatoria);
        $unica = $this->evaluacion($postulacion, 'finalizada', 80, true);

        $this->designar($instructor, $convocatoria, $postulacion, $unica)
            ->assertUnprocessable()->assertJsonValidationErrors('evaluacion_id');
        $this->assertSame(0, ReferenciaDocumental::count());
    }

    public function test_una_evaluacion_no_elegible_o_una_postulacion_no_postulada_dan_error_de_validacion(): void
    {
        [$instructor, $convocatoria, $postulacion] = $this->casoContradictorio();
        $enProceso = $this->evaluacion($postulacion, 'en_proceso', 60, null);

        $this->designar($instructor, $convocatoria, $postulacion, $enProceso)
            ->assertUnprocessable()->assertJsonValidationErrors('evaluacion_id');

        // La postulación se retira entre la consulta y la designación.
        $elegible = Evaluacion::query()->where('postulacion_id', $postulacion->id)->where('estado', 'finalizada')->first();
        $postulacion->update(['estado_id' => $this->parametroId('estado_postulacion', 'retirada')]);
        $this->designar($instructor, $convocatoria, $postulacion, $elegible)
            ->assertUnprocessable()->assertJsonValidationErrors('postulacion');

        $this->assertSame(0, ReferenciaDocumental::count());
    }

    // ── Vigencia ───────────────────────────────────────────────────────

}
