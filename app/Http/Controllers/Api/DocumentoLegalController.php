<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\AceptacionLegal;
use App\Models\DocumentoLegal;
use App\Services\Legal\GestorTextosLegales;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Versiones de textos legales. Crear, editar borradores y aprobar es solo del superadministrador (se comprueba aquí; no se añade un
 * permiso general). Cualquier usuario autenticado consulta las versiones aprobadas vigentes. La aprobación es administrativa: no
 * certifica la validez jurídica del texto. Detrás de la bandera legal.aceptaciones_activas (apagada por defecto).
 */
class DocumentoLegalController extends Controller
{
    private const NOTA = 'La aprobación es administrativa y no equivale a una certificación jurídica del texto.';

    /** Todas las versiones, con sus borradores (solo superadmin). */
    public function index(Request $request): JsonResponse
    {
        $this->soloSuperAdmin($request);

        return response()->json(['data' => DocumentoLegal::query()->orderBy('tipo')->orderByDesc('id')->get()->map(fn ($d) => $this->presentar($d))->all()]);
    }

    /** La última versión aprobada de cada tipo, con si el usuario autenticado ya la aceptó. */
    public function vigentes(Request $request): JsonResponse
    {
        $aceptadas = AceptacionLegal::where('user_id', $request->user()->id)->pluck('documento_legal_id')->all();
        $vigentes = DocumentoLegal::query()->where('estado', DocumentoLegal::APROBADO)->orderByDesc('aprobado_en')->orderByDesc('id')->get()
            ->unique('tipo')->values();

        return response()->json(['data' => $vigentes->map(fn ($d) => $this->presentar($d) + ['aceptada_por_mi' => in_array($d->id, $aceptadas, true)])->all()]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->soloSuperAdmin($request);
        $datos = $request->validate($this->reglas(), $this->mensajes());
        $this->versionLibre($datos['tipo'], $datos['version']);
        $doc = (new DocumentoLegal)->fill($datos);
        $doc->forceFill(['estado' => DocumentoLegal::BORRADOR, 'creado_por' => $request->user()->id])->save();

        return response()->json(['data' => $this->presentar($doc)], 201);
    }

    /** Edita un BORRADOR. Una versión aprobada no se toca. */
    public function update(Request $request, DocumentoLegal $documento): JsonResponse
    {
        $this->soloSuperAdmin($request);
        if ($documento->estaAprobado()) {
            throw ValidationException::withMessages(['documento' => 'Una versión aprobada no se puede modificar: crea una nueva versión.']);
        }
        $datos = $request->validate($this->reglas(), $this->mensajes());
        $this->versionLibre($datos['tipo'], $datos['version'], $documento->id);
        $documento->fill($datos)->save();

        return response()->json(['data' => $this->presentar($documento)]);
    }

    public function aprobar(Request $request, DocumentoLegal $documento, GestorTextosLegales $gestor): JsonResponse
    {
        $this->soloSuperAdmin($request);

        return response()->json(['data' => $this->presentar($gestor->aprobar($documento, $request->user()))]);
    }

    private function soloSuperAdmin(Request $request): void
    {
        abort_unless($request->user()->role === Role::SuperAdmin, 403, 'Solo el superadministrador gestiona los textos legales.');
    }

    /** @return array<string, mixed> */
    private function reglas(): array
    {
        return [
            'tipo' => ['required', Rule::in(DocumentoLegal::TIPOS)],
            'version' => ['required', 'string', 'max:20', 'regex:/^[0-9A-Za-z][0-9A-Za-z.\-]*$/'],
            'titulo' => ['required', 'string', 'max:150'],
            'contenido' => ['required', 'string', 'max:200000'],
        ];
    }

    /** @return array<string, string> */
    private function mensajes(): array
    {
        return [
            'tipo.required' => 'Elige el tipo de texto.', 'tipo.in' => 'El tipo de texto no es válido.',
            'version.required' => 'Escribe la versión.', 'version.regex' => 'La versión solo admite letras, números, puntos y guiones.',
            'version.max' => 'La versión admite hasta 20 caracteres.',
            'titulo.required' => 'Escribe el título.', 'titulo.max' => 'El título admite hasta 150 caracteres.',
            'contenido.required' => 'Escribe el contenido.', 'contenido.max' => 'El contenido es demasiado largo.',
        ];
    }

    private function versionLibre(string $tipo, string $version, ?int $excepto = null): void
    {
        $existe = DocumentoLegal::where('tipo', $tipo)->where('version', $version)->when($excepto, fn ($q) => $q->where('id', '!=', $excepto))->exists();
        if ($existe) {
            throw ValidationException::withMessages(['version' => 'Ya existe esa versión de este texto.']);
        }
    }

    /** @return array<string, mixed> */
    private function presentar(DocumentoLegal $d): array
    {
        return [
            'id' => $d->id, 'tipo' => $d->tipo, 'version' => $d->version, 'titulo' => $d->titulo, 'contenido' => $d->contenido,
            'estado' => $d->estado, 'huella' => $d->huella, 'aprobado_en' => $d->aprobado_en?->toIso8601String(),
            'aprobado_por' => $d->aprobado_por_nombre, 'nota' => self::NOTA,
        ];
    }
}
