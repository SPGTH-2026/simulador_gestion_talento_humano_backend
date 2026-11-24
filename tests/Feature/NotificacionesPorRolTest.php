<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Ficha;
use App\Models\Notificacion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\EvaluacionAyudas;
use Tests\TestCase;

/**
 * Quién recibe cada aviso según su rol y subrol (ver la tabla de App\Services\Notificaciones\Avisos).
 */
class NotificacionesPorRolTest extends TestCase
{
    use RefreshDatabase;
    use EvaluacionAyudas;

    /** Personal de la ficha 2896411 y gente que NO debe recibir nada de ella. */
    private function personal(): array
    {
        $ficha = $this->ficha('2896411');
        $otraFicha = $this->ficha('2896412');

        $instructor = $this->usuario(Role::Instructor);
        $ficha->instructores()->attach($instructor->id);

        $ajeno = $this->usuario(Role::Instructor);
        $otraFicha->instructores()->attach($ajeno->id);

        return [
            'ficha' => $ficha,
            'aspirante' => $this->usuario(Role::Aspirante, null, ['ficha_id' => $ficha->id]),
            'instructor' => $instructor,
            'evaluador' => $this->usuario(Role::Aprendiz, 'evaluador', ['ficha_id' => $ficha->id]),
            'otro_evaluador' => $this->usuario(Role::Aprendiz, 'evaluador', ['ficha_id' => $ficha->id]),
            'gestor' => $this->usuario(Role::Aprendiz, 'gestor_convocatorias', ['ficha_id' => $ficha->id]),
            'seleccionador' => $this->usuario(Role::Aprendiz, 'seleccionador', ['ficha_id' => $ficha->id]),
            'general' => $this->usuario(Role::Aprendiz, 'general', ['ficha_id' => $ficha->id]),
            'super_admin' => $this->usuario(Role::SuperAdmin),
            'instructor_ajeno' => $ajeno,
            'evaluador_inactivo' => $this->usuario(Role::Aprendiz, 'evaluador', ['ficha_id' => $ficha->id, 'active' => false]),
        ];
    }

    /** @return list<int> ids de quienes recibieron un aviso de ese tipo, ordenados */
    private function recibidos(string $tipo): array
    {
        return Notificacion::query()->where('tipo', $tipo)->pluck('user_id')->sort()->values()->all();
    }

    /** @param  list<User>  $usuarios  @return list<int> */
    private function ids(array $usuarios): array
    {
        return collect($usuarios)->map(fn (User $u) => $u->id)->sort()->values()->all();
    }

    private function convocatoriaAbierta(Ficha $ficha, User $creador): \App\Models\Convocatoria
    {
        $convocatoria = $this->convocatoria($ficha, $creador);
        $convocatoria->forceFill([
            'fecha_apertura' => now('America/Bogota')->subDay()->toDateString(),
            'fecha_cierre' => now('America/Bogota')->addDay()->toDateString(),
        ])->save();
        $convocatoria->documentos()->attach($this->parametroId('tipo_documento', 'hoja_de_vida'), ['obligatorio' => true, 'ponderacion' => 100]);

        return $convocatoria;
    }

    public function test_postulacion_enviada_avisa_al_aspirante_y_al_personal_evaluador_y_gestor(): void
    {
        $p = $this->personal();
        $convocatoria = $this->convocatoriaAbierta($p['ficha'], $p['instructor']);
        $postulacion = $this->conDocumentosCargados($this->postulacion($convocatoria, $p['aspirante']));
        $postulacion->update(['estado_id' => $this->parametroId('estado_postulacion', 'borrador')]);

        $this->actingAs($p['aspirante'])->postJson("/api/postulaciones/{$postulacion->id}/enviar")->assertOk();

        $this->assertSame(
            $this->ids([$p['aspirante'], $p['instructor'], $p['evaluador'], $p['otro_evaluador'], $p['gestor']]),
            $this->recibidos('postulacion_enviada'),
        );
    }

    public function test_postulacion_retirada_avisa_al_personal_evaluador_y_gestor_pero_no_al_aspirante(): void
    {
        $p = $this->personal();
        $convocatoria = $this->convocatoriaAbierta($p['ficha'], $p['instructor']);
        $postulacion = $this->postulacion($convocatoria, $p['aspirante']);

        $this->actingAs($p['aspirante'])->patchJson("/api/postulaciones/{$postulacion->id}/estado", ['estado' => 'retirada'])
            ->assertOk();

        $this->assertSame(
            $this->ids([$p['instructor'], $p['evaluador'], $p['otro_evaluador'], $p['gestor']]),
            $this->recibidos('postulacion_retirada'),
        );
    }

    public function test_correccion_solicitada_avisa_al_aspirante_y_al_instructor_no_a_los_demas_evaluadores(): void
    {
        $p = $this->personal();
        $convocatoria = $this->convocatoriaAbierta($p['ficha'], $p['instructor']);
        $postulacion = $this->postulacion($convocatoria, $p['aspirante']);

        $this->actingAs($p['evaluador'])->putJson("/api/evaluaciones/{$postulacion->id}", [
            'estado' => 'correccion_solicitada',
            'observaciones' => 'La hoja de vida está borrosa.',
            'criterios' => [['criterio' => 'Evaluación', 'puntaje' => 0, 'ponderacion' => 1]],
            'perfil' => ['doc-'.$this->parametroId('tipo_documento', 'hoja_de_vida') => false],
        ])->assertOk();

        $this->assertSame($this->ids([$p['aspirante'], $p['instructor']]), $this->recibidos('correccion_solicitada'));
    }

