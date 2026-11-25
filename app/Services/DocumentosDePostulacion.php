<?php

namespace App\Services;

use App\Models\Convocatoria;
use App\Models\Parametro;
use App\Models\PerfilAspirante;
use App\Models\Postulacion;
use App\Services\Documentos\ReglaLibreta;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Regla única de "documentos obligatorios de una postulación". La comparten la
 * presentación del estado (GET, campo `completo`) y la validación del envío,
 * para que no diverjan.
 *
 * Un documento obligatorio está satisfecho si tiene archivo subido o si está en
 * `no_aplica` (la libreta militar de una mujer no se exige).
 */
class DocumentosDePostulacion
{
    private ?Parametro $autorizacion = null;

    /** Motivo que acompaña a la libreta cuando el aspirante no ha registrado sexo. */
    public const MOTIVO_FALTA_SEXO =
        'Llena tus datos personales para saber si aplica la libreta militar.';

    /**
     * Faltantes que impiden enviar: documentos OBLIGATORIOS de la convocatoria
     * sin archivo y que no están en 'no_aplica'. La libreta militar solo aplica
     * a hombres. Cuando no hay sexo registrado y la convocatoria pide la libreta,
     * ese faltante lleva un `motivo` propio.
     *
     * @return array<int, array{codigo: string, nombre: string, motivo?: string}>
     */
    public function faltantes(Postulacion $postulacion): array
    {
        $postulacion->loadMissing(['convocatoria.documentos', 'documentos', 'datosPersonales']);

        $perfil = PerfilAspirante::query()
            ->where('user_id', $postulacion->user_id)
            ->with('sexo')
            ->first();

        return $this->faltantesCon($postulacion, $perfil);
    }

    /**
     * Calcula `completo` para VARIAS postulaciones con dos consultas en total
     * (relaciones y perfiles), en vez de dos consultas por postulación.
     *
     * @return array<int, bool> mapa id de postulación => completo
     */
    public function completosPara(Collection $postulaciones): array
    {
        if ($postulaciones->isEmpty()) {
            return [];
        }

        $postulaciones->loadMissing(['convocatoria.documentos', 'documentos', 'datosPersonales']);

        $perfiles = PerfilAspirante::query()
            ->whereIn('user_id', $postulaciones->pluck('user_id')->unique()->all())
            ->with('sexo')
            ->get()
            ->keyBy('user_id');

        $resultado = [];
        foreach ($postulaciones as $postulacion) {
            $resultado[$postulacion->id] = $this->faltantesCon($postulacion, $perfiles->get($postulacion->user_id)) === [];
        }

        return $resultado;
    }

    /**
     * Mismo cálculo y MISMA regla que completosPara (faltantesCon), para las postulaciones de UNA convocatoria ya
     * cargada: es lo que usa el Ranking para exigir los obligatorios aplicables. Siempre las mismas consultas, sin
     * importar cuántas postulaciones haya: los archivos subidos de todas, los documentos de la convocatoria (una vez) y,
     * solo si la convocatoria exige la libreta militar, los perfiles para saber a quién no le aplica.
     *
     * @param  EloquentCollection<int, Postulacion>  $postulaciones  postulaciones de $convocatoria
     * @return array<int, bool> mapa id de postulación => completo
     */
    public function completosDeConvocatoria(Convocatoria $convocatoria, EloquentCollection $postulaciones): array
    {
        if ($postulaciones->isEmpty()) {
            return [];
        }

        $convocatoria->loadMissing('documentos');
        $postulaciones->load(['documentos' => fn ($q) => $q->select('id', 'postulacion_id', 'parametro_id'), 'datosPersonales']);

        $exigeLibreta = $convocatoria->documentos->contains(
            fn (Parametro $d) => $d->codigo === Parametro::DOC_LIBRETA_MILITAR && (bool) $d->pivot->obligatorio,
        );
        $perfiles = $exigeLibreta
            ? PerfilAspirante::query()
                ->whereIn('user_id', $postulaciones->pluck('user_id')->unique()->all())
                ->with('sexo')
                ->get()
                ->keyBy('user_id')
            : collect();

        $resultado = [];
        foreach ($postulaciones as $postulacion) {
            $postulacion->setRelation('convocatoria', $convocatoria);
            $resultado[$postulacion->id] = $this->faltantesCon($postulacion, $perfiles->get($postulacion->user_id)) === [];
        }

        return $resultado;
    }

    /**
     * Faltantes con el perfil ya resuelto (null = el aspirante no registró sexo).
     *
     * @return array<int, array{codigo: string, nombre: string, motivo?: string}>
     */
    private function faltantesCon(Postulacion $postulacion, ?PerfilAspirante $perfil): array
    {
        $libretaNoAplica = ReglaLibreta::noAplica($perfil, $postulacion->datosPersonales);

        $subidos = $postulacion->documentos
            ->whereNotNull('parametro_id')
            ->keyBy('parametro_id');

        $faltantes = [];

        foreach ($postulacion->convocatoria->documentos as $parametro) {
            if (! (bool) $parametro->pivot->obligatorio) {
                continue;
            }

            // La libreta no se exige a mujeres ni a menores de 18: no es un faltante.
            if ($parametro->codigo === Parametro::DOC_LIBRETA_MILITAR && $libretaNoAplica) {
                continue;
            }

            if ($subidos->has($parametro->id)) {
                continue;
            }

            $faltante = [
                'codigo' => $parametro->codigo,
                'nombre' => $parametro->nombre,
            ];

            // Sin sexo no se sabe si la libreta aplica: se pide indicarlo.
            if ($parametro->codigo === Parametro::DOC_LIBRETA_MILITAR && $perfil === null) {
                $faltante['motivo'] = self::MOTIVO_FALTA_SEXO;
            }

            $faltantes[] = $faltante;
        }

        // Un menor de edad también debe subir la autorización de su representante (requisito por edad, sin porcentaje).
        $autorizacion = $postulacion->datosPersonales?->esMenor() ? $this->autorizacionRepresentante() : null;
        if ($autorizacion && ! $subidos->has($autorizacion->id)) {
            $faltantes[] = ['codigo' => $autorizacion->codigo, 'nombre' => $autorizacion->nombre];
        }

        return $faltantes;
    }

    /** La autorización que EXIGE esta postulación: solo si la persona es menor de edad; null en los demás casos. */
    public function autorizacionRequerida(Postulacion $postulacion): ?Parametro
    {
        $postulacion->loadMissing('datosPersonales');

        return $postulacion->datosPersonales?->esMenor() ? $this->autorizacionRepresentante() : null;
    }

    /** Parámetro de la autorización del representante (se resuelve una vez por instancia del servicio). */
    public function autorizacionRepresentante(): ?Parametro
    {
        return $this->autorizacion ??= Parametro::porTemaYCodigo('tipo_documento', Parametro::DOC_AUTORIZACION_REPRESENTANTE);
    }

    /** ¿Están todos los obligatorios subidos o en 'no_aplica'? */
    public function completo(Postulacion $postulacion): bool
    {
        return $this->faltantes($postulacion) === [];
    }

    /**
     * Mensaje humano para el 422 del envío.
     *
     * @param  array<int, array{codigo: string, nombre: string, motivo?: string}>  $faltantes
     */
    public function mensaje(array $faltantes): string
    {
        $nombres = array_map(
            fn (array $f) => isset($f['motivo'])
                ? "{$f['nombre']} ({$f['motivo']})"
                : $f['nombre'],
            $faltantes,
        );

        return 'Debes completar los documentos obligatorios: '.implode(', ', $nombres).'.';
    }
}
