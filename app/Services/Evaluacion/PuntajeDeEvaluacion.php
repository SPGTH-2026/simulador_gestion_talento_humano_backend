<?php

namespace App\Services\Evaluacion;

use App\Models\Parametro;
use App\Models\PerfilAspirante;
use App\Models\Postulacion;
use App\Services\Documentos\ReglaLibreta;

/**
 * Cómo se calcula el cumplimiento (0-100) de una postulación. Es la única fuente de la regla: el controlador y la pantalla de
 * evaluación (que recibe los puntos de cada elemento) salen de aquí.
 *
 *  - Hay dos bloques. Las HABILIDADES de la convocatoria valen 10 puntos y los DOCUMENTOS valen los otros 90. Si la convocatoria
 *    no pide habilidades, los documentos valen los 100.
 *  - Dentro de cada bloque, cada elemento vale lo que el gestor le puso (se leen relativos a la suma de su bloque: un documento
 *    de «100 %» entre documentos vale los 90 puntos). Sin porcentajes, el bloque se reparte en partes iguales.
 *  - Un documento con checklist aporta su parte según los ítems en «Cumple» (4 de 6 = 4/6 de sus puntos). Sin checklist, aporta
 *    todo o nada según «Cumple» / «No cumple».
 *  - Una habilidad solo suma si el aspirante la declaró y el evaluador la marcó «Cumple». Las no declaradas no suman ni restan:
 *    sin habilidades declaradas, el tope es el bloque de documentos.
 */
final class PuntajeDeEvaluacion
{
    /** Puntos del bloque de habilidades cuando la convocatoria las pide. */
    public const PUNTOS_HABILIDADES = 10;

    /**
     * Pesos relativos de los documentos OBLIGATORIOS y APLICABLES de la convocatoria, por id del parámetro (tipo_documento).
     * Sin porcentajes configurados todos pesan igual; si solo algunos los tienen, el resto se reparte lo que sobra.
     *
     * @return array<int, float>
     */
    public function pesosDocumentos(Postulacion $postulacion): array
    {
        $documentos = $postulacion->convocatoria?->documentos ?? collect();

        if ($documentos->isEmpty()) {
            return [];
        }

        // Misma regla que usa el envío (DocumentosDePostulacion): un documento opcional no cuenta y la libreta militar no aplica
        // a quien no la debe.
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

        return $this->repartirSobrantes(
            $documentos->mapWithKeys(fn (Parametro $d) => [(int) $d->id => $d->pivot->ponderacion])->all(),
        );
    }

    /**
     * Pesos relativos de TODAS las habilidades que pide la convocatoria, por id de la skill de la convocatoria.
     *
     * @return array<int, float>
     */
    public function pesosHabilidades(Postulacion $postulacion): array
    {
        $skills = $postulacion->convocatoria?->skills ?? collect();

        return $this->repartirSobrantes(
            $skills->mapWithKeys(fn ($s) => [(int) $s->id => $s->ponderacion])->all(),
        );
    }

    /**
     * Puntos máximos de cada elemento y de cada bloque (suman 100 cuando hay documentos).
     *
     * @return array{bloque_documentos: float, bloque_habilidades: float, documentos: array<int, float>, habilidades: array<int, float>}
     */
    public function puntos(Postulacion $postulacion): array
    {
        $habilidades = $this->pesosHabilidades($postulacion);
        $bloqueHabilidades = $habilidades === [] ? 0.0 : (float) self::PUNTOS_HABILIDADES;
        $bloqueDocumentos = 100.0 - $bloqueHabilidades;

        return [
            'bloque_documentos' => $bloqueDocumentos,
            'bloque_habilidades' => $bloqueHabilidades,
            'documentos' => $this->escalar($this->pesosDocumentos($postulacion), $bloqueDocumentos),
            'habilidades' => $this->escalar($habilidades, $bloqueHabilidades),
        ];
    }

