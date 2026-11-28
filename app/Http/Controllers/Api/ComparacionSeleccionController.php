<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Api\Concerns\RankingSeleccion;
use App\Http\Controllers\Api\Concerns\ResultadoAdicional;
use App\Http\Controllers\Api\Concerns\ResultadosDocumentales;
use App\Http\Controllers\Controller;
use App\Models\ConvocatoriaSkill;
use App\Models\Evaluacion;
use App\Models\Postulacion;
use App\Models\PostulacionSkill;
use App\Models\SeleccionCalificacion;
use App\Models\SeleccionFinalistaLista;
use App\Models\SeleccionFinalistaMiembro;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Selección · CU-SEL-017: comparar de dos a cinco candidatos de UNA convocatoria. Es solo lectura.
 *
 * Una sola petición devuelve, para todos, los mismos criterios: lo que conservó la lista confirmada y lo de hoy según el
 * Ranking (siempre las dos cosas, para no mezclarlas), notas y observaciones por actividad con su peso, estado y
 * cumplimiento documental, skills y fecha de postulación. Todo se resuelve en bloque (un número fijo de consultas, sin
 * importar cuántos candidatos sean) y con las reglas ya existentes: el ranking, el estado documental y la revisión de la
 * lista salen de los mismos cálculos que usan Ranking, Finalistas y Decidir.
 *
 * Visibilidad:
 *  - Permiso `seleccion:ver` (por ruta) y alcance por ficha: una convocatoria ajena responde 404.
 *  - Evaluador y referencia documental: solo Instructor y Superadministrador, como en la referencia documental. Para los
 *    demás roles `meta.evaluador_visible` es false y no se envía ningún nombre de evaluador.
 *  - Skills: las de la convocatoria con su estado («declarada» y «certificado cargado», que es solo la existencia de un
 *    archivo, no una validación). Nunca se envía el archivo, su ruta ni su nombre.
 */
class ComparacionSeleccionController extends Controller
{
    use RankingSeleccion;
    use ResultadoAdicional;
    use ResultadosDocumentales;

    public const MINIMO = 2;

    public const MAXIMO = 5;

