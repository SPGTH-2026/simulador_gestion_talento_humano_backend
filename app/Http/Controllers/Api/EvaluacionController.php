<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Evaluacion;
use App\Models\Postulacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use App\Http\Controllers\Api\Concerns\ValidaEvaluacion;
use App\Http\Controllers\Api\Concerns\PresentaEvaluacion;

/**
 * Módulo de Evaluación (adaptado al módulo real de Convocatorias/Postulación).
 *
 * Evalúa postulaciones de convocatorias del alcance del usuario. El aspirante
 * consulta sus propias evaluaciones con propio:evaluaciones.
 */
class EvaluacionController extends Controller
{
    use PresentaEvaluacion;

    use ValidaEvaluacion;

    /**
     * Umbral único de habilitación documental. Vive en el servidor para que la
     * interfaz y Selección no calculen valores distintos. Cambiarlo aquí (o
     * volverlo configurable por convocatoria) es una decisión de negocio.
     */
    private const UMBRAL_SELECCION = 60;

    public function show(Request $request, Postulacion $postulacion): JsonResponse
    {
        $this->autorizar($request, $postulacion);

        $postulacion->load([
            'user:id,name,email,ficha_id',
            'user.perfilAspirante.sexo',
            'convocatoria:id,nombre,cargo,estado_id,ficha_id,fecha_evaluacion',
            'convocatoria.estado:id,codigo,nombre',
            'convocatoria.documentos',
            'convocatoria.skills',
            'documentos',
            'skills',
        ]);

        $actor = $request->user();
        $queryEval = Evaluacion::query()
            ->with('evaluador:id,name,email,subrole')
            ->where('postulacion_id', $postulacion->id);

        if (! in_array($actor->role, [Role::Instructor, Role::SuperAdmin], true)) {
            $queryEval->where('evaluador_id', $actor->id);
            $evaluacion = $queryEval->first();
        } else {
            $evaluacion = $this->seleccionarEvaluacionVisible($queryEval->get());
        }

        return response()->json(['data' => $this->presentar($postulacion, $evaluacion, $actor)]);
    }

