<?php

namespace App\Services\Documentos;

use App\Models\CorreccionPostulacion;
use App\Models\Evaluacion;
use App\Models\PerfilAspirante;
use App\Models\Parametro;
use App\Models\Postulacion;
use App\Models\PostulacionDocumento;
use App\Support\RutaDocumento;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Escritura de los documentos de una postulación: subir o reemplazar un requerido, agregar o reemplazar un adicional, y
 * eliminar. Aquí están las reglas de negocio de esa escritura; el controlador solo valida la entrada, comprueba el acceso
 * y responde.
 *
 * Las comprobaciones de acceso (dueño, ventana de edición, corrección vigente) las hace el controlador antes de llamar
 * aquí: este servicio recibe la corrección vigente ya resuelta para no depender de ningún trait.
 */
final class EscribeDocumentoPostulacion
{
    public function __construct(private AlmacenArchivos $almacen) {}

    /**
     * Sube o reemplaza el documento requerido. Devuelve el documento y el código HTTP: 201 si es nuevo, 200 si reemplaza.
     *
     * @return array{0: PostulacionDocumento, 1: int}
     */
    public function guardarRequerido(
        Postulacion $postulacion,
        ?Parametro $parametro,
        string $campo,
        UploadedFile $archivo,
        ?CorreccionPostulacion $correccion,
    ): array {
        if (! $parametro || ! $this->documentoAplica($postulacion, $parametro)) {
            throw ValidationException::withMessages([
                $campo => 'Ese documento no hace parte de esta convocatoria.',
            ]);
        }

        if ($parametro->codigo === Parametro::DOC_LIBRETA_MILITAR) {
            $this->exigirLibretaAplicable($postulacion);
        }

        // Durante una corrección solo se reemplaza un documento marcado con «Corregir».
        if ($correccion !== null && ! in_array((int) $parametro->id, array_map('intval', $correccion->documentos_marcados ?? []), true)) {
            throw ValidationException::withMessages([
                $campo => 'Durante la corrección solo puedes reemplazar los documentos que el evaluador marcó.',
            ]);
        }

        $nuevo = $this->almacen->guardar($archivo, RutaDocumento::carpeta($postulacion, 'documentos'));

        // Búsqueda y escritura bajo el bloqueo de la postulación: dos subidas simultáneas del mismo documento no pueden
        // crear dos filas. El archivo anterior se borra solo después de confirmar el cambio.
        [$documento, $codigoHttp, $anterior] = $this->conArchivoNuevo($nuevo, fn () => DB::transaction(function () use ($postulacion, $parametro, $nuevo) {
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
        }));

        if ($anterior !== null) {
            $this->almacen->borrar(...$anterior);
        }

        $this->invalidarEvaluacionesPorCambioDocumental($postulacion, $documento);

        return [$documento, $codigoHttp];
    }

    /**
     * Agrega un adicional, o reemplaza el archivo (y el título y la nota) de uno existente si viene `documento_id`. El
     * reemplazo no gasta cupo.
     *
     * @param  array{documento_id: ?int, titulo: string, nota: ?string}  $datos
     * @return array{0: PostulacionDocumento, 1: int}
     */
    public function guardarAdicional(Postulacion $postulacion, array $datos, UploadedFile $archivo): array
    {
        $maxAdicionales = (int) config('convocatorias.documentos_adicionales_max');

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

        $nuevo = $this->almacen->guardar($archivo, RutaDocumento::carpeta($postulacion, 'adicionales'));

        $campos = $nuevo + [
            'titulo' => $datos['titulo'],
            'nota' => $datos['nota'] ?? null,
        ];

        if ($reemplazo) {
            $anterior = [$reemplazo->disco, $reemplazo->ruta];
            $this->conArchivoNuevo($nuevo, fn () => $reemplazo->update($campos));
            $this->almacen->borrar(...$anterior);

            return [$reemplazo->fresh(), 200];
        }

        // Recuento y alta bajo el bloqueo de la postulación: dos adicionales simultáneos no pueden superar el tope.
        $documento = $this->conArchivoNuevo($nuevo, fn () => DB::transaction(function () use ($postulacion, $campos, $maxAdicionales) {
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
        }));

        return [$documento, 201];
    }

