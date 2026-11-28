<?php

namespace Database\Seeders;

use App\Enums\ConvocatoriaEstado;
use App\Enums\PostulacionEstado;
use App\Enums\Role;
use App\Models\Convocatoria;
use App\Models\Ficha;
use App\Models\Parametro;
use App\Models\Postulacion;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

/**
 * Datos de DESARROLLO para probar el puntaje de la evaluación (documentos 90 % + habilidades 10 %, checklist por documento):
 * una convocatoria publicada con documentos ponderados y con checklist, tres habilidades ponderadas, y cinco aspirantes ya
 * postulados con distintos casos (completo, sin habilidades, parcial, con un documento sin cargar). Cada documento es un PDF
 * real y corto, para ver el visor junto al checklist. Es idempotente y no corre en producción.
 *
 * Cuentas (todas con la misma contraseña: SEED_PASSWORD del .env o «Prueba2026*»):
 *   evaluador.puntaje@test.com                       Aprendiz evaluador de la ficha
 *   aspirante.puntaje.completo@test.com              documentos completos + 3 habilidades (una con certificado)
 *   aspirante.puntaje.sinskills@test.com             documentos completos, sin habilidades
 *   aspirante.puntaje.parcial@test.com               documentos completos + 2 habilidades
 *   aspirante.puntaje.faltante@test.com              le falta un documento obligatorio
 *   aspirante.puntaje.laboral@test.com               documentos completos + 1 habilidad con certificado
 */
class PuntajeDemoSeeder extends Seeder
{
    private const CONTRASENA_POR_DEFECTO = 'Prueba2026*';

    /** codigo del documento => [porcentaje entre documentos, checklist (un ítem por línea) o null]. Suman 100. */
    private const DOCUMENTOS = [
        'hoja_de_vida' => [40, "Datos personales completos\nFormación académica registrada\nExperiencia laboral con cargo y fechas\nFirmada\nSin tachones ni enmendaduras\nActualizada en el último año"],
        'documento_identidad' => [30, "Legible por ambas caras\nVigente\nCoincide con el nombre del aspirante"],
        'certificados_academicos' => [20, "Título o acta de grado\nInstitución reconocida\nFecha de grado visible\nSin enmendaduras"],
        'certificados_laborales' => [10, null],
    ];

    /** nombre de la habilidad => porcentaje entre habilidades (suman 100). */
    private const HABILIDADES = ['Excel avanzado' => 50, 'Trabajo en equipo' => 30, 'Liderazgo' => 20];

    public function run(): void
    {
        if (app()->environment('production')) {
            return;
        }

        $ficha = Ficha::firstWhere('codigo', '3173334') ?? Ficha::query()->first();
        $instructor = User::firstWhere('email', 'instructor@test.com');
        $publicada = Parametro::porTemaYCodigo('estado_convocatoria', ConvocatoriaEstado::Publicada->value);
        $postulada = Parametro::porTemaYCodigo('estado_postulacion', PostulacionEstado::Postulada->value);

        if (! $ficha || ! $publicada || ! $postulada) {
            $this->command?->warn('Faltan la ficha o los parámetros: corre primero DatabaseSeeder.');

            return;
        }

        $password = env('SEED_PASSWORD') ?: self::CONTRASENA_POR_DEFECTO;
        $convocatoria = $this->convocatoria($ficha, $instructor, $publicada);

        $this->usuario('evaluador.puntaje@test.com', 'Evaluador de Puntaje', $password, $ficha, Role::Aprendiz, 'evaluador');

        $casos = [
            ['aspirante.puntaje.completo@test.com', 'Aspirante Completo', [], ['Excel avanzado' => true, 'Trabajo en equipo' => false, 'Liderazgo' => false]],
            ['aspirante.puntaje.sinskills@test.com', 'Aspirante Sin Habilidades', [], []],
            ['aspirante.puntaje.parcial@test.com', 'Aspirante Parcial', [], ['Trabajo en equipo' => false, 'Liderazgo' => false]],
            ['aspirante.puntaje.faltante@test.com', 'Aspirante Documento Faltante', ['certificados_academicos'], ['Liderazgo' => false]],
            ['aspirante.puntaje.laboral@test.com', 'Aspirante Con Certificado', [], ['Excel avanzado' => true]],
        ];

        foreach ($casos as [$correo, $nombre, $sinCargar, $habilidades]) {
            $aspirante = $this->usuario($correo, $nombre, $password, $ficha, Role::Aspirante);
            $postulacion = Postulacion::updateOrCreate(
                ['convocatoria_id' => $convocatoria->id, 'user_id' => $aspirante->id],
                ['estado_id' => $postulada->id, 'fecha_postulacion' => now()],
            );
            $this->documentos($postulacion, $nombre, $sinCargar);
            $this->habilidades($postulacion, $convocatoria, $nombre, $habilidades);
        }

        $this->command?->info("Convocatoria de prueba lista: «{$convocatoria->nombre}» con ".count($casos).' aspirantes postulados.');
    }

