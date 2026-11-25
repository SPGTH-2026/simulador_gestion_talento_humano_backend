<?php

namespace App\Services\Legal;

use App\Models\AceptacionLegal;
use App\Models\DocumentoLegal;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Reglas de las versiones de textos legales y de sus aceptaciones. La aprobación es administrativa (no certifica la validez
 * jurídica del texto) y la aceptación solo la hace el propio usuario autenticado sobre una versión aprobada.
 */
final class GestorTextosLegales
{
    /** Aprueba una versión: queda sellada (huella) e inmutable. Aprobar de nuevo una ya aprobada es un error, no un cambio. */
    public function aprobar(DocumentoLegal $documento, User $actor): DocumentoLegal
    {
        return DB::transaction(function () use ($documento, $actor) {
            $doc = DocumentoLegal::query()->lockForUpdate()->findOrFail($documento->id);
            if ($doc->estaAprobado()) {
                throw ValidationException::withMessages(['documento' => 'Esta versión ya está aprobada y no se puede cambiar.']);
            }
            $doc->forceFill([
                'estado' => DocumentoLegal::APROBADO,
                'huella' => DocumentoLegal::calcularHuella($doc->titulo, $doc->contenido),
                'aprobado_en' => now(),
                'aprobado_por' => $actor->id,
                'aprobado_por_nombre' => $actor->name,
                'aprobado_por_correo' => $actor->email,
            ])->save();

            return $doc;
        });
    }

    /**
     * Registra que `$usuario` aceptó la versión. Idempotente por usuario y versión: repetirlo devuelve la aceptación ya hecha
     * (`[$aceptacion, false]`) sin crear otra. Un borrador no se puede aceptar. Devuelve `[aceptación, creada]`.
     *
     * @return array{0: AceptacionLegal, 1: bool}
     */
    public function aceptar(User $usuario, DocumentoLegal $documento): array
    {
        if (! $documento->estaAprobado()) {
            throw ValidationException::withMessages(['documento' => 'Solo se pueden aceptar versiones aprobadas.']);
        }
        $existente = fn () => AceptacionLegal::where('user_id', $usuario->id)->where('documento_legal_id', $documento->id)->first();
        if ($previa = $existente()) {
            return [$previa, false];
        }
        try {
            $nueva = (new AceptacionLegal)->forceFill([
                'documento_legal_id' => $documento->id,
                'user_id' => $usuario->id,
                'usuario_nombre' => $usuario->name,
                'usuario_correo' => $usuario->email,
                'huella_aceptada' => $documento->huella,
                'aceptado_en' => now(),
            ]);
            $nueva->save();

            return [$nueva, true];
        } catch (UniqueConstraintViolationException) {
            // Dos peticiones a la vez: gana una y la otra devuelve lo que ya quedó registrado.
            return [$existente(), false];
        }
    }
}
