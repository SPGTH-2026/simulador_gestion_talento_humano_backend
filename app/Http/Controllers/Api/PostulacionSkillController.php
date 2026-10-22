<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\GestionaPostulacionPropia;
use App\Http\Controllers\Controller;
use App\Models\ConvocatoriaSkill;
use App\Models\Parametro;
use App\Models\Postulacion;
use App\Models\PostulacionSkill;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Skills declaradas por el aspirante en su postulación.
 *
 * - Las que pide la convocatoria ("la tengo") se declaran enviando
 *   convocatoria_skill_id; las propias, enviando un nombre libre.
 * - El certificado es OPCIONAL por skill (declarada vs. certificada) y se
 *   sube/reemplaza/borra con endpoints propios.
 * Skill declarada por el aspirante en una postulación. El origen puede ser:
 * de la convocatoria (`convocatoria_skill_id`), del catálogo general
 * (`parametro_id` del tema `skill_catalogo`) o propia (texto libre). El
 * certificado es opcional y se sube/reemplaza/borra con endpoints propios.
 * - Mismas reglas de acceso que los documentos: dueño (404 si es ajena),
 *   editable solo dentro de la ventana y en estado 'postulada', y lectura de
 *   revisores con permiso 'documentos:ver' con alcance por ficha.
 */
class PostulacionSkillController extends Controller
{
    use GestionaPostulacionPropia;

    private const MAX_NOMBRE = 100;

    /** Tema de parámetros que representa el catálogo general de skills. */
    private const TEMA_SKILL_CATALOGO = 'skill_catalogo';

    /**
     * Declara una skill. Debe indicarse EXACTAMENTE UNA de estas formas:
     *  - `convocatoria_skill_id` (skill pedida por la convocatoria),
     *  - `parametro_id` del tema `skill_catalogo` (catálogo general),
     *  - `nombre` (skill propia, texto libre).
     * Más de una o ninguna -> 422. Idempotente por nombre.
     */
    public function store(Request $request, Postulacion $postulacion): JsonResponse
    {
        $this->exigirPropia($request, $postulacion);
        $this->exigirEditable($postulacion, 'skills');

        $datos = $request->validate([
            'nombre' => ['nullable', 'string', 'max:'.self::MAX_NOMBRE],
            'convocatoria_skill_id' => ['nullable', 'integer'],
            'parametro_id' => ['nullable', 'integer'],
        ], $this->mensajes(), $this->atributos());

        $formas = array_filter([
            'convocatoria_skill_id' => ! empty($datos['convocatoria_skill_id']),
            'parametro_id' => ! empty($datos['parametro_id']),
            'nombre' => isset($datos['nombre']) && trim((string) $datos['nombre']) !== '',
        ]);

        if ($formas === []) {
            throw ValidationException::withMessages([
                'nombre' => 'Debes indicar una skill de la convocatoria, del catálogo o un nombre propio.',
            ]);
        }

        if (count($formas) > 1) {
            throw ValidationException::withMessages([
                'nombre' => 'Indica la skill de una sola forma: de la convocatoria, del catálogo o un nombre propio.',
            ]);
        }

        $convocatoriaSkillId = null;
        $parametroId = null;

        if (! empty($datos['convocatoria_skill_id'])) {
            $convocatoriaSkill = ConvocatoriaSkill::query()
                ->where('id', $datos['convocatoria_skill_id'])
                ->where('convocatoria_id', $postulacion->convocatoria_id)
                ->first();

            if (! $convocatoriaSkill) {
                throw ValidationException::withMessages([
                    'convocatoria_skill_id' => 'Esa skill no hace parte de la convocatoria.',
                ]);
            }

            $nombre = $convocatoriaSkill->nombre;
            $convocatoriaSkillId = $convocatoriaSkill->id;
        } elseif (! empty($datos['parametro_id'])) {
            $parametro = Parametro::query()
                ->where('id', $datos['parametro_id'])
                ->whereHas('tema', fn ($q) => $q->where('codigo', self::TEMA_SKILL_CATALOGO))
                ->first();

            if (! $parametro) {
                throw ValidationException::withMessages([
                    'parametro_id' => 'Esa skill no hace parte del catálogo.',
                ]);
            }

            $nombre = $parametro->nombre;
            $parametroId = $parametro->id;
        } else {
            $nombre = trim((string) ($datos['nombre'] ?? ''));
        }

        if ($nombre === '') {
            throw ValidationException::withMessages([
                'nombre' => 'El nombre de la skill es obligatorio.',
            ]);
        }

        $max = (int) config('convocatorias.skills_postulacion_max');
        $actuales = PostulacionSkill::query()->where('postulacion_id', $postulacion->id)->count();

        if ($actuales >= $max) {
            throw ValidationException::withMessages([
                'nombre' => "No puedes registrar más de {$max} skills en tu postulación.",
            ]);
        }

        // Idempotente sin distinguir mayúsculas: si ya la tiene, se devuelve.
        $existente = PostulacionSkill::query()
            ->where('postulacion_id', $postulacion->id)
            ->whereRaw('LOWER(nombre) = ?', [mb_strtolower($nombre)])
            ->first();

        if ($existente) {
            return response()->json(['data' => $this->resumen($existente)], 200);
        }

        $skill = PostulacionSkill::create([
            'postulacion_id' => $postulacion->id,
            'convocatoria_skill_id' => $convocatoriaSkillId,
            'parametro_id' => $parametroId,
            'nombre' => $nombre,
        ]);

        return response()->json(['data' => $this->resumen($skill)], 201);
    }