    public function upsert(Request $request, Postulacion $postulacion): JsonResponse
    {
        $this->autorizar($request, $postulacion);
        $this->exigirPostulada($postulacion);

        $datos = $request->validate([
            'observaciones' => ['nullable', 'string', 'max:2000'],
            'estado' => ['nullable', Rule::in(['en_proceso', 'correccion_solicitada', 'finalizada'])],
            'criterios' => ['required', 'array', 'min:1'],
            'criterios.*.criterio' => ['required', 'string', 'max:120'],
            'criterios.*.puntaje' => ['required', 'numeric', 'min:0', 'max:100'],
            'criterios.*.ponderacion' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'perfil' => ['nullable', 'array'],
            'cumplimiento' => ['nullable', 'integer', 'min:0', 'max:100'],
        ]);

        // Si el evaluador manda a corregir, debe indicar qué documento(s) no
        // cumplen (marcados con No = false) y explicar el motivo en observaciones.
        if (($datos['estado'] ?? null) === 'correccion_solicitada') {
            if (trim((string) ($datos['observaciones'] ?? '')) === '') {
                abort(422, 'Debes escribir en las observaciones qué documentos o datos debe corregir el aspirante.');
            }

            $perfil = (array) ($datos['perfil'] ?? []);
            $hayRechazado = collect($perfil)->contains(fn ($valor) => $valor === false);

            if (! $hayRechazado) {
                abort(422, 'Marca con "No" al menos un documento que el aspirante deba corregir.');
            }
        }

        // El cumplimiento se calcula en el SERVIDOR con la ponderación que el
        // gestor configuró para ESTA convocatoria: el cliente solo manda qué
        // documentos cumplió (perfil). Si no hay documentos obligatorios
        // aplicables no existe base para decidir el 60 %: se rechaza en vez de
        // confiar en el porcentaje que envíe el navegador.
        $calculado = $this->calcularCumplimiento($postulacion, $datos['perfil'] ?? null);

        if ($calculado === null) {
            abort(422, 'No se puede evaluar: la convocatoria no tiene documentos obligatorios aplicables con ponderación válida.');
        }

        // Un obligatorio aplicable sin archivo no puede marcarse «Cumple» ni permite finalizar (RF-DOC-006 + RF-EVA-003).
        $this->exigirDocumentosCargados($postulacion, $datos['perfil'] ?? null, $datos['estado'] ?? 'en_proceso');

        $evaluacion = DB::transaction(function () use ($request, $postulacion, $datos, $calculado) {
            $actor = $request->user();

            // Serializa todos los guardados de ESTA postulación: dos peticiones simultáneas (doble clic, dos pestañas) no
            // se cruzan ni crean dos evaluaciones, y la comprobación de la corrección pendiente se hace sobre el estado
            // real, no sobre el que se leyó antes.
            Postulacion::query()->whereKey($postulacion->id)->lockForUpdate()->first();

            // El Instructor/super_admin administra el proceso: si ya existe una
            // evaluación de un aprendiz evaluador, la edita; si no existe, crea
            // la suya. El evaluador siempre guarda la suya.
            if (in_array($actor->role, [Role::Instructor, Role::SuperAdmin], true)) {
                $existente = $this->seleccionarEvaluacionVisible(
                    Evaluacion::where('postulacion_id', $postulacion->id)->get()
                );
                $evaluacion = $existente ?? new Evaluacion([
                    'postulacion_id' => $postulacion->id,
                    'evaluador_id' => $actor->id,
                ]);
            } else {
                $evaluacion = Evaluacion::firstOrNew([
                    'postulacion_id' => $postulacion->id,
                    'evaluador_id' => $actor->id,
                ]);
            }

            // Con la fila bloqueada: dos guardados simultáneos de la misma evaluación se serializan y cada cambio real
            // suma su propia revisión.
            if ($evaluacion->exists) {
                $evaluacion = Evaluacion::query()->lockForUpdate()->findOrFail($evaluacion->id);
            }

            $estadoNuevo = $datos['estado'] ?? 'en_proceso';
            $perfilNuevo = $this->canonicoPerfil($datos['perfil'] ?? null);
            // Solo una evaluación finalizada es resultado: un borrador no define si
            // la postulación pasa a selección.
            $pasaNuevo = ($estadoNuevo === 'finalizada' && $calculado !== null)
                ? $calculado >= self::UMBRAL_SELECCION
                : null;

            // Reenvío idéntico: no se escribe nada y la revisión se conserva (la referencia sigue vigente). Se compara el
            // contenido real (el orden de las claves del perfil y de los criterios no cuenta), no lo que Eloquent
            // considere «sucio».
            // Si el aspirante ya envió la corrección, un «reenvío idéntico» del evaluador sí abre una ronda nueva: no se
            // puede cortar aquí, o correccion_enviada_at se quedaría puesta y el aspirante no podría volver a corregir.
            $pideRondaNueva = $estadoNuevo === 'correccion_solicitada'
                && $evaluacion->exists
                && $evaluacion->correccion_enviada_at !== null;

            if (! $pideRondaNueva
                && $evaluacion->exists
                && ($evaluacion->observaciones ?? null) === ($datos['observaciones'] ?? null)
                && $evaluacion->estado === $estadoNuevo
                && $this->canonicoPerfil($evaluacion->perfil) === $perfilNuevo
                && $evaluacion->cumplimiento === $calculado
                && $evaluacion->pasa_seleccion === $pasaNuevo
                && $this->canonicoCriterios($evaluacion->detalles()->get(['criterio', 'puntaje', 'ponderacion'])->all()) === $this->canonicoCriterios($datos['criterios'])) {
                return $evaluacion;
            }

            // Ya hay una corrección pendiente (pedida, sin enviar y con plazo vigente): no se pide otra encima. El reenvío
            // idéntico se resolvió arriba y sigue devolviendo la evaluación sin escribir. Abrir una ronda nueva cuando
            // la anterior venció o fue respondida NO se decide aquí.
            if ($estadoNuevo === 'correccion_solicitada'
                && $evaluacion->exists
                && $evaluacion->estadoCorreccion($postulacion) === 'vigente') {
                abort(422, 'Ya hay una corrección pendiente: espera a que el aspirante responda antes de pedir otra.');
            }

            $existia = $evaluacion->exists;

            // Plazo de corrección: se fija cuando el evaluador pide la corrección. Si vuelve a guardar una solicitud que
            // sigue pendiente, el plazo original se conserva. Si el aspirante ya la envió, una nueva solicitud abre otra
            // ronda con su propio plazo (aunque el anterior haya vencido). Un envío del aspirante se limpia con cada cambio.
            $eraCorreccion = $existia
                && $evaluacion->estado === 'correccion_solicitada'
                && $evaluacion->fecha_limite_correccion !== null
                && $evaluacion->correccion_enviada_at === null;
            $evaluacion->correccion_enviada_at = null;
            $evaluacion->fecha_limite_correccion = $estadoNuevo === 'correccion_solicitada'
                ? ($eraCorreccion ? $evaluacion->fecha_limite_correccion : now()->addDays(Evaluacion::DIAS_LIMITE_CORRECCION))
                : null;

            $evaluacion->observaciones = $datos['observaciones'] ?? null;
            $evaluacion->estado = $estadoNuevo;
            $evaluacion->perfil = $perfilNuevo;
            $evaluacion->cumplimiento = $calculado;
            $evaluacion->pasa_seleccion = $pasaNuevo;

            $evaluacion->save();

            $evaluacion->detalles()->delete();

            $sumaPonderada = 0.0;
            $sumaPesos = 0.0;

            foreach ($datos['criterios'] as $criterio) {
                $peso = (float) ($criterio['ponderacion'] ?? 1);
                $evaluacion->detalles()->create([
                    'criterio' => $criterio['criterio'],
                    'puntaje' => $criterio['puntaje'],
                    'ponderacion' => $peso,
                ]);
                $sumaPonderada += (float) $criterio['puntaje'] * $peso;
                $sumaPesos += $peso;
            }

            $evaluacion->puntaje_total = $sumaPesos > 0 ? round($sumaPonderada / $sumaPesos, 2) : null;
            $evaluacion->save();

            // Cambio real de una evaluación que ya existía: la revisión sube en 1, de forma atómica (revision = revision + 1
            // en la base) y con la fila bloqueada. De ella depende la vigencia de la referencia, no del reloj. Una
            // evaluación nueva nace en la revisión 1.
            if ($existia) {
                DB::table('evaluaciones')->where('id', $evaluacion->id)->increment('revision');
                $evaluacion->refresh();
            }

            return $evaluacion;
        });

        $postulacion->load([
            'user:id,name,email,ficha_id',
            'user.perfilAspirante.sexo',
            'convocatoria:id,nombre,cargo,estado_id,ficha_id,fecha_evaluacion',
            'convocatoria.estado:id,codigo,nombre',
            'convocatoria.documentos',
            'convocatoria.skills',
            'documentos',
            'skills',
        ])->loadCount([
            'documentos',
        ]);

        return response()->json([
            'data' => $this->presentar($postulacion, $evaluacion->load('detalles'), $request->user()),
        ]);
    }

}
