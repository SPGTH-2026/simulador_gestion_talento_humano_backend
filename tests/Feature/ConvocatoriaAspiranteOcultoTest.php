<?php

namespace Tests\Feature;

use App\Models\Convocatoria;
use App\Models\Postulacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ConvocatoriaIdentificadorOcultoAyudas;
use Tests\TestCase;

class ConvocatoriaAspiranteOcultoTest extends TestCase
{
    use RefreshDatabase;
    use ConvocatoriaIdentificadorOcultoAyudas;

    public function test_las_postulaciones_del_aspirante_no_incluyen_el_codigo(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->publicada($ficha, $instructor);
        $aspirante = $this->aspirante($ficha);

        Postulacion::create([
            'convocatoria_id' => $convocatoria->id,
            'user_id' => $aspirante->id,
            'estado_id' => $this->parametroId('estado_postulacion', 'postulada'),
            'fecha_postulacion' => now(),
        ]);

        $respuesta = $this->actingAs($aspirante)
            ->getJson('/api/postulaciones')
            ->assertOk();

        $this->assertNull($respuesta->json('data.0.convocatoria.codigo'));
        $this->assertSame($convocatoria->id, $respuesta->json('data.0.convocatoria.id'));
    }

    public function test_el_estado_de_documentos_del_dueno_no_incluye_el_codigo(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->publicada($ficha, $instructor);
        $aspirante = $this->aspirante($ficha);

        $postulacion = Postulacion::create([
            'convocatoria_id' => $convocatoria->id,
            'user_id' => $aspirante->id,
            'estado_id' => $this->parametroId('estado_postulacion', 'postulada'),
            'fecha_postulacion' => now(),
        ]);

        $delDueno = $this->actingAs($aspirante)
            ->getJson("/api/postulaciones/{$postulacion->id}/documentos")
            ->assertOk();
        $this->assertNull($delDueno->json('data.convocatoria.codigo'));

        // El revisor (lista blanca) sí lo recibe.
        $delRevisor = $this->actingAs($instructor)
            ->getJson("/api/revision/postulaciones/{$postulacion->id}/documentos")
            ->assertOk();
        $this->assertSame($convocatoria->codigo, $delRevisor->json('data.convocatoria.codigo'));
    }

    // ── Campos ocultos al aspirante: ficha, creador y conteo ───────────

    /**
     * El aspirante no recibe ficha, creador ni conteo en el listado ni en el
     * detalle: son datos de gestión. Las claves pueden existir (contrato del
     * JSON) pero con valor nulo, salvo `postulantes`, que ni siquiera aparece.
     */
    public function test_el_listado_del_aspirante_no_trae_ficha_creador_ni_conteo(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $this->publicada($ficha, $instructor);
        $aspirante = $this->aspirante($ficha);

        // Un postulante para que el conteo exista si se filtrara mal.
        Postulacion::create([
            'convocatoria_id' => Convocatoria::query()->value('id'),
            'user_id' => $aspirante->id,
            'estado_id' => $this->parametroId('estado_postulacion', 'postulada'),
            'fecha_postulacion' => now(),
        ]);

        $item = $this->actingAs($aspirante)
            ->getJson('/api/convocatorias')
            ->assertOk()
            ->json('data.0');

        $this->assertNull($item['codigo']);
        $this->assertNull($item['ficha']);
        $this->assertArrayNotHasKey('creado_por', $item);
        $this->assertArrayNotHasKey('postulantes', $item);
    }

    public function test_el_detalle_del_aspirante_no_trae_ficha_creador_ni_conteo(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->publicada($ficha, $instructor);
        $aspirante = $this->aspirante($ficha);

        Postulacion::create([
            'convocatoria_id' => $convocatoria->id,
            'user_id' => $aspirante->id,
            'estado_id' => $this->parametroId('estado_postulacion', 'postulada'),
            'fecha_postulacion' => now(),
        ]);

        $data = $this->actingAs($aspirante)
            ->getJson('/api/convocatorias/'.$convocatoria->id)
            ->assertOk()
            ->json('data');

        $this->assertNull($data['codigo']);
        $this->assertNull($data['ficha']);
        $this->assertArrayNotHasKey('creado_por', $data);
        $this->assertArrayNotHasKey('postulantes', $data);

        // El instructor (gestión) sí recibe ficha y conteo, nunca el creador.
        $delInstructor = $this->actingAs($instructor)
            ->getJson('/api/convocatorias/'.$convocatoria->id)
            ->assertOk()
            ->json('data');
        $this->assertSame($ficha->codigo, $delInstructor['ficha']['codigo']);
        $this->assertSame(1, $delInstructor['postulantes']);
        $this->assertArrayNotHasKey('creado_por', $delInstructor);
    }

    public function test_las_postulaciones_no_incluyen_ficha_creador_ni_conteo(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->publicada($ficha, $instructor);
        $aspirante = $this->aspirante($ficha);

        Postulacion::create([
            'convocatoria_id' => $convocatoria->id,
            'user_id' => $aspirante->id,
            'estado_id' => $this->parametroId('estado_postulacion', 'postulada'),
            'fecha_postulacion' => now(),
        ]);

        $convocatoriaEmbebida = $this->actingAs($aspirante)
            ->getJson('/api/postulaciones')
            ->assertOk()
            ->json('data.0.convocatoria');

        $this->assertNull($convocatoriaEmbebida['codigo']);
        $this->assertArrayNotHasKey('ficha', $convocatoriaEmbebida);
        $this->assertArrayNotHasKey('creado_por', $convocatoriaEmbebida);
        $this->assertArrayNotHasKey('postulantes', $convocatoriaEmbebida);
    }

    public function test_el_portafolio_de_un_usuario_no_es_accesible_para_el_aspirante(): void
    {
        $ficha = $this->ficha();
        $aspirante = $this->aspirante($ficha);

        $this->actingAs($aspirante)
            ->getJson('/api/portafolios/'.$aspirante->id)
            ->assertForbidden();

        // El instructor (documentos:ver + alcance de la ficha) sí puede.
        $this->actingAs($this->instructor($ficha))
            ->getJson('/api/portafolios/'.$aspirante->id)
            ->assertOk();
    }

    // ── Buscador ───────────────────────────────────────────────────────

    public function test_el_buscador_por_codigo_solo_funciona_para_la_lista_blanca(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->publicada($ficha, $instructor, ['nombre' => 'Nombre normal']);

        $delGestor = $this->actingAs($this->aprendiz($ficha, 'gestor_convocatorias'))
            ->getJson('/api/convocatorias?q='.$convocatoria->codigo)
            ->assertOk();
        $this->assertCount(1, $delGestor->json('data'));

        $delAspirante = $this->actingAs($this->aspirante($ficha))
            ->getJson('/api/convocatorias?q='.$convocatoria->codigo)
            ->assertOk();
        $this->assertSame([], $delAspirante->json('data'));

        $delGeneral = $this->actingAs($this->aprendiz($ficha, 'general'))
            ->getJson('/api/convocatorias?q='.$convocatoria->codigo)
            ->assertOk();
        $this->assertSame([], $delGeneral->json('data'));
    }

}
