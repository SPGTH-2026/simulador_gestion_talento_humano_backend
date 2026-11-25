<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Models\Convocatoria;
use App\Models\Parametro;
use Illuminate\Validation\ValidationException;

trait DocumentosDeConvocatoria
{
    /**
     * Documentos válidos del tema tipo_documento enviados por el gestor. Acepta
     * dos formatos por ítem:
     *  - clásico: un código (string) o un id (int).
     *  - extendido: ['codigo'|'id' => ..., 'ponderacion' => 0-100]. El valor
     *    'obligatorio' se IGNORA: la obligatoriedad la decide el backend
     *    (config/convocatorias.php), no el cliente.
     * La ponderación SÍ se respeta: es la configurada por el gestor para esta
     * convocatoria (null = no configurada, reparto equitativo en evaluación).
     * Devuelve una lista de ['id' => int, 'ponderacion' => float|null] sin
     * duplicados (gana la primera aparición), o null si no se envió la clave.
     */
    private function normalizarDocumentos(?array $documentos, array &$errores): ?array
    {
        if ($documentos === null) {
            return null;
        }

        $normalizados = [];
        foreach (array_values($documentos) as $indice => $documento) {
            $referencia = $documento;
            $ponderacion = null;

            if (is_array($documento)) {
                // El 'obligatorio' del formato extendido se acepta pero se ignora.
                $referencia = $documento['codigo'] ?? $documento['id'] ?? null;

                $valor = $documento['ponderacion'] ?? null;
                if ($valor !== null && $valor !== '') {
                    if (! is_numeric($valor) || (float) $valor < 0 || (float) $valor > 100) {
                        $errores["documentos.{$indice}"][] = 'El porcentaje del documento debe ser un número entre 0 y 100.';

                        continue;
                    }
                    $ponderacion = round((float) $valor, 2);
                }
            }

            $parametro = null;

            if (is_int($referencia)) {
                // Un documento creado por otra ficha no es válido aquí, igual que si no existiera.
                $parametro = Parametro::query()
                    ->whereKey($referencia)
                    ->whereHas('tema', fn ($q) => $q->where('codigo', 'tipo_documento'))
                    ->where('activo', true)
                    ->asignablesAConvocatoria()
                    ->when(request()->user(), fn ($q, $usuario) => $q->visiblesPara($usuario))
                    ->first();
            } elseif (is_string($referencia) && $referencia !== '') {
                $parametro = Parametro::porTemaYCodigo('tipo_documento', $referencia);
                if ($parametro && (! $parametro->activo || $parametro->codigo === Parametro::DOC_AUTORIZACION_REPRESENTANTE)) {
                    $parametro = null;
                }
            }

            if (! $parametro) {
                $errores["documentos.{$indice}"][] = 'El documento seleccionado no es válido.';
                continue;
            }

            $id = (int) $parametro->id;
            if (! array_key_exists($id, $normalizados)) {
                $normalizados[$id] = ['id' => $id, 'ponderacion' => $ponderacion];
            }
        }

        return array_values($normalizados);
    }

    /**
     * Valida la configuración de porcentajes de los documentos.
     *
     * Reglas:
     *  - Sin ninguna ponderación enviada: válido. La convocatoria queda sin
     *    configurar y la evaluación reparte el 100 % en partes iguales.
     *  - Con ponderaciones: TODOS los documentos deben tener la suya y la suma
     *    debe ser 100 % (±0,01 por redondeos de dos decimales).
     */
    private function validarPonderaciones(array $documentos, array &$errores): void
    {
        $configurados = array_filter(
            $documentos,
            fn ($documento) => ($documento['ponderacion'] ?? null) !== null,
        );

        if ($configurados === []) {
            return;
        }

        if (count($configurados) !== count($documentos)) {
            $errores['documentos'][] = 'Define el porcentaje de todos los documentos: deben sumar 100%.';

            return;
        }

        $suma = array_sum(array_map(
            fn ($documento) => (float) $documento['ponderacion'],
            $documentos,
        ));

        if (abs($suma - 100) > 0.01) {
            $errores['documentos'][] = 'Los porcentajes de los documentos deben sumar 100% (suman '
                .number_format($suma, 2, ',', '.').'%).';
        }
    }

