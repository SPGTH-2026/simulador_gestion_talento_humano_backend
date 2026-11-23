<?php

namespace Tests\Concerns;

use App\Enums\Role;
use App\Models\Convocatoria;
use App\Models\Evaluacion;
use App\Models\Ficha;
use App\Models\Parametro;
use App\Models\Postulacion;
use App\Models\PerfilAspirante;
use App\Models\SeleccionConfiguracion;
use App\Models\User;
use Database\Seeders\ParametroSeeder;

trait SeleccionDocumentosCompletosAyudas
{
    private static int $codigoSecuencia = 0;

    private Ficha $ficha;

    private User $seleccionador;

    private Convocatoria $convocatoria;

    /** @var array{hv: int, id: int, tp: int, libreta: int} */
    private array $doc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ParametroSeeder::class);

        $this->ficha = Ficha::create(['codigo' => '2896411', 'nombre_programa' => 'ADSO']);
        $this->seleccionador = $this->usuario(Role::Aprendiz, 'seleccionador');
        $this->doc = [
            'hv' => $this->parametroId('tipo_documento', 'hoja_de_vida'),
            'id' => $this->parametroId('tipo_documento', 'documento_identidad'),
            'tp' => $this->parametroId('tipo_documento', 'tarjeta_profesional'),
            'libreta' => $this->parametroId('tipo_documento', 'libreta_militar'),
        ];
        $this->convocatoria = $this->crearConvocatoria(2);
    }

    private function usuario(Role $role, ?string $subrole = null): User
    {
        return User::factory()->create([
            'role' => $role, 'subrole' => $subrole, 'active' => true, 'email_verified_at' => now(),
            'ficha_id' => $this->ficha->id ?? null,
        ]);
    }

    private function parametroId(string $tema, string $codigo): int
    {
        return (int) Parametro::porTemaYCodigo($tema, $codigo)->id;
    }

    /** En proceso, solo documental, hoja de vida y documento de identidad obligatorios, tarjeta profesional opcional. */
    private function crearConvocatoria(int $vacantes, bool $conLibreta = false): Convocatoria
    {
        $convocatoria = Convocatoria::unguarded(fn () => Convocatoria::create([
            'ficha_id' => $this->ficha->id,
            'codigo' => sprintf('CONV-DOC-%03d', ++self::$codigoSecuencia),
            'nombre' => 'Convocatoria de prueba',
            'creado_por' => $this->usuario(Role::Instructor)->id,
            'estado_id' => $this->parametroId('estado_convocatoria', 'en_proceso'),
            'tipo_convocatoria_id' => $this->parametroId('tipo_convocatoria', 'externo'),
            'tipo_empleo_id' => $this->parametroId('tipo_empleo', 'tiempo_completo'),
            'tipo_contrato_id' => $this->parametroId('tipo_contrato', 'termino_fijo'),
            'modalidad_id' => $this->parametroId('modalidad', 'presencial'),
            'vacantes' => $vacantes,
        ]));
        $convocatoria->documentos()->attach($this->doc['hv'], ['obligatorio' => true, 'ponderacion' => 60]);
        $convocatoria->documentos()->attach($this->doc['id'], ['obligatorio' => true, 'ponderacion' => $conLibreta ? 20 : 40]);
        $convocatoria->documentos()->attach($this->doc['tp'], ['obligatorio' => false, 'ponderacion' => null]);
        if ($conLibreta) {
            $convocatoria->documentos()->attach($this->doc['libreta'], ['obligatorio' => true, 'ponderacion' => 20]);
        }
        SeleccionConfiguracion::create(['convocatoria_id' => $convocatoria->id, 'entrevista_activa' => false, 'prueba_tecnica_activa' => false]);

        return $convocatoria;
    }

    /**
     * Postulada con una evaluación finalizada que la habilita. $subidos: claves de $this->doc que SÍ tiene cargadas.
     *
     * @param  array<int,string>  $subidos
     */
    private function candidato(array $subidos = ['hv', 'id'], ?string $sexo = null, ?Convocatoria $convocatoria = null): Postulacion
    {
        $convocatoria ??= $this->convocatoria;
        $aspirante = $this->usuario(Role::Aspirante);
        if ($sexo !== null) {
            PerfilAspirante::create(['user_id' => $aspirante->id, 'sexo_id' => $this->parametroId('sexo', $sexo)]);
        }
        $postulacion = Postulacion::create([
            'convocatoria_id' => $convocatoria->id,
            'user_id' => $aspirante->id,
            'estado_id' => $this->parametroId('estado_postulacion', 'postulada'),
            'fecha_postulacion' => now(),
        ]);
        foreach ($subidos as $clave) {
            $postulacion->documentos()->create([
                'nombre_original' => "{$clave}.pdf", 'ruta' => "postulaciones/test/{$postulacion->id}-{$clave}.pdf",
                'disco' => 'local', 'mime' => 'application/pdf', 'tamano_bytes' => 1024, 'parametro_id' => $this->doc[$clave],
            ]);
        }
        Evaluacion::create([
            'postulacion_id' => $postulacion->id,
            'evaluador_id' => $this->usuario(Role::Aprendiz, 'evaluador')->id,
            'estado' => 'finalizada', 'cumplimiento' => 100, 'pasa_seleccion' => true,
        ]);

        return $postulacion;
    }

    /** Estado legado: se le quita un obligatorio a una postulación ya evaluada (antes de RF-DOC-006 se podía). */
    private function quitarDocumento(Postulacion $postulacion, string $clave): void
    {
        $postulacion->documentos()->where('parametro_id', $this->doc[$clave])->delete();
    }

    private function ranking(?Convocatoria $convocatoria = null)
    {
        $convocatoria ??= $this->convocatoria;

        return $this->actingAs($this->seleccionador->fresh())->getJson("/api/seleccion/convocatorias/{$convocatoria->id}/ranking")->assertOk();
    }

    /** @return array<int,int> ids de las postulaciones listas */
    private function listos(?Convocatoria $convocatoria = null): array
    {
        return array_map(fn ($f) => $f['postulacion_id'], $this->ranking($convocatoria)->json('data'));
    }

    private function confirmar(array $postulaciones, ?Convocatoria $convocatoria = null)
    {
        $convocatoria ??= $this->convocatoria;

        return $this->actingAs($this->seleccionador)->postJson("/api/seleccion/convocatorias/{$convocatoria->id}/finalistas", [
            'version' => $this->ranking($convocatoria)->json('meta.version'),
            'postulacion_ids' => array_map(fn (Postulacion $p) => $p->id, $postulaciones),
            'justificacion' => 'Elección manual de la convocatoria solo documental.',
        ]);
    }

    private function versionLista(): int
    {
        return (int) $this->actingAs($this->seleccionador)
            ->getJson("/api/seleccion/convocatorias/{$this->convocatoria->id}/finalistas")->assertOk()->json('data.actual.version');
    }

    private function decidir(Postulacion $postulacion, string $resultado, int $version, string $motivo = 'Decisión de prueba del seleccionador.')
    {
        return $this->actingAs($this->seleccionador)->putJson(
            "/api/seleccion/convocatorias/{$this->convocatoria->id}/decisiones/{$postulacion->id}",
            ['resultado' => $resultado, 'justificacion' => $motivo, 'lista_version' => $version],
        );
    }

    // ── Ranking ────────────────────────────────────────────────────────
}