    /** Quita una skill declarada (y su certificado, si lo tuviera). */
    public function destroy(Request $request, Postulacion $postulacion, PostulacionSkill $skill): JsonResponse
    {
        $this->exigirPropia($request, $postulacion);
        $this->exigirSkillDeLaPostulacion($postulacion, $skill);
        $this->exigirEditable($postulacion, 'skills');

        $disco = $skill->certificado_disco;
        $ruta = $skill->certificado_ruta;
        $skill->delete();
        $this->borrarArchivo($disco, $ruta);

        return response()->json(null, 204);
    }

    /** Certificado OPCIONAL: sube o reemplaza el de una skill declarada. */
    public function certificado(Request $request, Postulacion $postulacion, PostulacionSkill $skill): JsonResponse
    {
        $this->exigirPropia($request, $postulacion);
        $this->exigirSkillDeLaPostulacion($postulacion, $skill);
        $this->exigirEditable($postulacion, 'skills');

        return $this->guardarCertificado($request, $skill);
    }

    /**
     * Gestión documental del admin: sube o reemplaza el certificado de una skill
     * de un aspirante, sin exigir ventana ni estado editable.
     */
    public function certificadoGestion(Request $request, Postulacion $postulacion, PostulacionSkill $skill): JsonResponse
    {
        $this->exigirRevision($request, $postulacion, true);
        $this->exigirSkillDeLaPostulacion($postulacion, $skill);

        $respuesta = $this->guardarCertificado($request, $skill);

        Log::info('Gestión documental del administrador.', [
            'accion' => 'certificado_reemplazado',
            'actor_id' => $request->user()?->id,
            'postulacion_id' => $postulacion->id,
            'aspirante_id' => $postulacion->user_id,
            'skill_id' => $skill->id,
        ]);

        return $respuesta;
    }

    /** Quita el certificado de una skill (la skill se conserva). */
    public function quitarCertificado(Request $request, Postulacion $postulacion, PostulacionSkill $skill): JsonResponse
    {
        $this->exigirPropia($request, $postulacion);
        $this->exigirSkillDeLaPostulacion($postulacion, $skill);
        $this->exigirEditable($postulacion, 'skills');

        $this->limpiarCertificado($skill);

        return response()->json(null, 204);
    }

    /** Gestión documental del admin: quita el certificado sin exigir ventana. */
    public function quitarCertificadoGestion(Request $request, Postulacion $postulacion, PostulacionSkill $skill): JsonResponse
    {
        $this->exigirRevision($request, $postulacion, true);
        $this->exigirSkillDeLaPostulacion($postulacion, $skill);

        $this->limpiarCertificado($skill);

        Log::info('Gestión documental del administrador.', [
            'accion' => 'certificado_eliminado',
            'actor_id' => $request->user()?->id,
            'postulacion_id' => $postulacion->id,
            'aspirante_id' => $postulacion->user_id,
            'skill_id' => $skill->id,
        ]);

        return response()->json(null, 204);
    }

