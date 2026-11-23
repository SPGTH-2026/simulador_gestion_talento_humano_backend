<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Models\Evaluacion;
use App\Models\Parametro;
use App\Models\Postulacion;
use App\Models\PostulacionDocumento;
use App\Support\RutaDocumento;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

trait GuardaDocumentos
{
    /** Sube o reemplaza el documento requerido del código indicado. */
    private function guardarRequerido(Request $request, Postulacion $postulacion): JsonResponse
    {
        $datos = $request->validate([
            'codigo' => ['nullable', 'string', 'required_without:parametro_id'],
            'parametro_id' => ['nullable', 'integer', 'required_without:codigo'],
            'archivo' => $this->reglasArchivo(),
        ], $this->mensajes(), $this->atributos());

        // Un documento del catálogo puede identificarse por código (documentos
        // de sistema) o por id (documentos NUEVOS creados por el gestor, que
        // nacen sin código). Se resuelve por código solo si vino.
        $campo = empty($datos['parametro_id']) ? 'codigo' : 'parametro_id';

        $parametro = empty($datos['parametro_id'])
            ? Parametro::porTemaYCodigo('tipo_documento', $datos['codigo'])
            : Parametro::query()
                ->whereKey($datos['parametro_id'])
                ->whereHas('tema', fn ($q) => $q->where('codigo', 'tipo_documento'))
                ->first();

        if (! $parametro || ! $this->convocatoriaPide($postulacion, $parametro->id)) {
            throw ValidationException::withMessages([
                $campo => 'Ese documento no hace parte de esta convocatoria.',
            ]);
        }

        if ($parametro->codigo === Parametro::DOC_LIBRETA_MILITAR) {
            $this->exigirLibretaAplicable($postulacion);
        }

        // Durante una corrección solo se reemplaza un documento marcado con «Corregir».
        $correccion = $this->correccionVigente($postulacion);
        if ($correccion !== null && ! in_array((int) $parametro->id, array_map('intval', $correccion->documentos_marcados ?? []), true)) {
            throw ValidationException::withMessages([
                $campo => 'Durante la corrección solo puedes reemplazar los documentos que el evaluador marcó.',
            ]);
        }

        $nuevo = $this->guardarArchivo(
            $request->file('archivo'),
            RutaDocumento::carpeta($postulacion, 'documentos'),
        );

        // Búsqueda y escritura bajo el bloqueo de la postulación: dos subidas simultáneas del mismo documento no pueden
        // crear dos filas. El archivo anterior se borra solo después de confirmar el cambio.
        [$documento, $codigoHttp, $anterior] = DB::transaction(function () use ($postulacion, $parametro, $nuevo) {
            Postulacion::query()->whereKey($postulacion->id)->lockForUpdate()->first();

            $existente = PostulacionDocumento::query()
                ->where('postulacion_id', $postulacion->id)
                ->where('parametro_id', $parametro->id)
                ->first();

            if ($existente) {
                $anteriorArchivo = [$existente->disco, $existente->ruta];
                $existente->update($nuevo);

                return [$existente->fresh(), 200, $anteriorArchivo];
            }

            $creado = PostulacionDocumento::create($nuevo + [
                'postulacion_id' => $postulacion->id,
                'parametro_id' => $parametro->id,
            ]);

            return [$creado, 201, null];
        });

        if ($anterior !== null) {
            $this->borrarArchivo(...$anterior);
        }

        $this->invalidarEvaluacionesPorCambioDocumental($postulacion, $documento);

        return response()->json(['data' => $this->archivoResumen($documento)], $codigoHttp);
    }

    /**
     * Agrega un adicional. Con `documento_id` reemplaza el archivo (y el
     * título/nota si se envían) de ese adicional existente, sin gastar cupo.
     */
    private function guardarAdicional(Request $request, Postulacion $postulacion): JsonResponse
    {
        $maxAdicionales = (int) config('convocatorias.documentos_adicionales_max');

        $datos = $request->validate([
            'documento_id' => ['nullable', 'integer'],
            'titulo' => ['required', 'string', 'max:'.config('convocatorias.titulo_adicional_max_longitud')],
            'nota' => ['nullable', 'string', 'max:'.config('convocatorias.nota_adicional_max_longitud')],
            'archivo' => $this->reglasArchivo(),
        ], $this->mensajes(), $this->atributos());

        $reemplazo = null;

        if (! empty($datos['documento_id'])) {
            $reemplazo = PostulacionDocumento::query()
                ->where('postulacion_id', $postulacion->id)
                ->whereNull('parametro_id')
                ->find($datos['documento_id']);

            if (! $reemplazo) {
                throw ValidationException::withMessages([
                    'documento_id' => 'Ese documento adicional no existe en esta postulación.',
                ]);
            }
        } else {
            $actuales = PostulacionDocumento::query()
                ->where('postulacion_id', $postulacion->id)
                ->whereNull('parametro_id')
                ->count();

            if ($actuales >= $maxAdicionales) {
                throw ValidationException::withMessages([
                    'titulo' => "No puedes agregar más de {$maxAdicionales} documentos adicionales.",
                ]);
            }
        }

        $nuevo = $this->guardarArchivo(
            $request->file('archivo'),
            RutaDocumento::carpeta($postulacion, 'adicionales'),
        );

        $campos = $nuevo + [
            'titulo' => $datos['titulo'],
            'nota' => $datos['nota'] ?? null,
        ];

        if ($reemplazo) {
            $anterior = [$reemplazo->disco, $reemplazo->ruta];
            $reemplazo->update($campos);
            $this->borrarArchivo(...$anterior);
            $documento = $reemplazo->fresh();
            $codigoHttp = 200;
        } else {
            // Recuento y alta bajo el bloqueo de la postulación: dos adicionales simultáneos no pueden superar el tope.
            $documento = DB::transaction(function () use ($postulacion, $campos, $maxAdicionales) {
                Postulacion::query()->whereKey($postulacion->id)->lockForUpdate()->first();

                $total = PostulacionDocumento::query()
                    ->where('postulacion_id', $postulacion->id)
                    ->whereNull('parametro_id')
                    ->count();

                if ($total >= $maxAdicionales) {
                    throw ValidationException::withMessages([
                        'titulo' => "No puedes agregar más de {$maxAdicionales} documentos adicionales.",
                    ]);
                }

                return PostulacionDocumento::create($campos + [
                    'postulacion_id' => $postulacion->id,
                    'parametro_id' => null,
                ]);
            });
            $codigoHttp = 201;
        }

        return response()->json(['data' => $this->adicionalResumen($documento)], $codigoHttp);
    }

