<?php

// Estructura de textos legales y aceptaciones (APAGADA por defecto). Todo con textos y usuarios ficticios.

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AceptacionLegal;
use App\Models\DocumentoLegal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\Concerns\SeleccionCalificacionAyudas;
use Tests\TestCase;

class AceptacionesLegalesTest extends TestCase
{
    use RefreshDatabase;
    use SeleccionCalificacionAyudas;

    private function activar(bool $si = true): void
    {
        config(['legal.aceptaciones_activas' => $si]);
    }

    private function superAdmin(): User
    {
        return $this->usuario(Role::SuperAdmin);
    }

    private function cuerpo(array $cambios = []): array
    {
        return $cambios + ['tipo' => 'privacidad', 'version' => '0.1-ficticia', 'titulo' => 'Texto ficticio de prueba', 'contenido' => 'Contenido ficticio. No es un texto jurídico.'];
    }

    /** Crea y aprueba una versión ficticia como superadmin. */
    private function aprobada(array $cambios = []): DocumentoLegal
    {
        $admin = $this->superAdmin();
        $id = $this->actingAs($admin)->postJson('/api/legal/documentos', $this->cuerpo($cambios))->assertCreated()->json('data.id');
        $this->actingAs($admin)->postJson("/api/legal/documentos/{$id}/aprobar")->assertOk();

        return DocumentoLegal::findOrFail($id);
    }

    private function aspirante(): User
    {
        static $n = 0;

        return $this->usuario(Role::Aspirante, null, ['ficha_id' => $this->ficha('9'.str_pad((string) ++$n, 6, '0', STR_PAD_LEFT))->id]);
    }

    public function test_apagada_por_defecto_todo_responde_404_y_nada_se_registra(): void
    {
        $this->assertFalse(config('legal.aceptaciones_activas'));
        $admin = $this->superAdmin();
        $this->actingAs($admin)->getJson('/api/legal/documentos')->assertNotFound();
        $this->actingAs($admin)->postJson('/api/legal/documentos', $this->cuerpo())->assertNotFound();
        $this->actingAs($this->aspirante())->getJson('/api/legal/documentos/vigentes')->assertNotFound();
        $this->assertSame(0, DocumentoLegal::count() + AceptacionLegal::count());
    }

    public function test_solo_el_superadmin_crea_edita_y_aprueba_y_sin_sesion_es_401(): void
    {
        $this->activar();
        $this->postJson('/api/legal/documentos', $this->cuerpo())->assertUnauthorized();
        $id = $this->actingAs($this->superAdmin())->postJson('/api/legal/documentos', $this->cuerpo())->assertCreated()
            ->assertJsonPath('data.estado', 'borrador')->assertJsonPath('data.huella', null)->json('data.id');

        $ficha = $this->ficha('9000001');
        $noAdmin = [$this->aspirante(), $this->seleccionador($ficha), $this->usuario(Role::Instructor, null, ['ficha_id' => $ficha->id])];
        foreach ($noAdmin as $u) {
            $this->actingAs($u)->postJson('/api/legal/documentos', $this->cuerpo(['version' => '9']))->assertForbidden();
            $this->actingAs($u)->putJson("/api/legal/documentos/{$id}", $this->cuerpo())->assertForbidden();
            $this->actingAs($u)->postJson("/api/legal/documentos/{$id}/aprobar")->assertForbidden();
            $this->actingAs($u)->getJson('/api/legal/documentos')->assertForbidden();
        }
        $this->assertSame(DocumentoLegal::BORRADOR, DocumentoLegal::find($id)->estado);
    }