    /**
     * Combina los documentos elegidos por el gestor con los siempre
     * obligatorios (config/convocatorias.php). TODO documento que el gestor
     * incluya queda obligatorio: si se le puede asignar un porcentaje, debe
     * pesar en la evaluación y exigirse al aspirante. Los obligatorios fijos
     * van primero y siempre, aunque el gestor no los haya enviado. Sin
     * duplicados.
     *
     * La ponderación enviada por el gestor se conserva; los obligatorios que el
     * backend agrega por su cuenta quedan sin ponderación (null) y se validan
     * después en validarPonderaciones.
     *
     * @param  array<int, array{id:int, ponderacion:float|null}>|null  $documentos
     * @return array<int, array{id:int, obligatorio:bool, ponderacion:float|null}>
     */
    private function conDocumentosObligatorios(?array $documentos): array
    {
        $obligatorios = array_fill_keys($this->idsDocumentosObligatorios(), true);

        $final = [];

        // 1) Los siempre obligatorios, en el orden de config y con true.
        foreach (array_keys($obligatorios) as $id) {
            $final[$id] = ['id' => $id, 'obligatorio' => true, 'ponderacion' => null];
        }

        // 2) Los elegidos por el gestor (sin repetir los obligatorios). TODO
        //    documento incluido queda obligatorio: un documento nuevo del
        //    catálogo con porcentaje debe contar en el 60 % y exigirse.
        foreach ($documentos ?? [] as $documento) {
            $id = (int) $documento['id'];
            $ponderacion = $documento['ponderacion'] ?? null;

            if (array_key_exists($id, $final)) {
                $final[$id]['ponderacion'] = $ponderacion;

                continue;
            }

            $final[$id] = [
                'id' => $id,
                'obligatorio' => true,
                'ponderacion' => $ponderacion,
            ];
        }

        return array_values($final);
    }

    /**
     * IDs de los documentos SIEMPRE obligatorios, resueltos por código desde
     * config/convocatorias.php. Se ignoran los códigos ausentes del catálogo.
     *
     * @return array<int, int>
     */
    private function idsDocumentosObligatorios(): array
    {
        $ids = [];

        foreach ((array) config('convocatorias.documentos_obligatorios', []) as $codigo) {
            $parametro = Parametro::porTemaYCodigo('tipo_documento', (string) $codigo);

            if ($parametro && $parametro->activo) {
                $ids[(int) $parametro->id] = true;
            }
        }

        return array_keys($ids);
    }

    /**
     * Reemplaza los documentos de la convocatoria. La lista recibida ya viene
     * fusionada (conDocumentosObligatorios): los obligatorios no se pueden quitar
     * y se guardan con obligatorio = true. La ponderación es la configurada por
     * el gestor para esta convocatoria (null = sin configurar).
     */
    private function reemplazarDocumentos(Convocatoria $convocatoria, ?array $documentos): void
    {
        $sincronizar = [];
        foreach ($documentos ?? [] as $documento) {
            $sincronizar[(int) $documento['id']] = [
                'obligatorio' => (bool) ($documento['obligatorio'] ?? false),
                'ponderacion' => $documento['ponderacion'] ?? null,
            ];
        }

        $convocatoria->documentos()->sync($sincronizar);
    }

    /**
     * Con postulaciones, los documentos requeridos no se pueden agregar, quitar ni
     * cambiar sus porcentajes. La obligatoriedad no la envía el cliente (la decide
     * el backend), así que no puede cambiarse por esta vía. Se compara el conjunto
     * enviado contra el guardado, sin reinyectar obligatorios.
     */
    private function bloquearDocumentosConPostulaciones(Convocatoria $actual, array $documentos): void
    {
        $guardados = $actual->documentos()->get()
            ->mapWithKeys(fn ($parametro) => [
                (int) $parametro->id => $parametro->pivot->ponderacion === null
                    ? null
                    : round((float) $parametro->pivot->ponderacion, 2),
            ])
            ->all();

        $nuevos = [];
        foreach ($documentos as $documento) {
            $ponderacion = $documento['ponderacion'] ?? null;

            $nuevos[(int) $documento['id']] = $ponderacion === null
                ? null
                : round((float) $ponderacion, 2);
        }

        ksort($guardados);
        ksort($nuevos);

        if ($guardados !== $nuevos) {
            throw ValidationException::withMessages([
                'documentos' => ['No se pueden agregar, quitar ni cambiar la obligatoriedad de los documentos porque la convocatoria ya tiene postulaciones.'],
            ]);
        }
    }
}