    /** Borra la fila y su archivo físico. */
    private function eliminarDocumento(PostulacionDocumento $documento): void
    {
        $disco = $documento->disco;
        $ruta = $documento->ruta;
        $esRequerido = ! $documento->esAdicional();
        $postulacion = $esRequerido ? $documento->postulacion : null;

        $documento->delete();
        $this->borrarArchivo($disco, $ruta);

        if ($esRequerido && $postulacion) {
            $this->invalidarEvaluacionesPorCambioDocumental($postulacion);
        }
    }

    /**
     * Si se reemplaza o elimina un documento requerido después de una
     * evaluación, las evaluaciones finalizadas vuelven a 'en_proceso' para que
     * Selección no use un resultado calculado sobre archivos antiguos.
     */
    private function invalidarEvaluacionesPorCambioDocumental(Postulacion $postulacion, ?PostulacionDocumento $documento = null): void
    {
        // Cada evaluación que pierde su resultado suma una revisión (de forma atómica, con las filas bloqueadas): así una
        // referencia que la tenía elegida deja de ser vigente aunque la evaluación se rehaga en el mismo segundo.
        DB::transaction(function () use ($postulacion, $documento) {
            $evaluaciones = Evaluacion::query()
                ->where('postulacion_id', $postulacion->id)
                ->lockForUpdate()
                ->get();

            foreach ($evaluaciones as $evaluacion) {
                $evalUpdated = $evaluacion->updated_at ? \Illuminate\Support\Carbon::parse($evaluacion->updated_at) : null;
                $docUpdated = $documento?->updated_at ? \Illuminate\Support\Carbon::parse($documento->updated_at) : null;

                $evaluacion->timestamps = false;
                $invalidada = false;

                if ($evaluacion->estado === 'finalizada') {
                    $evaluacion->estado = 'en_proceso';
                    $evaluacion->pasa_seleccion = null;
                    // El evaluador ve qué documento cambió y cuándo (BUG-01C: el cambio puede venir de la ronda de otro evaluador).
                    $nombre = $documento?->parametro?->nombre ?? 'un documento requerido';
                    $evaluacion->motivo_reapertura = mb_substr(
                        "El documento «{$nombre}» cambió el ".now('America/Bogota')->format('d/m/Y H:i').'. Revisa la evaluación antes de finalizarla de nuevo.',
                        0,
                        255,
                    );
                    $evaluacion->reabierta_at = now();
                    $invalidada = true;
                }

                if ($evalUpdated && $docUpdated && $evalUpdated->gte($docUpdated)) {
                    $evaluacion->updated_at = $docUpdated->copy()->subSecond();
                }

                $evaluacion->save();

                if ($invalidada) {
                    DB::table('evaluaciones')->where('id', $evaluacion->id)->increment('revision');
                }
            }
        });
    }

    private function exigirDocumentoDeLaPostulacion(Postulacion $postulacion, PostulacionDocumento $documento): void
    {
        if ($documento->postulacion_id !== $postulacion->id) {
            abort(404, 'Documento no encontrado.');
        }
    }

    /**
     * Regla acordada el 4 de noviembre de 2026 (RF-DOC-006): un documento OBLIGATORIO de la convocatoria, una vez cargado,
     * no se elimina; solo se reemplaza por otro archivo válido (RF-DOC-005). Así una postulación enviada no puede perder
     * un obligatorio. Los adicionales y los documentos opcionales del catálogo sí se pueden eliminar.
     */
    private function exigirQueNoSeaObligatorio(Postulacion $postulacion, PostulacionDocumento $documento): void
    {
        if ($documento->esAdicional()) {
            return;
        }

        $postulacion->loadMissing('convocatoria.documentos');
        $requerido = $postulacion->convocatoria->documentos
            ->first(fn (Parametro $d) => $d->id === $documento->parametro_id);

        if ($requerido !== null && (bool) $requerido->pivot->obligatorio) {
            throw ValidationException::withMessages([
                'documento' => 'Este documento es obligatorio y no se puede eliminar: reemplázalo por otro archivo.',
            ]);
        }
    }
}
