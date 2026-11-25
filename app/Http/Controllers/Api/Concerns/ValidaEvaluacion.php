<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Enums\PostulacionEstado;
use App\Models\Parametro;
use App\Models\PerfilAspirante;
use App\Models\Postulacion;
use App\Services\Documentos\ReglaLibreta;
use App\Services\DocumentosDePostulacion;
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
        $autorizacion = app(DocumentosDePostulacion::class)->autorizacionRequerida($postulacion);
        // La autorización de un menor no pesa en el cumplimiento, pero sí cuenta como obligatoria para poder finalizar.
        $obligatorios = array_keys($this->pesosDocumentos($postulacion));
        if ($autorizacion) {
            $obligatorios[] = (int) $autorizacion->id;
        }

        $faltantes = collect($obligatorios)
            ->reject(fn (int $id) => in_array($id, $cargados, true))
            ->values();

        if ($faltantes->isEmpty()) {
            return;
        }

        $nombres = fn ($ids) => $postulacion->convocatoria->documentos->whereIn('id', $ids)->pluck('nombre')
            ->when($autorizacion && $ids->contains((int) $autorizacion->id), fn ($n) => $n->push($autorizacion->nombre))
            ->implode(', ');

        $marcados = $faltantes->filter(fn (int $id) => ! empty($perfil["doc-{$id}"]))->values();
        if ($marcados->isNotEmpty()) {
            abort(422, 'No puedes marcar «Cumple» un documento que el aspirante no ha cargado: '.$nombres($marcados).'.');
        }

        if ($estado === 'finalizada') {
            abort(422, 'No se puede finalizar la evaluación: faltan documentos obligatorios ('.$nombres($faltantes).'). Solicita la corrección al aspirante.');
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
     * Pesos (%) de los documentos OBLIGATORIOS y APLICABLES de la convocatoria,
     * indexados por id del parámetro (tipo_documento). Si la convocatoria no
     * configuró ponderaciones (todas null) se reparte el 100 % en partes
     * iguales, que es el comportamiento histórico. Si solo algunas están
     * configuradas (no debería pasar: el alta lo valida), el resto se reparte
     * con lo que sobra.
     *
     * @return array<int, float>
     */
    private function pesosDocumentos(Postulacion $postulacion): array
    {
        $documentos = $postulacion->convocatoria?->documentos ?? collect();

        if ($documentos->isEmpty()) {
            return [];
        }

        // Solo cuentan los documentos OBLIGATORIOS y APLICABLES, con la misma
        // regla que usa el envío (DocumentosDePostulacion): un documento
        // opcional no debe restar, y la libreta militar de una mujer no aplica.
        $perfil = PerfilAspirante::query()
            ->where('user_id', $postulacion->user_id)
            ->with('sexo')
            ->first();

        $libretaNoAplica = ReglaLibreta::noAplica($perfil, $postulacion->datosPersonales);

        $documentos = $documentos->filter(function (Parametro $documento) use ($libretaNoAplica) {
            if (! (bool) $documento->pivot->obligatorio) {
                return false;
            }

            return ! ($documento->codigo === Parametro::DOC_LIBRETA_MILITAR && $libretaNoAplica);
        });

        if ($documentos->isEmpty()) {
            return [];
        }

        $pesos = [];
        $sinPeso = [];
        $suma = 0.0;

        foreach ($documentos as $documento) {
            $valor = $documento->pivot->ponderacion;

            if ($valor === null) {
                $sinPeso[] = (int) $documento->id;

                continue;
            }

            $pesos[(int) $documento->id] = (float) $valor;
            $suma += (float) $valor;
        }

        if ($sinPeso === []) {
            return $pesos;
        }

        $cuota = max(0.0, 100.0 - $suma) / count($sinPeso);

        foreach ($sinPeso as $id) {
            $pesos[$id] = $cuota;
        }

        return $pesos;
    }

    /**
     * Cumplimiento ponderado (0-100) a partir de lo marcado por el evaluador.
     *
     * El `perfil` llega como { "doc-{parametro_id}": true|false } desde la
     * pantalla de evaluación. Se suma el peso de cada documento OBLIGATORIO y
     * APLICABLE marcado como cumplido sobre el total de pesos configurados.
     */
    private function calcularCumplimiento(Postulacion $postulacion, ?array $perfil): ?int
    {
        $pesos = $this->pesosDocumentos($postulacion);

        if ($pesos === []) {
            return null;
        }

        $total = array_sum($pesos);

        if ($total <= 0) {
            return null;
        }

        $obtenido = 0.0;
        foreach ($pesos as $parametroId => $peso) {
            if (! empty($perfil["doc-{$parametroId}"])) {
                $obtenido += $peso;
            }
        }

        return (int) round(min(100.0, ($obtenido / $total) * 100));
    }
}
