<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Enums\PostulacionEstado;
use App\Models\Postulacion;
use App\Services\Evaluacion\PuntajeDeEvaluacion;
use Illuminate\Http\Request;

trait ValidaEvaluacion
{
    private function autorizar(Request $request, Postulacion $postulacion): void
    {
        $fichaId = $postulacion->convocatoria?->ficha_id;
        // Fuera de alcance se responde como si no existiera (404), para no revelar qué postulaciones hay en otras fichas.
        if (! $fichaId || ! in_array((int) $fichaId, $request->user()->fichaIds(), true)) {
            abort(404, 'Postulación no encontrada.');
        }
    }

    /**
     * Solo las postulaciones enviadas (postulada) se evalúan. Un borrador es un
     * formulario incompleto que el aspirante aún no confirmó, y una retirada ya
     * no participa del proceso. La validación vive en el servidor: la interfaz
     * solo es un reflejo de esta regla.
     */
    private function exigirPostulada(Postulacion $postulacion): void
    {
        $postulacion->loadMissing('estado');

        if ($postulacion->estado?->codigo !== PostulacionEstado::Postulada->value) {
            abort(422, 'Solo se pueden evaluar postulaciones enviadas. Esta postulación no ha sido enviada o fue retirada.');
        }
    }

    /**
     * Documentos obligatorios aplicables que el aspirante no tiene cargados. Marcar «Cumple» un documento sin archivo
     * se rechaza siempre; finalizar la evaluación con alguno faltante también (se solicita la corrección). Guardar en
     * proceso o pedir una corrección con el faltante marcado «No» sí se permite.
     */
    private function exigirDocumentosCargados(Postulacion $postulacion, ?array $perfil, string $estado): void
    {
        $postulacion->loadMissing('documentos', 'convocatoria.documentos');

        $cargados = $postulacion->documentos->whereNotNull('parametro_id')->pluck('parametro_id')->map(fn ($id) => (int) $id)->all();
        $faltantes = collect(array_keys($this->pesosDocumentos($postulacion)))
            ->reject(fn (int $id) => in_array($id, $cargados, true))
            ->values();

        if ($faltantes->isEmpty()) {
            return;
        }

        $nombres = fn ($ids) => $postulacion->convocatoria->documentos->whereIn('id', $ids)->pluck('nombre')->implode(', ');

        $puntaje = app(PuntajeDeEvaluacion::class);
        $marcados = $faltantes->filter(fn (int $id) => $puntaje->documentoMarcado($perfil, $id))->values();
        if ($marcados->isNotEmpty()) {
            abort(422, 'No puedes marcar «Cumple» un documento que el aspirante no ha cargado: '.$nombres($marcados).'.');
        }

        if ($estado === 'finalizada') {
            abort(422, 'No se puede finalizar la evaluación: faltan documentos obligatorios ('.$nombres($faltantes).'). Solicita la corrección al aspirante.');
        }
    }

    /**
     * Para FINALIZAR no puede quedar nada sin calificar: cada documento (o cada ítem de su checklist) y cada habilidad que el
     * aspirante declaró deben tener «Cumple» o «No cumple». Y si algo quedó en «No cumple», las observaciones explican el motivo.
     * Un borrador o una solicitud de corrección sí pueden quedar a medias.
     */
    private function exigirEvaluacionCompleta(Postulacion $postulacion, ?array $perfil, ?string $observaciones, string $estado): void
    {
        if ($estado !== 'finalizada') {
            return;
        }

        $postulacion->loadMissing('convocatoria.documentos', 'convocatoria.skills', 'skills');
        $puntaje = app(PuntajeDeEvaluacion::class);
        $puntos = $puntaje->puntos($postulacion);
        $respondido = fn (string $clave) => is_bool($perfil[$clave] ?? null);
        $sinCalificar = [];

        foreach (array_keys($puntos['documentos']) as $documentoId) {
            $nombre = (string) $postulacion->convocatoria->documentos->firstWhere('id', $documentoId)?->nombre;
            $items = $puntaje->itemsDeDocumento($postulacion, $documentoId);

            if ($items === []) {
                if (! $respondido("doc-{$documentoId}")) {
                    $sinCalificar[] = $nombre;
                }

                continue;
            }

            $faltan = collect(array_keys($items))->filter(fn (int $n) => ! $respondido("docitem-{$documentoId}-{$n}"))->count();
            if ($faltan > 0) {
                $sinCalificar[] = $nombre.' ('.$faltan.($faltan === 1 ? ' ítem' : ' ítems').')';
            }
        }

        $declaradas = $postulacion->skills->pluck('convocatoria_skill_id')->filter()->map(fn ($id) => (int) $id)->all();
        foreach (array_keys($puntos['habilidades']) as $skillId) {
            if (in_array($skillId, $declaradas, true) && ! $respondido("skill-{$skillId}")) {
                $sinCalificar[] = (string) $postulacion->convocatoria->skills->firstWhere('id', $skillId)?->nombre;
            }
        }

        if ($sinCalificar !== []) {
            abort(422, 'No se puede finalizar: faltan por calificar '.implode(', ', $sinCalificar).'.');
        }

        $hayNoCumple = collect($perfil ?? [])->contains(fn ($valor, $clave) => $valor === false
            && preg_match('/^(doc|docitem|skill)-/', (string) $clave) === 1);

        if ($hayNoCumple && trim((string) $observaciones) === '') {
            abort(422, 'Hay requisitos marcados «No cumple»: explica el motivo en las observaciones antes de finalizar.');
        }
    }

    /** Perfil con las claves en orden estable: el orden en que llegan o se guardan no es contenido. */
    private function canonicoPerfil(?array $perfil): ?array
    {
        if ($perfil === null) {
            return null;
        }
        ksort($perfil);

        return $perfil;
    }

    /**
     * Criterios en forma comparable (texto, puntaje y peso a dos decimales, sin orden), tanto si vienen de la solicitud
     * como si son filas guardadas.
     *
     * @param  array<int, mixed>  $criterios
     * @return array<int, array{string, float, float}>
     */
    private function canonicoCriterios(array $criterios): array
    {
        $lista = array_map(fn ($c) => [
            (string) data_get($c, 'criterio'),
            round((float) data_get($c, 'puntaje'), 2),
            round((float) (data_get($c, 'ponderacion') ?? 1), 2),
        ], $criterios);
        sort($lista);

        return $lista;
    }

    /**
     * Pesos relativos de los documentos obligatorios y aplicables (ver PuntajeDeEvaluacion).
     *
     * @return array<int, float>
     */
    private function pesosDocumentos(Postulacion $postulacion): array
    {
        return app(PuntajeDeEvaluacion::class)->pesosDocumentos($postulacion);
    }

    /**
     * Cumplimiento (0-100) a partir de lo marcado por el evaluador. La regla completa (90 % documentos, 10 % habilidades
     * declaradas, checklist por documento) vive en PuntajeDeEvaluacion.
     */
    private function calcularCumplimiento(Postulacion $postulacion, ?array $perfil): ?float
    {
        return app(PuntajeDeEvaluacion::class)->cumplimiento($postulacion, $perfil);
    }
}