    private function convocatoria(Ficha $ficha, ?User $instructor, Parametro $publicada): Convocatoria
    {
        $convocatoria = Convocatoria::unguarded(fn () => Convocatoria::updateOrCreate(
            ['ficha_id' => $ficha->id, 'nombre' => 'Prueba de puntaje 90/10'],
            [
                'codigo' => 'CONV-2026-900',
                'cargo' => 'Auxiliar Administrativo',
                'area' => 'Talento humano',
                'descripcion' => 'Convocatoria de desarrollo para probar el puntaje: documentos con checklist y habilidades ponderadas.',
                'creado_por' => $instructor?->id,
                'tipo_convocatoria_id' => Parametro::porTemaYCodigo('tipo_convocatoria', 'externo')->id,
                'tipo_empleo_id' => Parametro::porTemaYCodigo('tipo_empleo', 'tiempo_completo')->id,
                'tipo_contrato_id' => Parametro::porTemaYCodigo('tipo_contrato', 'termino_fijo')->id,
                'modalidad_id' => Parametro::porTemaYCodigo('modalidad', 'presencial')->id,
                'estado_id' => $publicada->id,
                'vacantes' => 3,
                'fecha_apertura' => now()->subDays(5)->toDateString(),
                'fecha_cierre' => now()->addDays(30)->toDateString(),
                'fecha_evaluacion' => now()->addDays(40)->toDateString(),
            ],
        ));

        $documentos = [];
        foreach (self::DOCUMENTOS as $codigo => [$porcentaje, $items]) {
            $documentos[Parametro::porTemaYCodigo('tipo_documento', $codigo)->id] = [
                'obligatorio' => true,
                'ponderacion' => $porcentaje,
                'items' => $items,
            ];
        }
        $convocatoria->documentos()->sync($documentos);

        foreach (self::HABILIDADES as $nombre => $porcentaje) {
            $convocatoria->skills()->updateOrCreate(['nombre' => $nombre], ['ponderacion' => $porcentaje]);
        }

        return $convocatoria;
    }

    private function usuario(string $correo, string $nombre, string $password, Ficha $ficha, Role $rol, ?string $subrol = null): User
    {
        $usuario = User::firstWhere('email', $correo) ?? User::forceCreate([
            'name' => $nombre,
            'email' => $correo,
            'password' => $password,
            'role' => $rol,
            'subrole' => $subrol,
            'active' => true,
            'ficha_id' => $ficha->id,
            'email_verified_at' => now(),
        ]);

        return $usuario;
    }

    /** @param list<string> $sinCargar códigos de documento que este aspirante NO subió */
    private function documentos(Postulacion $postulacion, string $aspirante, array $sinCargar): void
    {
        foreach (array_keys(self::DOCUMENTOS) as $codigo) {
            $parametro = Parametro::porTemaYCodigo('tipo_documento', $codigo);

            if (in_array($codigo, $sinCargar, true)) {
                $postulacion->documentos()->where('parametro_id', $parametro->id)->delete();

                continue;
            }

            $ruta = "demo-puntaje/postulacion-{$postulacion->id}/{$codigo}.pdf";
            $contenido = $this->pdf($parametro->nombre, "Aspirante: {$aspirante}");
            Storage::disk('local')->put($ruta, $contenido);

            $postulacion->documentos()->updateOrCreate(['parametro_id' => $parametro->id], [
                'nombre_original' => "{$codigo}.pdf",
                'ruta' => $ruta,
                'disco' => 'local',
                'mime' => 'application/pdf',
                'tamano_bytes' => strlen($contenido),
            ]);
        }
    }

    /** @param array<string, bool> $habilidades nombre => ¿con certificado? */
    private function habilidades(Postulacion $postulacion, Convocatoria $convocatoria, string $aspirante, array $habilidades): void
    {
        $postulacion->skills()->delete();

        foreach ($habilidades as $nombre => $conCertificado) {
            $skill = $convocatoria->skills()->where('nombre', $nombre)->first();
            $datos = ['convocatoria_skill_id' => $skill->id, 'nombre' => $nombre];

            if ($conCertificado) {
                $ruta = "demo-puntaje/postulacion-{$postulacion->id}/certificado-".str($nombre)->slug().'.pdf';
                $contenido = $this->pdf("Certificado: {$nombre}", "Aspirante: {$aspirante}");
                Storage::disk('local')->put($ruta, $contenido);
                $datos += [
                    'certificado_nombre_original' => 'certificado.pdf',
                    'certificado_ruta' => $ruta,
                    'certificado_disco' => 'local',
                    'certificado_mime' => 'application/pdf',
                    'certificado_tamano_bytes' => strlen($contenido),
                ];
            }

            $postulacion->skills()->create($datos);
        }
    }

    /** Un PDF mínimo de una página (solo texto ASCII), válido para el visor. */
    private function pdf(string $titulo, string $linea): string
    {
        $limpiar = fn (string $texto) => str_replace(['(', ')', '\\'], '', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $texto) ?: $texto);
        $texto = 'BT /F1 40 Tf 60 680 Td ('.$limpiar($titulo).') Tj 0 -60 Td /F1 24 Tf ('.$limpiar($linea).') Tj 0 -46 Td /F1 20 Tf (Documento de prueba generado para desarrollo.) Tj 0 -40 Td (Sirve para ver el visor junto al checklist.) Tj ET';
        $objetos = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
            4 => '<< /Length '.strlen($texto)." >>\nstream\n{$texto}\nendstream",
            5 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];

        $pdf = "%PDF-1.4\n";
        $desplazamientos = [];
        foreach ($objetos as $numero => $objeto) {
            $desplazamientos[$numero] = strlen($pdf);
            $pdf .= "{$numero} 0 obj\n{$objeto}\nendobj\n";
        }

        $inicioTabla = strlen($pdf);
        $pdf .= "xref\n0 6\n0000000000 65535 f \n";
        foreach ($desplazamientos as $desplazamiento) {
            $pdf .= sprintf("%010d 00000 n \n", $desplazamiento);
        }

        return $pdf."trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n{$inicioTabla}\n%%EOF";
    }
}