    public function test_aprobar_sella_la_version_con_su_huella_y_deja_quien_la_aprobo_con_la_nota_de_que_no_es_certificacion_juridica(): void
    {
        $this->activar();
        $admin = $this->superAdmin();
        $id = $this->actingAs($admin)->postJson('/api/legal/documentos', $this->cuerpo())->json('data.id');
        $res = $this->actingAs($admin)->postJson("/api/legal/documentos/{$id}/aprobar")->assertOk()
            ->assertJsonPath('data.estado', 'aprobado')->assertJsonPath('data.aprobado_por', $admin->name);
        $this->assertSame(DocumentoLegal::calcularHuella('Texto ficticio de prueba', 'Contenido ficticio. No es un texto jurídico.'), $res->json('data.huella'));
        $this->assertStringContainsString('no equivale a una certificación jurídica', $res->json('data.nota'));
        $this->assertSame($admin->email, DocumentoLegal::find($id)->aprobado_por_correo);
        // Aprobar otra vez no es válido ni cambia nada.
        $antes = DocumentoLegal::find($id)->aprobado_en;
        $this->actingAs($admin)->postJson("/api/legal/documentos/{$id}/aprobar")->assertUnprocessable();
        $this->assertEquals($antes, DocumentoLegal::find($id)->aprobado_en);
    }

    public function test_una_version_aprobada_es_inmutable_por_la_api_y_por_el_modelo(): void
    {
        $this->activar();
        $doc = $this->aprobada();
        $this->actingAs($this->superAdmin())->putJson("/api/legal/documentos/{$doc->id}", $this->cuerpo(['contenido' => 'Cambio no permitido.']))->assertUnprocessable();
        $this->assertSame('Contenido ficticio. No es un texto jurídico.', $doc->fresh()->contenido);

        try {
            $doc->fresh()->update(['contenido' => 'Cambio directo']);
            $this->fail('El modelo debía impedirlo.');
        } catch (LogicException) {
            $this->assertSame('Contenido ficticio. No es un texto jurídico.', $doc->fresh()->contenido);
        }
        $this->expectException(LogicException::class);
        $doc->fresh()->delete();
    }

    public function test_un_borrador_si_se_puede_editar_y_no_se_repite_tipo_y_version(): void
    {
        $this->activar();
        $admin = $this->superAdmin();
        $id = $this->actingAs($admin)->postJson('/api/legal/documentos', $this->cuerpo())->json('data.id');
        $this->actingAs($admin)->putJson("/api/legal/documentos/{$id}", $this->cuerpo(['titulo' => 'Título corregido']))->assertOk()->assertJsonPath('data.titulo', 'Título corregido');
        $this->actingAs($admin)->postJson('/api/legal/documentos', $this->cuerpo())->assertUnprocessable()->assertJsonValidationErrors('version');
        $this->actingAs($admin)->postJson('/api/legal/documentos', $this->cuerpo(['tipo' => 'terminos']))->assertCreated();
        $this->actingAs($admin)->postJson('/api/legal/documentos', $this->cuerpo(['tipo' => 'otro', 'version' => '2']))->assertUnprocessable()->assertJsonValidationErrors('tipo');
    }

    public function test_no_se_acepta_un_borrador_y_la_aceptacion_es_idempotente_por_usuario_y_version(): void
    {
        $this->activar();
        $admin = $this->superAdmin();
        $borrador = $this->actingAs($admin)->postJson('/api/legal/documentos', $this->cuerpo(['version' => 'b1']))->json('data.id');
        $persona = $this->aspirante();
        $this->actingAs($persona)->postJson("/api/legal/documentos/{$borrador}/aceptar")->assertUnprocessable();
        // Ni siquiera el superadmin acepta un borrador.
        $this->actingAs($admin)->postJson("/api/legal/documentos/{$borrador}/aceptar")->assertUnprocessable();
        $this->assertSame(0, AceptacionLegal::count());

        $doc = $this->aprobada(['version' => '1.0']);
        $primera = $this->actingAs($persona)->postJson("/api/legal/documentos/{$doc->id}/aceptar")->assertCreated()->json('data');
        $segunda = $this->actingAs($persona)->postJson("/api/legal/documentos/{$doc->id}/aceptar")->assertOk()->json('data');
        $this->assertSame($primera['id'], $segunda['id']);
        $this->assertSame(1, AceptacionLegal::count());
        $this->assertSame($doc->huella, $primera['huella']);
        // Otra persona acepta la misma versión por su cuenta: es otra aceptación.
        $this->actingAs($this->aspirante())->postJson("/api/legal/documentos/{$doc->id}/aceptar")->assertCreated();
        $this->assertSame(2, AceptacionLegal::count());
    }