    /**
     * Cumplimiento (0-100, con dos decimales) según lo que marcó el evaluador en `perfil`: «doc-{id}», «docitem-{id}-{n}» y «skill-{id}».
     * Null si la convocatoria no tiene documentos obligatorios aplicables (no hay base para decidir).
     */
    public function cumplimiento(Postulacion $postulacion, ?array $perfil): ?float
    {
        $puntos = $this->puntos($postulacion);

        if ($puntos['documentos'] === []) {
            return null;
        }

        $obtenido = 0.0;
        foreach ($puntos['documentos'] as $documentoId => $maximo) {
            $obtenido += $maximo * $this->fraccionDocumento($postulacion, $documentoId, $perfil);
        }

        $declaradas = $postulacion->skills->pluck('convocatoria_skill_id')->filter()->map(fn ($id) => (int) $id)->all();
        foreach ($puntos['habilidades'] as $skillId => $maximo) {
            if (in_array($skillId, $declaradas, true) && ! empty($perfil["skill-{$skillId}"])) {
                $obtenido += $maximo;
            }
        }

        // Con dos decimales: el total es justo (96,67 %), no se redondea al entero más cercano.
        return round(min(100.0, $obtenido), 2);
    }

    /**
     * Ítems del checklist de un documento (una línea cada uno). Vacío = el documento no tiene checklist.
     *
     * @return list<string>
     */
    public function itemsDeDocumento(Postulacion $postulacion, int $documentoId): array
    {
        $texto = $postulacion->convocatoria?->documentos?->firstWhere('id', $documentoId)?->pivot?->items;

        if ($texto === null) {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode("\n", (string) $texto)), fn ($linea) => $linea !== ''));
    }

    /** ¿El evaluador marcó este documento como cumplido (el documento entero o alguno de sus ítems)? */
    public function documentoMarcado(?array $perfil, int $documentoId): bool
    {
        if (! empty($perfil["doc-{$documentoId}"])) {
            return true;
        }

        foreach ($perfil ?? [] as $clave => $valor) {
            if ($valor && str_starts_with((string) $clave, "docitem-{$documentoId}-")) {
                return true;
            }
        }

        return false;
    }

    /** Parte del documento que aporta (0 a 1): ítems «Cumple» / total; sin checklist, todo o nada según su marca. */
    private function fraccionDocumento(Postulacion $postulacion, int $documentoId, ?array $perfil): float
    {
        $items = $this->itemsDeDocumento($postulacion, $documentoId);

        if ($items === []) {
            return empty($perfil["doc-{$documentoId}"]) ? 0.0 : 1.0;
        }

        $cumplen = 0;
        foreach (array_keys($items) as $indice) {
            if (! empty($perfil["docitem-{$documentoId}-{$indice}"])) {
                $cumplen++;
            }
        }

        return $cumplen / count($items);
    }

    /**
     * Completa los pesos que faltan: sin ninguno configurado todos pesan 1; con algunos, los demás reparten lo que sobra de 100.
     *
     * @param  array<int, mixed>  $crudos  id => ponderación (null = sin configurar)
     * @return array<int, float>
     */
    private function repartirSobrantes(array $crudos): array
    {
        if ($crudos === []) {
            return [];
        }

        $pesos = [];
        $sinPeso = [];
        $suma = 0.0;

        foreach ($crudos as $id => $valor) {
            if ($valor === null) {
                $sinPeso[] = $id;

                continue;
            }

            $pesos[$id] = (float) $valor;
            $suma += (float) $valor;
        }

        if (count($sinPeso) === count($crudos)) {
            return array_fill_keys($sinPeso, 1.0);
        }

        $cuota = $sinPeso === [] ? 0.0 : max(0.0, 100.0 - $suma) / count($sinPeso);
        foreach ($sinPeso as $id) {
            $pesos[$id] = $cuota;
        }

        return $pesos;
    }

    /**
     * Lleva los pesos relativos a puntos de un bloque (la suma de los puntos es el bloque).
     *
     * @param  array<int, float>  $pesos
     * @return array<int, float>
     */
    private function escalar(array $pesos, float $bloque): array
    {
        $suma = array_sum($pesos);

        if ($pesos === [] || $suma <= 0) {
            return [];
        }

        return array_map(fn (float $peso) => round($peso / $suma * $bloque, 4), $pesos);
    }
}