    public function test_correccion_respondida_avisa_al_instructor_y_al_evaluador_que_la_pidio(): void
    {
        Storage::fake('local');
        $p = $this->personal();
        $convocatoria = $this->convocatoriaAbierta($p['ficha'], $p['instructor']);
        $hoja = $this->parametroId('tipo_documento', 'hoja_de_vida');
        $postulacion = $this->postulacion($convocatoria, $p['aspirante']);

        $this->actingAs($p['evaluador'])->putJson("/api/evaluaciones/{$postulacion->id}", [
            'estado' => 'correccion_solicitada',
            'observaciones' => 'La hoja de vida está borrosa.',
            'criterios' => [['criterio' => 'Evaluación', 'puntaje' => 0, 'ponderacion' => 1]],
            'perfil' => ['doc-'.$hoja => false],
        ])->assertOk();

        $this->actingAs($p['aspirante'])->withHeaders(['Accept' => 'application/json'])
            ->post("/api/postulaciones/{$postulacion->id}/documentos", [
                'codigo' => 'hoja_de_vida',
                'archivo' => UploadedFile::fake()->create('hoja_corregida.pdf', 100, 'application/pdf'),
            ])->assertCreated();
        $this->actingAs($p['aspirante'])->postJson("/api/postulaciones/{$postulacion->id}/correccion/enviar")->assertOk();

        $this->assertSame($this->ids([$p['instructor'], $p['evaluador']]), $this->recibidos('correccion_respondida'));
    }

    public function test_evaluacion_finalizada_que_pasa_avisa_a_instructor_y_seleccionador(): void
    {
        $p = $this->personal();
        $convocatoria = $this->convocatoriaAbierta($p['ficha'], $p['instructor']);
        $postulacion = $this->conDocumentosCargados($this->postulacion($convocatoria, $p['aspirante']));

        // El paso por selección depende del cumplimiento documental (umbral 60 %): la hoja de vida cuenta como cumplida.
        $this->actingAs($p['evaluador'])->putJson("/api/evaluaciones/{$postulacion->id}", [
            'estado' => 'finalizada',
            'perfil' => ['doc-'.$this->parametroId('tipo_documento', 'hoja_de_vida') => true],
            'criterios' => [['criterio' => 'Experiencia', 'puntaje' => 95, 'ponderacion' => 1]],
        ])->assertOk();

        $this->assertSame($this->ids([$p['aspirante'], $p['instructor']]), $this->recibidos('evaluacion_finalizada'));
        $this->assertSame($this->ids([$p['seleccionador']]), $this->recibidos('pasa_seleccion'));
    }

    public function test_evaluacion_finalizada_que_no_pasa_no_avisa_al_seleccionador(): void
    {
        $p = $this->personal();
        $convocatoria = $this->convocatoriaAbierta($p['ficha'], $p['instructor']);
        $postulacion = $this->conDocumentosCargados($this->postulacion($convocatoria, $p['aspirante']));

        $this->actingAs($p['evaluador'])->putJson("/api/evaluaciones/{$postulacion->id}", [
            'estado' => 'finalizada',
            'criterios' => [['criterio' => 'Experiencia', 'puntaje' => 10, 'ponderacion' => 1]],
        ])->assertOk();

        $this->assertSame($this->ids([$p['aspirante'], $p['instructor']]), $this->recibidos('evaluacion_finalizada'));
        $this->assertSame([], $this->recibidos('pasa_seleccion'));
    }

    public function test_quien_finaliza_no_se_avisa_a_si_mismo(): void
    {
        $p = $this->personal();
        $convocatoria = $this->convocatoriaAbierta($p['ficha'], $p['instructor']);
        $postulacion = $this->conDocumentosCargados($this->postulacion($convocatoria, $p['aspirante']));

        $this->actingAs($p['instructor'])->putJson("/api/evaluaciones/{$postulacion->id}", [
            'estado' => 'finalizada',
            'criterios' => [['criterio' => 'Experiencia', 'puntaje' => 10, 'ponderacion' => 1]],
        ])->assertOk();

        $this->assertSame([$p['aspirante']->id], $this->recibidos('evaluacion_finalizada'));
    }

    /** Quien puede recibir un aviso debe poder abrir su lista: si no, el aviso existe pero nadie lo ve. */
    public function test_cada_rol_que_recibe_avisos_puede_abrir_su_lista(): void
    {
        $p = $this->personal();

        foreach (['aspirante', 'instructor', 'evaluador', 'gestor', 'seleccionador'] as $clave) {
            $this->assertContains('propio:notificaciones', $p[$clave]->permissions(), "El rol «{$clave}» no tiene propio:notificaciones.");
            $this->actingAs($p[$clave])->getJson('/api/notificaciones')->assertOk();
        }
    }

    public function test_el_general_y_el_super_admin_no_reciben_avisos_operativos(): void
    {
        $p = $this->personal();
        $convocatoria = $this->convocatoriaAbierta($p['ficha'], $p['instructor']);
        $postulacion = $this->conDocumentosCargados($this->postulacion($convocatoria, $p['aspirante']));
        $postulacion->update(['estado_id' => $this->parametroId('estado_postulacion', 'borrador')]);

        $this->actingAs($p['aspirante'])->postJson("/api/postulaciones/{$postulacion->id}/enviar")->assertOk();
        $this->actingAs($p['aspirante'])->patchJson("/api/postulaciones/{$postulacion->id}/estado", ['estado' => 'retirada'])
            ->assertOk();

        $sinAvisos = [$p['general']->id, $p['super_admin']->id, $p['instructor_ajeno']->id];
        $recibidos = Notificacion::query()->pluck('user_id')->unique()->all();
        foreach ($sinAvisos as $id) {
            $this->assertNotContains($id, $recibidos);
        }
    }
}