    public function test_nadie_acepta_por_otro_usuario_aunque_lo_envie_en_el_cuerpo_y_no_se_guardan_ip_ni_navegador(): void
    {
        $this->activar();
        $doc = $this->aprobada();
        $yo = $this->aspirante();
        $otro = $this->aspirante();
        $this->actingAs($yo)->postJson("/api/legal/documentos/{$doc->id}/aceptar", ['user_id' => $otro->id, 'usuario_correo' => $otro->email])->assertCreated();
        $fila = AceptacionLegal::firstOrFail();
        $this->assertSame($yo->id, $fila->user_id);
        $this->assertSame($yo->email, $fila->usuario_correo);
        $this->assertSame(0, AceptacionLegal::where('user_id', $otro->id)->count());
        $this->assertEqualsCanonicalizing(
            ['id', 'documento_legal_id', 'user_id', 'usuario_nombre', 'usuario_correo', 'huella_aceptada', 'aceptado_en', 'created_at'],
            array_keys($fila->getAttributes()),
        );
        // La lista propia solo trae las suyas.
        $this->actingAs($otro)->getJson('/api/legal/aceptaciones')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($yo)->getJson('/api/legal/aceptaciones')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.version', '0.1-ficticia');
    }

    public function test_las_vigentes_traen_la_ultima_aprobada_de_cada_tipo_sin_borradores_y_dicen_si_ya_la_acepte(): void
    {
        $this->activar();
        $admin = $this->superAdmin();
        $this->aprobada(['version' => '1.0']);
        $nueva = $this->aprobada(['version' => '2.0']);
        $this->actingAs($admin)->postJson('/api/legal/documentos', $this->cuerpo(['tipo' => 'terminos', 'version' => 'borrador']))->assertCreated();
        $persona = $this->aspirante();

        $lista = $this->actingAs($persona)->getJson('/api/legal/documentos/vigentes')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.version', '2.0')->assertJsonPath('data.0.aceptada_por_mi', false);
        $this->assertSame($nueva->id, $lista->json('data.0.id'));
        $this->actingAs($persona)->postJson("/api/legal/documentos/{$nueva->id}/aceptar")->assertCreated();
        $this->actingAs($persona)->getJson('/api/legal/documentos/vigentes')->assertJsonPath('data.0.aceptada_por_mi', true);
    }

    public function test_la_evidencia_se_conserva_si_la_cuenta_se_desactiva_o_se_elimina_y_la_aceptacion_no_se_modifica(): void
    {
        $this->activar();
        $doc = $this->aprobada();
        $persona = $this->aspirante();
        $this->actingAs($persona)->postJson("/api/legal/documentos/{$doc->id}/aceptar")->assertCreated();
        $nombre = $persona->name;
        $correo = $persona->email;

        $persona->forceFill(['active' => false])->save();
        $this->assertSame(1, AceptacionLegal::where('user_id', $persona->id)->count());

        $persona->delete();
        $fila = AceptacionLegal::firstOrFail();
        $this->assertNull($fila->user_id);
        $this->assertSame($nombre, $fila->usuario_nombre);
        $this->assertSame($correo, $fila->usuario_correo);
        $this->assertSame($doc->huella, $fila->huella_aceptada);

        // La aprobación también conserva quién aprobó aunque esa cuenta se borre.
        User::where('email', $doc->aprobado_por_correo)->first()->delete();
        $this->assertNull($doc->fresh()->aprobado_por);
        $this->assertNotNull($doc->fresh()->aprobado_por_nombre);

        $this->expectException(LogicException::class);
        $fila->forceFill(['usuario_nombre' => 'Otro'])->save();
    }

    public function test_una_version_con_aceptaciones_no_se_puede_borrar_en_la_base(): void
    {
        $this->activar();
        $doc = $this->aprobada();
        $this->actingAs($this->aspirante())->postJson("/api/legal/documentos/{$doc->id}/aceptar")->assertCreated();
        $this->expectException(\Illuminate\Database\QueryException::class);
        \Illuminate\Support\Facades\DB::table('documentos_legales')->where('id', $doc->id)->delete();
    }
}