    public function show(Request $request, int $convocatoria): JsonResponse
    {
        $actor = $request->user();
        $convocatoria = $this->convocatoriaEnAlcance($actor, $convocatoria);

        $datos = $request->validate([
            'postulaciones' => ['required', 'array', 'min:'.self::MINIMO, 'max:'.self::MAXIMO],
            'postulaciones.*' => ['required', 'integer', 'min:1', 'distinct'],
        ], [
            'postulaciones.required' => 'Elige de '.self::MINIMO.' a '.self::MAXIMO.' candidatos para comparar.',
            'postulaciones.array' => 'Elige de '.self::MINIMO.' a '.self::MAXIMO.' candidatos para comparar.',
            'postulaciones.min' => 'Elige al menos '.self::MINIMO.' candidatos para comparar.',
            'postulaciones.max' => 'Se pueden comparar hasta '.self::MAXIMO.' candidatos.',
            'postulaciones.*.distinct' => 'No repitas al mismo candidato.',
            'postulaciones.*.integer' => 'Candidato no válido.',
        ]);

        $ids = collect($datos['postulaciones'])->map(fn ($id) => (int) $id)->values();

        // Todas deben ser de ESTA convocatoria (aunque sean de la misma ficha): si una no lo es, 404 sin revelar más.
        $postulaciones = Postulacion::query()
            ->with('user:id,name')
            ->where('convocatoria_id', $convocatoria->id)
            ->whereHas('user', fn ($u) => $u->where('active', true))
            ->whereIn('id', $ids)
            ->get(['id', 'user_id', 'fecha_postulacion'])
            ->keyBy('id');
        abort_if($postulaciones->count() !== $ids->count(), 404, 'Candidato no encontrado.');

        $ranking = $this->construirRanking($convocatoria);
        $indice = [
            'candidatos' => $ranking['candidatos']->keyBy('id'),
            'listos' => $ranking['listos']->keyBy('id'),
        ];

        // Lista vigente: lo confirmado y qué candidatos de esta comparación requieren revisión.
        $lista = SeleccionFinalistaLista::query()
            ->with('miembros')
            ->where('convocatoria_id', $convocatoria->id)
            ->orderByDesc('version')
            ->first();
        $revision = $lista === null ? collect() : collect($this->revisionDeLista($lista, $ranking))->keyBy('postulacion_id');
        $miembros = $lista === null
            ? collect()
            : $lista->miembros->whereIn('postulacion_id', $ids->all())->keyBy('postulacion_id');

        $documentales = $this->estadosDocumentales($ids);
        $notas = SeleccionCalificacion::query()
            ->with('registrador:id,name')
            ->where('convocatoria_id', $convocatoria->id)
            ->whereIn('postulacion_id', $ids)
            ->get()
            ->groupBy('postulacion_id')
            ->map(fn ($filas) => $filas->keyBy('actividad'));
        $configuracion = $ranking['configuracion'];

        $evaluadorVisible = in_array($actor->role, [Role::Instructor, Role::SuperAdmin], true);
        $evaluadores = $evaluadorVisible ? $this->evaluadoresDe($ids) : [];

        [$requeridas, $declaradas] = $this->skillsDe($convocatoria->id, $ids);

        $candidatos = $ids->map(function (int $id) use (
            $postulaciones, $indice, $miembros, $lista, $revision, $documentales, $notas, $configuracion, $evaluadorVisible,
            $evaluadores, $requeridas, $declaradas,
        ) {
            $p = $postulaciones->get($id);
            $miembro = $miembros->get($id);
            $propias = $notas->get($id, collect());
            $doc = $documentales->get($id);
            $propiasSkills = $declaradas->get($id, collect());

            $actividades = [];
            foreach (SeleccionCalificacion::ACTIVIDADES as $actividad) {
                $activa = $configuracion?->{$actividad.'_activa'} ?? false;
                $nota = $propias->get($actividad);
                $actividades[$actividad] = [
                    'estado' => $nota !== null ? 'registrada' : ($activa ? 'pendiente' : 'desactivada'),
                    'nota' => $nota === null ? null : (string) $nota->nota,
                    'fecha_realizacion' => $nota?->fecha_realizacion?->toDateString(),
                    'observacion' => $nota?->observacion,
                    'registrada_por' => $nota?->registrador?->name,
                ];
            }

            return [
                'postulacion_id' => $id,
                'aspirante' => $p->user?->name,
                'fecha_postulacion' => $p->fecha_postulacion?->toIso8601String(),
                // Lo que CONSERVÓ la lista vigente al confirmarse (null si no está en ella).
                'confirmada' => $miembro === null ? null : [
                    'lista_version' => $lista->version,
                    'posicion' => $miembro->posicion,
                    'resultado' => $miembro->resultado === null ? null : (string) $miembro->resultado,
                ],
                'revision' => $revision->get($id)['motivo'] ?? null,
                // Cómo está HOY según el Ranking vivo (null si su postulación ya no está postulada).
                'actual' => $this->situacionActual($indice, $id),
                'documental' => [
                    'estado' => $doc['estado'],
                    'cumplimiento' => $doc['cumplimiento'],
                ],
                'evaluacion' => $evaluadorVisible ? ($evaluadores[$id] ?? ['referencia' => null, 'evaluaciones' => []]) : null,
                'actividades' => $actividades,
                'skills' => [
                    'requeridas' => $requeridas->map(function (ConvocatoriaSkill $s) use ($propiasSkills) {
                        $declarada = $propiasSkills->firstWhere('convocatoria_skill_id', $s->id);

                        return [
                            'nombre' => $s->nombre,
                            'declarada' => $declarada !== null,
                            // Solo consta que hay un archivo cargado; no es una validación del certificado.
                            'certificado_cargado' => $declarada !== null && $declarada->certificado_ruta !== null,
                        ];
                    })->values(),
                ],
            ];
        })->values();

        return response()->json([
            'data' => $candidatos,
            'meta' => [
                'modo' => $ranking['modo'],
                'actividades' => [
                    'entrevista' => ['activa' => (bool) ($configuracion?->entrevista_activa ?? false), 'peso' => $configuracion?->peso_entrevista],
                    'prueba_tecnica' => ['activa' => (bool) ($configuracion?->prueba_tecnica_activa ?? false), 'peso' => $configuracion?->peso_prueba_tecnica],
                ],
                'lista' => $lista === null ? null : [
                    'version' => $lista->version,
                    'confirmada_en' => $lista->created_at?->toIso8601String(),
                    'requiere_revision' => $revision->isNotEmpty(),
                ],
                'evaluador_visible' => $evaluadorVisible,
                'skills_requeridas' => $requeridas->count(),
            ],
        ]);
    }

