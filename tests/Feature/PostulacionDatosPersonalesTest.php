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
            'representante_tipo_documento' => 'cc',
            'representante_documento' => '52123456',
            'representante_telefono' => '3109876543',
            'representante_correo' => 'marta.perez@example.com',
            'representante_parentesco' => 'Madre',
            'representante_conoce' => true,
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
            ->assertJsonPath('data.datos.sexo.codigo', 'hombre')
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
            ->assertJsonValidationErrors([
                'representante_nombre', 'representante_tipo_documento', 'representante_documento', 'representante_telefono',
                'representante_correo', 'representante_parentesco', 'representante_conoce',
            ]);

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

        $registro = PostulacionDatosPersonales::firstOrFail();
        $this->assertNull($registro->representante_nombre);
        $this->assertNull($registro->representante_correo);
        $this->assertNull($registro->representante_tipo_documento_id);
        $this->assertFalse($registro->representante_conoce);
    }

    public function test_los_datos_se_guardan_sin_autorizar_pero_no_cuentan_como_completos_ni_dejan_enviar(): void
    {
        [$aspirante, $postulacion] = $this->borrador(['hoja_de_vida' => false]);

        $this->guardar($aspirante, $postulacion, $this->cuerpo(['acepta_tratamiento_datos' => false]))
            ->assertOk()
            ->assertJsonPath('data.completo', false)
            ->assertJsonPath('data.datos.acepta_tratamiento_datos', false)
            ->assertJsonPath('data.datos.nombres', 'Camilo Andrés');

        $this->actingAs($aspirante)->postJson("/api/postulaciones/{$postulacion->id}/enviar")
            ->assertStatus(422)
            ->assertJsonValidationErrors('datos_personales');

        // Al marcar la autorización queda completo y ya se puede enviar.
        $this->guardar($aspirante, $postulacion, $this->cuerpo(['acepta_tratamiento_datos' => true]))
            ->assertOk()
            ->assertJsonPath('data.completo', true);
        $this->actingAs($aspirante)->postJson("/api/postulaciones/{$postulacion->id}/enviar")->assertOk();
    }

    public function test_la_autorizacion_dada_no_se_revoca_al_guardar_de_nuevo(): void
    {
        [$aspirante, $postulacion] = $this->borrador();
        $this->guardar($aspirante, $postulacion, $this->cuerpo(['acepta_tratamiento_datos' => true]))->assertOk();
        $fecha = PostulacionDatosPersonales::firstOrFail()->acepta_tratamiento_datos_at;

        $this->guardar($aspirante, $postulacion, $this->cuerpo(['nombres' => 'Otro nombre', 'acepta_tratamiento_datos' => false]))
            ->assertOk()
            ->assertJsonPath('data.completo', true);

        $this->assertEquals($fecha, PostulacionDatosPersonales::firstOrFail()->acepta_tratamiento_datos_at);
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

    // ── Representante legal de un menor: datos para localizarlo (la autorización se firma en persona) ──

    private function guardarComoMenor(array $documentos = ['hoja_de_vida' => false], array $cambios = []): array
    {
        [$aspirante, $postulacion] = $this->borrador($documentos);
        $this->guardar($aspirante, $postulacion, $this->cuerpo([
            'fecha_nacimiento' => now('America/Bogota')->subYears(17)->toDateString(),
        ] + $cambios + $this->representante()))->assertOk();

        return [$aspirante, $postulacion];
    }

    public function test_un_menor_no_ve_ni_necesita_un_documento_de_autorizacion(): void
    {
        [$aspirante, $postulacion] = $this->guardarComoMenor(['hoja_de_vida' => true]);

        $documentos = $this->actingAs($aspirante)->getJson("/api/postulaciones/{$postulacion->id}/documentos")->assertOk()->json('data.documentos');

        $this->assertSame(['hoja_de_vida'], array_column(array_column($documentos, 'parametro'), 'codigo'));
        $this->assertSame('pendiente', $documentos[0]['estado']);
    }

    public function test_un_menor_envia_su_postulacion_sin_subir_ninguna_autorizacion(): void
    {
        [$aspirante, $postulacion] = $this->guardarComoMenor(['hoja_de_vida' => false], ['acepta_tratamiento_datos' => true]);

        $this->actingAs($aspirante)->postJson("/api/postulaciones/{$postulacion->id}/enviar")
            ->assertOk()
            ->assertJsonPath('data.estado.codigo', 'postulada');
    }

    public function test_el_representante_se_guarda_con_todos_sus_datos_de_contacto(): void
    {
        [$aspirante, $postulacion] = $this->guardarComoMenor(['hoja_de_vida' => false], [
            'representante_telefono_alterno' => '6044445555',
            'representante_departamento' => '05',
            'representante_municipio' => '05001',
        ]);

        $this->actingAs($aspirante)->getJson("/api/postulaciones/{$postulacion->id}/datos-personales")
            ->assertOk()
            ->assertJsonPath('data.datos.representante_tipo_documento.codigo', 'cc')
            ->assertJsonPath('data.datos.representante_correo', 'marta.perez@example.com')
            ->assertJsonPath('data.datos.representante_telefono_alterno', '6044445555')
            ->assertJsonPath('data.datos.representante_municipio.nombre', 'Medellín')
            ->assertJsonPath('data.datos.representante_conoce', true);
    }

    public function test_el_correo_del_representante_debe_ser_valido(): void
    {
        [$aspirante, $postulacion] = $this->borrador();

        $this->guardar($aspirante, $postulacion, $this->cuerpo([
            'fecha_nacimiento' => now('America/Bogota')->subYears(17)->toDateString(),
            'representante_correo' => 'no-es-un-correo',
        ] + $this->representante()))
            ->assertStatus(422)->assertJsonValidationErrors('representante_correo');
    }

    public function test_el_representante_no_puede_identificarse_con_tarjeta_de_identidad(): void
    {
        [$aspirante, $postulacion] = $this->borrador();

        $this->guardar($aspirante, $postulacion, $this->cuerpo([
            'fecha_nacimiento' => now('America/Bogota')->subYears(17)->toDateString(),
            'representante_tipo_documento' => 'ti',
        ] + $this->representante()))
            ->assertStatus(422)->assertJsonValidationErrors('representante_tipo_documento');
    }

    public function test_la_residencia_del_representante_es_opcional_pero_va_completa(): void
    {
        [$aspirante, $postulacion] = $this->borrador();
        $base = $this->cuerpo(['fecha_nacimiento' => now('America/Bogota')->subYears(17)->toDateString()] + $this->representante());

        $this->guardar($aspirante, $postulacion, $base + ['representante_departamento' => '05'])
            ->assertStatus(422)->assertJsonValidationErrors('representante_municipio');
        // Cartagena (13001) no es de Antioquia (05).
        $this->guardar($aspirante, $postulacion, $base + ['representante_departamento' => '05', 'representante_municipio' => '13001'])
            ->assertStatus(422)->assertJsonValidationErrors('representante_municipio');

        $this->guardar($aspirante, $postulacion, $base)->assertOk();
    }

    /** Datos de un menor guardados ANTES de que existieran los campos nuevos del representante (sin tipo, correo ni declaración). */
    private function datosDeMenorSinTerminar(Postulacion $postulacion): void
    {
        $this->conDatosPersonales($postulacion, [
            'fecha_nacimiento' => now('America/Bogota')->subYears(17)->toDateString(),
            'representante_nombre' => 'Marta Pérez',
            'representante_documento' => '52123456',
            'representante_telefono' => '3109876543',
            'representante_parentesco' => 'Madre',
        ]);
    }

    public function test_un_menor_con_el_representante_incompleto_no_puede_enviar(): void
    {
        [$aspirante, $postulacion] = $this->borrador(['hoja_de_vida' => false]);
        $this->datosDeMenorSinTerminar($postulacion);

        $this->actingAs($aspirante)->postJson("/api/postulaciones/{$postulacion->id}/enviar")
            ->assertStatus(422)
            ->assertJsonPath('errors.datos_personales.0', 'Completa los datos de tu representante legal y confirma que conoce tu postulación antes de enviarla.');

        $this->assertSame('borrador', $postulacion->fresh()->estado->codigo);
    }

    public function test_volver_a_postularse_tambien_exige_los_datos_personales_completos(): void
    {
        [$aspirante, $postulacion] = $this->borrador(['hoja_de_vida' => false]);
        $postulacion->update(['estado_id' => $this->parametroId('estado_postulacion', 'retirada')]);
        $reactivar = fn () => $this->actingAs($aspirante)->patchJson("/api/postulaciones/{$postulacion->id}/estado", ['estado' => 'postulada']);

        // Sin datos personales.
        $reactivar()->assertStatus(422)->assertJsonPath('errors.datos_personales.0', 'Debes llenar tus datos personales antes de enviar la postulación.');

        // Datos de un menor sin terminar.
        $this->datosDeMenorSinTerminar($postulacion);
        $reactivar()->assertStatus(422)
            ->assertJsonPath('errors.datos_personales.0', 'Completa los datos de tu representante legal y confirma que conoce tu postulación antes de enviarla.');
        $this->assertSame('retirada', $postulacion->fresh()->estado->codigo);

        // Completados desde el formulario, ya puede volver a postularse.
        $this->guardar($aspirante, $postulacion, $this->cuerpo([
            'fecha_nacimiento' => now('America/Bogota')->subYears(17)->toDateString(),
            'acepta_tratamiento_datos' => true,
        ] + $this->representante()))->assertOk();
        $reactivar()->assertOk();
        $this->assertSame('postulada', $postulacion->fresh()->estado->codigo);
    }

    public function test_quien_revisa_lee_los_datos_con_alcance_por_ficha_y_sin_borradores(): void
    {
        [$aspirante, $postulacion] = $this->guardarComoMenor(['hoja_de_vida' => false]);
        $ficha = $postulacion->convocatoria->ficha;
        $revisor = $this->instructor($ficha);
        $otraFicha = $this->instructor($this->ficha('2631620'));
        $url = "/api/revision/postulaciones/{$postulacion->id}/datos-personales";

        // En borrador no es un postulante todavía.
        $this->actingAs($revisor)->getJson($url)->assertNotFound();

        $postulacion->update(['estado_id' => $this->parametroId('estado_postulacion', 'postulada')]);

        $this->actingAs($revisor)->getJson($url)
            ->assertOk()
            ->assertJsonPath('data.es_menor', true)
            ->assertJsonPath('data.datos.representante_nombre', 'Marta Pérez')
            ->assertJsonPath('data.datos.representante_correo', 'marta.perez@example.com');
        // Fuera de su ficha no existe; y el propio aspirante no usa la ruta de revisión.
        $this->actingAs($otraFicha)->getJson($url)->assertNotFound();
        $this->actingAs($aspirante)->getJson($url)->assertForbidden();
    }
}
