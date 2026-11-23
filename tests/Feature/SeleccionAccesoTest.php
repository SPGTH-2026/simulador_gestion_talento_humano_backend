<?php

namespace Tests\Feature;

use App\Enums\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeleccionAyudas;
use Tests\TestCase;

class SeleccionAccesoTest extends TestCase
{
    use RefreshDatabase;
    use SeleccionAyudas;

    public function test_invitado_recibe_401_en_ambas_rutas(): void
    {
        $convocatoria = $this->convocatoria($this->ficha('2896411'));

        $this->getJson('/api/seleccion/convocatorias')->assertUnauthorized();
        $this->getJson("/api/seleccion/convocatorias/{$convocatoria->id}/candidatos")->assertUnauthorized();
    }

    public function test_usuario_sin_permiso_recibe_403_en_ambas_rutas(): void
    {
        $ficha = $this->ficha('2896411');
        $convocatoria = $this->convocatoria($ficha);
        $evaluador = $this->usuario(Role::Aprendiz, 'evaluador', ['ficha_id' => $ficha->id]);
        $aspirante = $this->usuario(Role::Aspirante, null, ['ficha_id' => $ficha->id]);

        foreach ([$evaluador, $aspirante] as $actor) {
            $this->actingAs($actor)->getJson('/api/seleccion/convocatorias')->assertForbidden();
            $this->candidatos($actor, $convocatoria)->assertForbidden();
        }
    }

    public function test_correo_sin_verificar_recibe_403(): void
    {
        $ficha = $this->ficha('2896411');
        $sinVerificar = $this->usuario(Role::Aprendiz, 'seleccionador', [
            'ficha_id' => $ficha->id,
            'email_verified_at' => null,
        ]);

        $this->actingAs($sinVerificar)->getJson('/api/seleccion/convocatorias')->assertForbidden();
    }

    public function test_convocatoria_ajena_o_inexistente_responde_404(): void
    {
        $fichaA = $this->ficha('2896411');
        $fichaB = $this->ficha('2631620');
        $ajena = $this->convocatoria($fichaB);
        $this->postulacion($ajena);
        $seleccionador = $this->seleccionador($fichaA);

        $this->candidatos($seleccionador, $ajena)->assertNotFound();
        $this->actingAs($seleccionador)->getJson('/api/seleccion/convocatorias/999999/candidatos')->assertNotFound();
    }

    // ── Alcance de convocatorias ───────────────────────────────────────

    public function test_seleccionador_con_acceso_sin_convocatorias_ver_ve_solo_su_ficha_sin_borradores(): void
    {
        $fichaA = $this->ficha('2896411');
        $fichaB = $this->ficha('2631620');
        $seleccionador = $this->seleccionador($fichaA);
        $this->assertFalse($seleccionador->can('convocatorias:ver'));

        $publicada = $this->convocatoria($fichaA, 'publicada', 3);
        $cerrada = $this->convocatoria($fichaA, 'cerrada');
        $borrador = $this->convocatoria($fichaA, 'borrador');
        $ajena = $this->convocatoria($fichaB);

        $respuesta = $this->actingAs($seleccionador)->getJson('/api/seleccion/convocatorias')->assertOk();
        $ids = collect($respuesta->json('data'))->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$publicada->id, $cerrada->id], $ids);
        $this->assertNotContains($borrador->id, $ids);
        $this->assertNotContains($ajena->id, $ids);

        $fila = collect($respuesta->json('data'))->firstWhere('id', $publicada->id);
        $this->assertSame(3, $fila['vacantes']);
        $this->assertSame('publicada', $fila['estado']['codigo']);
        $this->assertSame(['id', 'codigo', 'nombre', 'cargo', 'estado', 'vacantes', 'candidatos'], array_keys($fila));
        $this->assertArrayNotHasKey('ficha_id', $fila);
    }

    public function test_el_codigo_solo_lo_ven_instructor_y_superadmin(): void
    {
        $ficha = $this->ficha('2896411');
        $convocatoria = $this->convocatoria($ficha);

        $conCodigo = [
            $this->usuario(Role::Instructor, null, ['ficha_id' => $ficha->id]),
            $this->usuario(Role::SuperAdmin),
        ];
        $sinCodigo = [
            $this->seleccionador($ficha),
            $this->usuario(Role::Aprendiz, 'general', ['ficha_id' => $ficha->id]),
        ];

        foreach ($conCodigo as $actor) {
            $fila = $this->actingAs($actor)->getJson('/api/seleccion/convocatorias')->assertOk()->json('data.0');
            $this->assertSame($convocatoria->codigo, $fila['codigo']);
        }

        foreach ($sinCodigo as $actor) {
            $fila = $this->actingAs($actor)->getJson('/api/seleccion/convocatorias')->assertOk()->json('data.0');
            $this->assertArrayHasKey('codigo', $fila);
            $this->assertNull($fila['codigo']);
        }
    }

    public function test_instructor_ve_solo_las_convocatorias_de_sus_fichas(): void
    {
        $fichaA = $this->ficha('2896411');
        $fichaB = $this->ficha('2631620');
        $instructor = $this->usuario(Role::Instructor, null, ['ficha_id' => $fichaA->id]);
        $mia = $this->convocatoria($fichaA);
        $ajena = $this->convocatoria($fichaB);

        $ids = collect($this->actingAs($instructor)->getJson('/api/seleccion/convocatorias')->assertOk()->json('data'))
            ->pluck('id')->all();

        $this->assertContains($mia->id, $ids);
        $this->assertNotContains($ajena->id, $ids);
    }

    public function test_superadmin_ve_convocatorias_y_candidatos_de_todas_las_fichas(): void
    {
        $fichaA = $this->ficha('2896411');
        $fichaB = $this->ficha('2631620');
        $superadmin = $this->usuario(Role::SuperAdmin);
        $a = $this->convocatoria($fichaA);
        $b = $this->convocatoria($fichaB);
        $postulacionB = $this->postulacion($b);

        $ids = collect($this->actingAs($superadmin)->getJson('/api/seleccion/convocatorias')->assertOk()->json('data'))
            ->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$a->id, $b->id], $ids);
        $this->assertSame($postulacionB->id, $this->candidatos($superadmin, $b)->assertOk()->json('data.0.id'));
    }

}