    /**
     * Situación de un candidato según el Ranking vivo, o null si ya no es candidato (su postulación dejó de estar
     * postulada). 'posicion' y 'resultado' solo existen si está listo y hay actividades configuradas.
     */
    private function situacionActual(array $indice, int $postulacionId): ?array
    {
        $candidato = $indice['candidatos'][$postulacionId] ?? null;
        if ($candidato === null) {
            return null;
        }
        $listo = $indice['listos'][$postulacionId] ?? null;

        return [
            'listo' => $candidato['listo'],
            'posicion' => $listo['posicion'] ?? null,
            'resultado' => $candidato['resultado'] === null ? null : $this->formatear($candidato['resultado']),
        ];
    }

    /**
     * Evaluaciones elegibles y referencia documental de cada postulación (solo para Instructor y Superadministrador).
     * Dos consultas para todas.
     *
     * @return array<int, array{referencia: ?array, evaluaciones: array}>
     */
    private function evaluadoresDe($ids): array
    {
        $elegibles = $this->soloElegibles(Evaluacion::query()->whereIn('postulacion_id', $ids))
            ->with('evaluador:id,name')
            ->orderBy('id')
            ->get(['id', 'postulacion_id', 'evaluador_id', 'cumplimiento', 'pasa_seleccion'])
            ->groupBy('postulacion_id');
        $referencias = $this->ultimasReferencias($ids);
        // Evaluador y designador de todas las referencias en bloque (una consulta por relación, no una por referencia).
        (new \Illuminate\Database\Eloquent\Collection($referencias->pluck('ref')->values()->all()))
            ->load('evaluador:id,name', 'designador:id,name');

        return $ids->mapWithKeys(function (int $id) use ($elegibles, $referencias) {
            $ref = $referencias->get($id);

            return [$id => [
                'referencia' => $ref === null ? null : [
                    'evaluador' => $ref['ref']->evaluador?->name,
                    'designada_por' => $ref['ref']->designador?->name,
                    'vigente' => $ref['vigente'],
                ],
                'evaluaciones' => $elegibles->get($id, collect())->map(fn (Evaluacion $e) => [
                    'evaluador' => $e->evaluador?->name,
                    'cumplimiento' => $e->cumplimiento,
                    'resultado' => $e->pasa_seleccion ? 'habilitado' : 'no_habilitado',
                ])->values()->all(),
            ]];
        })->all();
    }

    /**
     * Skills pedidas por la convocatoria y skills declaradas por cada postulación. No se leen la ruta, el nombre ni el tipo
     * del archivo del certificado: solo si existe (certificado_ruta no nulo).
     *
     * @return array{0: \Illuminate\Support\Collection<int, ConvocatoriaSkill>, 1: \Illuminate\Support\Collection}
     */
    private function skillsDe(int $convocatoriaId, $ids): array
    {
        $requeridas = ConvocatoriaSkill::query()->where('convocatoria_id', $convocatoriaId)->orderBy('id')->get(['id', 'nombre']);
        $declaradas = PostulacionSkill::query()
            ->whereIn('postulacion_id', $ids)
            ->get(['id', 'postulacion_id', 'convocatoria_skill_id', 'certificado_ruta'])
            ->groupBy('postulacion_id');

        return [$requeridas, $declaradas];
    }
}
