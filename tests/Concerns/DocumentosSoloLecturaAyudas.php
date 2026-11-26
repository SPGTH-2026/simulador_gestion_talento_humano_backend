<?php

namespace Tests\Concerns;

use App\Enums\Role;
use App\Models\Convocatoria;
use App\Models\Ficha;
use App\Models\Parametro;
use App\Models\Postulacion;
use App\Models\PostulacionDocumento;
use App\Models\PostulacionSkill;
use App\Models\User;
use Database\Seeders\ParametroSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

trait DocumentosSoloLecturaAyudas
{
    private static int $codigoSecuencia = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ParametroSeeder::class);
        Storage::fake('local');
    }

    // ── Helpers ────────────────────────────────────────────────────────

    private function ficha(string $codigo = '3173334'): Ficha
    {
        return Ficha::create(['codigo' => $codigo, 'nombre_programa' => 'Análisis y Desarrollo de Software']);
    }

    private function usuario(Role $role, array $extra = []): User
    {
        return User::factory()->create($extra + [
            'role' => $role,
            'active' => true,
            'email_verified_at' => now(),
        ]);
    }

    private function instructor(Ficha ...$fichas): User
    {
        $instructor = $this->usuario(Role::Instructor);
        foreach ($fichas as $ficha) {
            $instructor->fichas()->attach($ficha->id);
        }

        return $instructor->fresh();
    }

    private function aspirante(Ficha $ficha): User
    {
        return $this->usuario(Role::Aspirante, ['ficha_id' => $ficha->id]);
    }

    private function parametroId(string $tema, string $codigo): int
    {
        $parametro = Parametro::porTemaYCodigo($tema, $codigo);
        $this->assertNotNull($parametro, "Falta el parámetro {$tema}.{$codigo}");

        return (int) $parametro->id;
    }

    private function convocatoriaAbierta(Ficha $ficha, User $creador): Convocatoria
    {
        return Convocatoria::unguarded(fn () => Convocatoria::create([
            'ficha_id' => $ficha->id,
            'codigo' => sprintf('CONV-SOLO-%03d', ++self::$codigoSecuencia),
            'nombre' => 'Convocatoria base',
            'vacantes' => 1,
            'tipo_convocatoria_id' => $this->parametroId('tipo_convocatoria', 'externo'),
            'tipo_empleo_id' => $this->parametroId('tipo_empleo', 'tiempo_completo'),
            'tipo_contrato_id' => $this->parametroId('tipo_contrato', 'termino_fijo'),
            'modalidad_id' => $this->parametroId('modalidad', 'presencial'),
            'estado_id' => $this->parametroId('estado_convocatoria', 'publicada'),
            'fecha_apertura' => now('America/Bogota')->subDay()->toDateString(),
            'fecha_cierre' => now('America/Bogota')->addDay()->toDateString(),
            'creado_por' => $creador->id,
        ]));
    }

    private function crearPostulacion(Convocatoria $convocatoria, User $usuario, string $estado = 'postulada'): Postulacion
    {
        return Postulacion::create([
            'convocatoria_id' => $convocatoria->id,
            'user_id' => $usuario->id,
            'estado_id' => $this->parametroId('estado_postulacion', $estado),
            'fecha_postulacion' => now(),
        ]);
    }

    private function pdf(string $nombre = 'documento.pdf'): UploadedFile
    {
        return UploadedFile::fake()->create($nombre, 100, 'application/pdf');
    }

    /** @return array{0: User, 1: Postulacion, 2: User} [aspirante, postulacion, instructor] */
    private function base(): array
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $aspirante = $this->aspirante($ficha);
        $convocatoria = $this->convocatoriaAbierta($ficha, $instructor);
        $convocatoria->documentos()->attach($this->parametroId('tipo_documento', 'hoja_de_vida'), ['obligatorio' => true]);
        // Nace en borrador: es cuando el aspirante sube sus documentos. subirComoAspirante() la deja enviada.
        $postulacion = $this->crearPostulacion($convocatoria, $aspirante, 'borrador');

        return [$aspirante, $postulacion, $instructor];
    }

    /** Sube un documento como el aspirante dueño (única vía de escritura válida). */
    private function subirComoAspirante(User $aspirante, Postulacion $postulacion): PostulacionDocumento
    {
        $this->actingAs($aspirante)
            ->withHeaders(['Accept' => 'application/json'])
            ->post("/api/postulaciones/{$postulacion->id}/documentos", [
                'codigo' => 'hoja_de_vida',
                'archivo' => $this->pdf('hoja.pdf'),
            ])
            ->assertCreated();

        // Subido el documento, la postulación se envía: desde ahí ya no se edita y quien revisa la ve.
        $postulacion->update(['estado_id' => $this->parametroId('estado_postulacion', 'postulada')]);

        return PostulacionDocumento::sole();
    }

    private function skill(Postulacion $postulacion): PostulacionSkill
    {
        return PostulacionSkill::create([
            'postulacion_id' => $postulacion->id,
            'nombre' => 'SAP HR',
        ]);
    }

    /** Un 403, 404 o 405 significa que la escritura fue rechazada. */
    private function assertRechazada($respuesta): void
    {
        $this->assertContains(
            $respuesta->getStatusCode(),
            [403, 404, 405],
            'La escritura debía rechazarse y respondió '.$respuesta->getStatusCode().'.',
        );
    }

    // ── Permisos ───────────────────────────────────────────────────────
}