    /**
     * Ejecuta la escritura en base de datos cuando el archivo nuevo ya está en el disco. Si la escritura falla (el tope
     * de adicionales lo superó otra subida, error de base de datos), borra ese archivo para que no quede huérfano.
     */
    private function conArchivoNuevo(array $nuevo, callable $escribir): mixed
    {
        try {
            return $escribir();
        } catch (\Throwable $e) {
            $this->almacen->borrar($nuevo['disco'], $nuevo['ruta']);

            throw $e;
        }
    }

    /**
     * Borra la fila y su archivo. Un documento OBLIGATORIO no se elimina (RF-DOC-006): solo se reemplaza. Los adicionales
     * y los opcionales sí se pueden eliminar.
     */
    public function eliminar(PostulacionDocumento $documento): void
    {
        $this->exigirQueNoSeaObligatorio($documento);

        $disco = $documento->disco;
        $ruta = $documento->ruta;
        $esRequerido = ! $documento->esAdicional();
        $postulacion = $esRequerido ? $documento->postulacion : null;

        $documento->delete();
        $this->almacen->borrar($disco, $ruta);

        if ($esRequerido && $postulacion) {
            $this->invalidarEvaluacionesPorCambioDocumental($postulacion);
        }
    }

    /**
     * ¿Se puede subir este documento a la postulación? Los de la convocatoria, sí; la autorización del representante
     * solo si la persona es menor de edad (no pertenece a la convocatoria: la exige el sistema por la edad).
     */
    private function documentoAplica(Postulacion $postulacion, Parametro $parametro): bool
    {
        if ($parametro->codigo === Parametro::DOC_AUTORIZACION_REPRESENTANTE) {
            $postulacion->loadMissing('datosPersonales');

            return $postulacion->datosPersonales?->esMenor() ?? false;
        }

        return $this->convocatoriaPide($postulacion, $parametro->id);
    }

    /** ¿La convocatoria de la postulación pide este tipo de documento? */
    private function convocatoriaPide(Postulacion $postulacion, int $parametroId): bool
    {
        $postulacion->loadMissing('convocatoria.documentos');

        return $postulacion->convocatoria->documentos->contains('id', $parametroId);
    }

    /** La libreta militar solo aplica a hombres, y exige tener el sexo registrado. */
    private function exigirLibretaAplicable(Postulacion $postulacion): void
    {
        $perfil = PerfilAspirante::query()
            ->where('user_id', $postulacion->user_id)
            ->with('sexo')
            ->first();

        if (! $perfil) {
            throw ValidationException::withMessages([
                'codigo' => 'Antes de subir la libreta militar debes llenar tus datos personales.',
            ]);
        }

        if ($perfil->sexo?->codigo === 'mujer') {
            throw ValidationException::withMessages([
                'codigo' => 'La libreta militar no aplica: tu perfil está registrado como femenino.',
            ]);
        }

        if (ReglaLibreta::noAplica($perfil, $postulacion->datosPersonales)) {
            throw ValidationException::withMessages([
                'codigo' => 'La libreta militar no aplica: eres menor de edad.',
            ]);
        }
    }

    private function exigirQueNoSeaObligatorio(PostulacionDocumento $documento): void
    {
        if ($documento->esAdicional()) {
            return;
        }

        $postulacion = $documento->postulacion;
        $postulacion->loadMissing('convocatoria.documentos');

        $requerido = $postulacion->convocatoria->documentos
            ->first(fn (Parametro $d) => $d->id === $documento->parametro_id);

        if ($requerido !== null && (bool) $requerido->pivot->obligatorio) {
            throw ValidationException::withMessages([
                'documento' => 'Este documento es obligatorio y no se puede eliminar: reemplázalo por otro archivo.',
            ]);
        }
    }

    /**
     * Si se reemplaza o elimina un documento requerido después de una evaluación, las evaluaciones finalizadas vuelven a
     * 'en_proceso' para que Selección no use un resultado calculado sobre archivos antiguos.
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
                $evalUpdated = $evaluacion->updated_at ? Carbon::parse($evaluacion->updated_at) : null;
                $docUpdated = $documento?->updated_at ? Carbon::parse($documento->updated_at) : null;

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
}
