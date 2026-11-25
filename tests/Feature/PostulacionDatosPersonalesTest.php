<?php

namespace Tests\Feature;

use App\Models\Postulacion;
use App\Models\PostulacionDatosPersonales;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\DatosPersonalesAyudas;
use Tests\Concerns\PostulacionDocumentoAyudas;
use Tests\TestCase;

/**
 * Datos personales de la postulación: se llenan en cada convocatoria (no se heredan), se bloquean al enviar, exigen el
 * representante legal a los menores de 16 a 17 años, no dejan continuar con menos de 16 y la libreta militar no se pide
 * a menores ni a mujeres.
 */
class PostulacionDatosPersonalesTest extends TestCase
{
    use DatosPersonalesAyudas;
    use PostulacionDocumentoAyudas;
    use RefreshDatabase;

    private static int $fichas = 0;

    /** @return array<string, mixed> */
    private function cuerpo(array $cambios = []): array
    {
        return $cambios + [
            'sexo' => 'hombre',
            'tipo_documento_identidad' => 'cc',
            'numero_documento' => '1012345678',
            'nombres' => 'Camilo Andrés',
            'apellidos' => 'Rojas Pérez',
            'fecha_nacimiento' => now('America/Bogota')->subYears(25)->toDateString(),
            'telefono' => '3001234567',
            'departamento' => '05',
            'municipio' => '05001',
            'direccion' => 'Calle 10 # 20-30',
            'nivel_formacion' => 'tecnologo',
            'acepta_tratamiento_datos' => true,
        ];
    }

    /** @return array<string, string> */
    private function representante(): array
    {
        return [
            'representante_nombre' => 'Marta Pérez',
            'representante_documento' => '52123456',
            'representante_telefono' => '3109876543',
            'representante_parentesco' => 'Madre',
        ];
    }

    private function borrador(array $documentos = ['hoja_de_vida' => true]): array
    {
        // Cada borrador usa su propia ficha: el código de la ficha es único.
        $ficha = $this->ficha((string) (3000000 + ++self::$fichas));
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->conDocumentos($this->convocatoriaAbierta($ficha, $this->instructor($ficha)), $documentos);
        $postulacion = $this->crearPostulacion($convocatoria, $aspirante, [
            'estado_id' => $this->parametroId('estado_postulacion', 'borrador'),
        ]);

        return [$aspirante, $postulacion];
    }

    private function guardar($aspirante, Postulacion $postulacion, array $cuerpo)
    {
        $this->conUbicacionDePrueba();

        return $this->actingAs($aspirante)->putJson("/api/postulaciones/{$postulacion->id}/datos-personales", $cuerpo);
    }

    public function test_un_mayor_de_edad_guarda_sus_datos_y_queda_sin_representante(): void
    {
        [$aspirante, $postulacion] = $this->borrador();

        $this->guardar($aspirante, $postulacion, $this->cuerpo($this->representante()))
            ->assertOk()
            ->assertJsonPath('data.completo', true)
            ->assertJsonPath('data.es_menor', false)
            ->assertJsonPath('data.datos.nombres', 'Camilo Andrés')
            ->assertJsonPath('data.datos.tipo_documento_identidad.codigo', 'cc')
            ->assertJsonPath('data.datos.representante_nombre', null);

        // El sexo también queda en el perfil: de él depende la libreta militar.
        $this->assertSame('hombre', $aspirante->perfilAspirante()->with('sexo')->first()->sexo->codigo);
    }

    public function test_cada_convocatoria_empieza_en_blanco(): void
    {
        [$aspirante, $primera] = $this->borrador();
        $this->guardar($aspirante, $primera, $this->cuerpo())->assertOk();

        $segunda = $this->crearPostulacion(
            $this->conDocumentos($this->convocatoriaAbierta($primera->convocatoria->ficha, $this->instructor($primera->convocatoria->ficha)), ['hoja_de_vida' => true]),
            $aspirante,
            ['estado_id' => $this->parametroId('estado_postulacion', 'borrador')],
        );

        $this->actingAs($aspirante)->getJson("/api/postulaciones/{$segunda->id}/datos-personales")
            ->assertOk()
            ->assertJsonPath('data.completo', false)
            ->assertJsonPath('data.datos', null);
    }

    public function test_con_menos_de_16_anos_no_puede_continuar(): void
    {
        [$aspirante, $postulacion] = $this->borrador();

        $this->guardar($aspirante, $postulacion, $this->cuerpo([
            'fecha_nacimiento' => now('America/Bogota')->subYears(15)->toDateString(),
        ] + $this->representante()))
            ->assertStatus(422)
            ->assertJsonValidationErrors('fecha_nacimiento');

        $this->assertDatabaseCount('postulacion_datos_personales', 0);
    }