    /** Entrega el certificado (dueño). Por defecto attachment; ?inline=1 lo deja en la pestaña. */
    public function certificadoArchivo(Request $request, Postulacion $postulacion, PostulacionSkill $skill): Response
    {
        $this->exigirPropia($request, $postulacion);

        return $this->descargarCertificado($postulacion, $skill, $request->boolean('inline'));
    }

    /** Entrega el certificado (revisor con 'documentos:ver'). Igual que certificadoArchivo(): ?inline=1. */
    public function certificadoRevision(Request $request, Postulacion $postulacion, PostulacionSkill $skill): Response
    {
        $this->exigirRevision($request, $postulacion);

        return $this->descargarCertificado($postulacion, $skill, $request->boolean('inline'));
    }

    private function exigirSkillDeLaPostulacion(Postulacion $postulacion, PostulacionSkill $skill): void
    {
        if ($skill->postulacion_id !== $postulacion->id) {
            abort(404, 'Skill no encontrada.');
        }
    }

    /** Sube o reemplaza el certificado de la skill (dueño y gestor documental). */
    private function guardarCertificado(Request $request, PostulacionSkill $skill): JsonResponse
    {
        $request->validate(['archivo' => $this->reglasArchivo()], $this->mensajes(), $this->atributos());

        $nuevo = $this->guardarArchivo(
            $request->file('archivo'),
            "postulaciones/{$skill->postulacion_id}/skills",
        );
        $anterior = [$skill->certificado_disco, $skill->certificado_ruta];

        $skill->update([
            'certificado_nombre_original' => $nuevo['nombre_original'],
            'certificado_ruta' => $nuevo['ruta'],
            'certificado_disco' => $nuevo['disco'],
            'certificado_mime' => $nuevo['mime'],
            'certificado_tamano_bytes' => $nuevo['tamano_bytes'],
        ]);

        $this->borrarArchivo(...$anterior);

        return response()->json(['data' => $this->resumen($skill->fresh())], 200);
    }

    /** Borra el archivo del certificado y deja la skill sin certificado. */
    private function limpiarCertificado(PostulacionSkill $skill): void
    {
        if (! $skill->tieneCertificado()) {
            return;
        }

        $disco = $skill->certificado_disco;
        $ruta = $skill->certificado_ruta;

        $skill->update([
            'certificado_nombre_original' => null,
            'certificado_ruta' => null,
            'certificado_disco' => null,
            'certificado_mime' => null,
            'certificado_tamano_bytes' => null,
        ]);

        $this->borrarArchivo($disco, $ruta);
    }

    private function descargarCertificado(Postulacion $postulacion, PostulacionSkill $skill, bool $inline = false): Response
    {
        $this->exigirSkillDeLaPostulacion($postulacion, $skill);

        if (! $skill->tieneCertificado()) {
            abort(404, 'Certificado no encontrado.');
        }

        return $this->leerArchivo(
            $skill->certificado_disco,
            $skill->certificado_ruta,
            $skill->certificado_nombre_original,
            $skill->certificado_mime,
            $inline,
            'Certificado no encontrado.',
        );
    }

    private function resumen(PostulacionSkill $skill): array
    {
        return [
            'id' => $skill->id,
            'nombre' => $skill->nombre,
            'origen' => $skill->origen(),
            'certificado' => $skill->tieneCertificado()
                ? [
                    'id' => $skill->id,
                    'nombre_original' => $skill->certificado_nombre_original,
                    'mime' => $skill->certificado_mime,
                    'tamano_bytes' => $skill->certificado_tamano_bytes,
                ]
                : null,
        ];
    }

    /** @return array<string, string> */
    private function mensajes(): array
    {
        return [
            'string' => 'El campo :attribute debe ser texto.',
            'integer' => 'El campo :attribute no es válido.',
            'max' => 'El campo :attribute no debe superar :max caracteres.',
            'archivo.required' => 'El archivo es obligatorio.',
            'archivo.file' => 'El archivo no es válido.',
            'archivo.mimes' => 'El archivo debe ser un PDF.',
            'archivo.max' => 'El archivo no debe superar los '.config('convocatorias.tamano_maximo_mb').' MB.',
        ];
    }

    /** @return array<string, string> */
    private function atributos(): array
    {
        return [
            'nombre' => 'nombre de la skill',
            'convocatoria_skill_id' => 'skill',
            'parametro_id' => 'skill del catálogo',
            'archivo' => 'archivo',
        ];
    }
}
