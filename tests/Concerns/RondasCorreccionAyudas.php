<?php

namespace Tests\Concerns;

use App\Enums\Role;
use App\Models\Convocatoria;
use App\Models\CorreccionPostulacion;
use App\Models\Evaluacion;
use App\Models\Ficha;
use App\Models\Parametro;
use App\Models\Postulacion;
use App\Models\User;
use Database\Seeders\ParametroSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Escenario común de las pruebas de rondas de corrección: una convocatoria cerrada con dos documentos obligatorios, un
 * aspirante postulado, dos evaluadores y un Instructor de la misma ficha, y utilidades para pedir, responder y autorizar.
 */
trait RondasCorreccionAyudas
{
    private static int $secuencia = 0;
    private Ficha $ficha;
    private User $evaluador;
    private User $otroEvaluador;
    private User $aspirante;
    private User $instructor;
    private Postulacion $postulacion;
    private int $hoja;
    private int $identidad;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(ParametroSeeder::class);

        $this->ficha = Ficha::create(['codigo' => (string) (4100000 + ++self::$secuencia), 'nombre_programa' => 'ADSO']);
        $this->evaluador = $this->usuario(Role::Aprendiz, 'evaluador');
        $this->otroEvaluador = $this->usuario(Role::Aprendiz, 'evaluador');
        $this->aspirante = $this->usuario(Role::Aspirante);
        $this->instructor = $this->usuario(Role::Instructor);

        $convocatoria = Convocatoria::unguarded(fn () => Convocatoria::create([
            'ficha_id' => $this->ficha->id,
            'codigo' => sprintf('CONV-RC-%03d', ++self::$secuencia),
            'nombre' => 'Convocatoria de rondas',
            'creado_por' => $this->instructor->id,
            // Cerrada: una corrección vigente se atiende aunque la convocatoria ya no reciba postulaciones.
            'estado_id' => $this->parametroId('estado_convocatoria', 'cerrada'),
            'tipo_convocatoria_id' => $this->parametroId('tipo_convocatoria', 'externo'),
            'tipo_empleo_id' => $this->parametroId('tipo_empleo', 'tiempo_completo'),
            'tipo_contrato_id' => $this->parametroId('tipo_contrato', 'termino_fijo'),
            'modalidad_id' => $this->parametroId('modalidad', 'presencial'),
            'vacantes' => 3,
        ]));
        $this->hoja = $this->parametroId('tipo_documento', 'hoja_de_vida');
        $this->identidad = $this->parametroId('tipo_documento', 'documento_identidad');
        $convocatoria->documentos()->attach($this->hoja, ['obligatorio' => true, 'ponderacion' => 50]);
        $convocatoria->documentos()->attach($this->identidad, ['obligatorio' => true, 'ponderacion' => 50]);

        $this->postulacion = Postulacion::create([
            'convocatoria_id' => $convocatoria->id,
            'user_id' => $this->aspirante->id,
            'estado_id' => $this->parametroId('estado_postulacion', 'postulada'),
            'fecha_postulacion' => now(),
        ]);

        // Los archivos ya existían ANTES de cualquier corrección.
        foreach (['hoja_de_vida' => $this->hoja, 'documento_identidad' => $this->identidad] as $parametro) {
            $this->postulacion->documentos()->create([
                'nombre_original' => 'original.pdf', 'ruta' => "postulaciones/test/{$this->postulacion->id}-{$parametro}.pdf",
                'disco' => 'local', 'mime' => 'application/pdf', 'tamano_bytes' => 1024, 'parametro_id' => $parametro,
            ]);
        }
    }

    private function usuario(Role $role, ?string $subrole = null): User
    {
        return User::factory()->create([
            'role' => $role, 'subrole' => $subrole, 'active' => true,
            'email_verified_at' => now(), 'ficha_id' => $this->ficha->id,
        ]);
    }

    private function parametroId(string $tema, string $codigo): int
    {
        return (int) Parametro::porTemaYCodigo($tema, $codigo)->id;
    }

    /** Evaluador pide corrección de los documentos indicados (por defecto, la hoja de vida). */
    private function pedir(User $evaluador, string $observaciones = 'Reemplaza la hoja de vida.', ?array $marcados = null)
    {
        $marcados ??= [$this->hoja];
        $perfil = [];
        foreach ([$this->hoja, $this->identidad] as $id) {
            $perfil['doc-'.$id] = in_array($id, $marcados, true) ? false : true;
        }

        return $this->actingAs($evaluador)->putJson("/api/evaluaciones/{$this->postulacion->id}", [
            'estado' => 'correccion_solicitada',
            'observaciones' => $observaciones,
            'criterios' => [['criterio' => 'Evaluación', 'puntaje' => 0, 'ponderacion' => 1]],
            'perfil' => $perfil,
        ]);
    }

    /** El aspirante reemplaza el archivo del documento indicado (codigo del tema) y envía la corrección. */
    private function responder(string $codigo = 'hoja_de_vida', bool $enviar = true)
    {
        $this->actingAs($this->aspirante)->withHeaders(['Accept' => 'application/json'])
            ->post("/api/postulaciones/{$this->postulacion->id}/documentos", [
                'codigo' => $codigo,
                'archivo' => UploadedFile::fake()->create('nuevo.pdf', 100, 'application/pdf'),
            ])->assertSuccessful();

        return $enviar
            ? $this->actingAs($this->aspirante)->postJson("/api/postulaciones/{$this->postulacion->id}/correccion/enviar")
            : null;
    }

    private function rondas()
    {
        return CorreccionPostulacion::where('postulacion_id', $this->postulacion->id)->orderBy('id')->get();
    }

    private function resumen(?User $actor = null): array
    {
        return $this->actingAs($actor ?? $this->evaluador)
            ->getJson("/api/evaluaciones/{$this->postulacion->id}")->assertOk()->json('data.correccion');
    }

    private function vencer(CorreccionPostulacion $ronda): void
    {
        $ronda->forceFill(['fecha_limite' => now()->subMinute()])->save();
    }

    private function autorizar(User $actor, string $justificacion = 'Hubo una falla del sistema al cargar.')
    {
        return $this->actingAs($actor)->postJson("/api/evaluaciones/{$this->postulacion->id}/correcciones/autorizacion", [
            'justificacion' => $justificacion,
        ]);
    }

    private function legada(?User $evaluador = null, ?\DateTimeInterface $limite = null, ?\DateTimeInterface $enviada = null): Evaluacion
    {
        return Evaluacion::create([
            'postulacion_id' => $this->postulacion->id,
            'evaluador_id' => ($evaluador ?? $this->evaluador)->id,
            'estado' => 'correccion_solicitada',
            'observaciones' => 'Solicitud antigua.',
            'perfil' => ['doc-'.$this->hoja => false, 'doc-'.$this->identidad => true],
            'fecha_limite_correccion' => $limite,
            'correccion_enviada_at' => $enviada,
        ]);
    }
}