    public function test_un_menor_de_16_o_17_exige_el_representante_legal(): void
    {
        [$aspirante, $postulacion] = $this->borrador();
        $fecha = now('America/Bogota')->subYears(17)->toDateString();

        $this->guardar($aspirante, $postulacion, $this->cuerpo(['fecha_nacimiento' => $fecha]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['representante_nombre', 'representante_documento', 'representante_telefono', 'representante_parentesco']);

        $this->guardar($aspirante, $postulacion, $this->cuerpo(['fecha_nacimiento' => $fecha] + $this->representante()))
            ->assertOk()
            ->assertJsonPath('data.es_menor', true)
            ->assertJsonPath('data.datos.representante_parentesco', 'Madre');
    }

    public function test_exactamente_16_anos_si_puede_continuar(): void
    {
        [$aspirante, $postulacion] = $this->borrador();

        $this->guardar($aspirante, $postulacion, $this->cuerpo([
            'fecha_nacimiento' => now('America/Bogota')->subYears(16)->toDateString(),
        ] + $this->representante()))->assertOk();
    }

    public function test_al_volverse_mayor_se_borran_los_datos_del_representante(): void
    {
        [$aspirante, $postulacion] = $this->borrador();
        $this->guardar($aspirante, $postulacion, $this->cuerpo(['fecha_nacimiento' => now('America/Bogota')->subYears(17)->toDateString()] + $this->representante()))->assertOk();

        $this->guardar($aspirante, $postulacion, $this->cuerpo($this->representante()))->assertOk();

        $this->assertNull(PostulacionDatosPersonales::firstOrFail()->representante_nombre);
    }

    public function test_exige_la_autorizacion_de_tratamiento_de_datos(): void
    {
        [$aspirante, $postulacion] = $this->borrador();

        $this->guardar($aspirante, $postulacion, $this->cuerpo(['acepta_tratamiento_datos' => false]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('acepta_tratamiento_datos');
    }

    public function test_rechaza_catalogos_invalidos(): void
    {
        [$aspirante, $postulacion] = $this->borrador();

        $this->guardar($aspirante, $postulacion, $this->cuerpo(['tipo_documento_identidad' => 'inventado']))
            ->assertStatus(422)->assertJsonValidationErrors('tipo_documento_identidad');
        $this->guardar($aspirante, $postulacion, $this->cuerpo(['nivel_formacion' => 'inventado']))
            ->assertStatus(422)->assertJsonValidationErrors('nivel_formacion');
    }

    public function test_una_postulacion_enviada_no_se_edita(): void
    {
        [$aspirante, $postulacion] = $this->borrador();
        $postulacion->update(['estado_id' => $this->parametroId('estado_postulacion', 'postulada')]);

        $this->guardar($aspirante, $postulacion, $this->cuerpo())
            ->assertStatus(422)
            ->assertJsonValidationErrors('datos_personales');
    }

    public function test_una_postulacion_retirada_si_se_edita(): void
    {
        [$aspirante, $postulacion] = $this->borrador();
        $postulacion->update(['estado_id' => $this->parametroId('estado_postulacion', 'retirada')]);

        $this->guardar($aspirante, $postulacion, $this->cuerpo())->assertOk();
    }

    public function test_la_postulacion_de_otra_persona_responde_404(): void
    {
        [, $postulacion] = $this->borrador();
        $otra = $this->aspirante($postulacion->convocatoria->ficha);

        $this->guardar($otra, $postulacion, $this->cuerpo())->assertNotFound();
        $this->actingAs($otra)->getJson("/api/postulaciones/{$postulacion->id}/datos-personales")->assertNotFound();
    }

    public function test_no_se_envia_la_postulacion_sin_datos_personales(): void
    {
        [$aspirante, $postulacion] = $this->borrador(['hoja_de_vida' => false]);

        $this->actingAs($aspirante)->postJson("/api/postulaciones/{$postulacion->id}/enviar")
            ->assertStatus(422)
            ->assertJsonValidationErrors('datos_personales');

        $this->guardar($aspirante, $postulacion, $this->cuerpo())->assertOk();

        $this->actingAs($aspirante)->postJson("/api/postulaciones/{$postulacion->id}/enviar")->assertOk();
    }

    public function test_la_libreta_no_aplica_a_un_menor_ni_a_una_mujer_y_si_a_un_hombre_mayor(): void
    {
        $caso = function (array $cambios) {
            [$aspirante, $postulacion] = $this->borrador(['libreta_militar' => true]);
            $this->guardar($aspirante, $postulacion, $this->cuerpo($cambios))->assertOk();

            $estado = $this->actingAs($aspirante)->getJson("/api/postulaciones/{$postulacion->id}/documentos")->assertOk();

            return collect($estado->json('data.documentos'))->firstWhere('parametro.codigo', 'libreta_militar')['estado'];
        };

        $this->assertSame('pendiente', $caso([]));
        $this->assertSame('no_aplica', $caso(['sexo' => 'mujer']));
        $this->assertSame('no_aplica', $caso(['fecha_nacimiento' => now('America/Bogota')->subYears(17)->toDateString()] + $this->representante()));
    }

    public function test_no_se_puede_subir_la_libreta_siendo_menor(): void
    {
        [$aspirante, $postulacion] = $this->borrador(['libreta_militar' => true]);
        $this->guardar($aspirante, $postulacion, $this->cuerpo(['fecha_nacimiento' => now('America/Bogota')->subYears(17)->toDateString()] + $this->representante()))->assertOk();

        $this->actingAs($aspirante)
            ->post("/api/postulaciones/{$postulacion->id}/documentos", ['codigo' => 'libreta_militar', 'archivo' => $this->pdf()], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('codigo');
    }

    // ── Autorización del representante (requisito por edad, sin porcentaje) ──

    private function guardarComoMenor(array $documentos = ['hoja_de_vida' => false]): array
    {
        [$aspirante, $postulacion] = $this->borrador($documentos);
        $this->guardar($aspirante, $postulacion, $this->cuerpo([
            'fecha_nacimiento' => now('America/Bogota')->subYears(17)->toDateString(),
        ] + $this->representante()))->assertOk();

        return [$aspirante, $postulacion];
    }

    private function subirAutorizacion($aspirante, Postulacion $postulacion)
    {
        return $this->actingAs($aspirante)->post(
            "/api/postulaciones/{$postulacion->id}/documentos",
            ['codigo' => 'autorizacion_representante', 'archivo' => $this->pdf()],
            ['Accept' => 'application/json'],
        );
    }

    private function autorizacionEnEstado($aspirante, Postulacion $postulacion): ?array
    {
        $estado = $this->actingAs($aspirante)->getJson("/api/postulaciones/{$postulacion->id}/documentos")->assertOk();

        return collect($estado->json('data.documentos'))->firstWhere('parametro.codigo', 'autorizacion_representante');
    }

    public function test_el_menor_ve_la_autorizacion_obligatoria_y_sin_porcentaje(): void
    {
        [$aspirante, $postulacion] = $this->guardarComoMenor();

        $autorizacion = $this->autorizacionEnEstado($aspirante, $postulacion);

        $this->assertNotNull($autorizacion);
        $this->assertTrue($autorizacion['obligatorio']);
        $this->assertNull($autorizacion['ponderacion']);
        $this->assertSame('pendiente', $autorizacion['estado']);
    }

    public function test_el_mayor_de_edad_no_ve_la_autorizacion_ni_puede_subirla(): void
    {
        [$aspirante, $postulacion] = $this->borrador();
        $this->guardar($aspirante, $postulacion, $this->cuerpo())->assertOk();

        $this->assertNull($this->autorizacionEnEstado($aspirante, $postulacion));
        $this->subirAutorizacion($aspirante, $postulacion)->assertStatus(422);
    }

    public function test_el_menor_no_puede_enviar_sin_la_autorizacion_y_si_despues_de_subirla(): void
    {
        [$aspirante, $postulacion] = $this->guardarComoMenor();

        $respuesta = $this->actingAs($aspirante)->postJson("/api/postulaciones/{$postulacion->id}/enviar")->assertStatus(422);
        $this->assertSame('autorizacion_representante', $respuesta->json('faltantes.0.codigo'));

        $this->subirAutorizacion($aspirante, $postulacion)->assertCreated();

        $this->assertSame('subido', $this->autorizacionEnEstado($aspirante, $postulacion)['estado']);
        $this->actingAs($aspirante)->postJson("/api/postulaciones/{$postulacion->id}/enviar")->assertOk();
    }

    public function test_la_autorizacion_no_cambia_el_puntaje_de_documentos(): void
    {
        [$aspirante, $postulacion] = $this->guardarComoMenor(['hoja_de_vida' => true]);

        $ids = $postulacion->convocatoria->documentos()->pluck('parametros.codigo')->all();

        $this->assertSame(['hoja_de_vida'], $ids);
    }

    public function test_el_gestor_no_ve_ni_puede_asignar_la_autorizacion_a_una_convocatoria(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);

        $catalogo = $this->actingAs($instructor)->getJson('/api/parametros?tema=tipo_documento')->assertOk()->json('data');
        $this->assertNotContains('autorizacion_representante', array_column($catalogo, 'codigo'));

        $this->actingAs($instructor)
            ->postJson('/api/convocatorias', ['nombre' => 'Con autorización', 'documentos' => [['codigo' => 'autorizacion_representante', 'ponderacion' => 10]]])
            ->assertStatus(422);
    }
}
