<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Evaluacion;
use App\Models\Postulacion;
use App\Services\Notificaciones\Avisos;
use App\Services\RondasCorreccion;
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

        return response()->json(['data' => $this->presentar($postulacion, $evaluacion, $actor, true)]);
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

            // Las solicitudes antiguas de esta postulación se guardan como rondas antes de tocar nada, para que un
            // cambio de estado de la evaluación no las saque del conteo ni del historial. Idempotente; los solapes de
            // varios evaluadores no se agrupan (ver RondasCorreccion::convertirLegadas).
            $rondas = app(RondasCorreccion::class);
            $rondas->convertirLegadas($postulacion);

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
            // Si el aspirante ya respondió la última ronda, un «reenvío idéntico» del evaluador sí pide la siguiente: no se
            // puede cortar aquí, o el aspirante no podría volver a corregir.
            $pideRondaNueva = $estadoNuevo === 'correccion_solicitada'
                && $evaluacion->exists
                && $rondas->rondas($postulacion)->last()?->estado() === 'respondida';

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

            // Una solicitud antigua que forma parte de un solape de varios evaluadores no se toca hasta que el administrador
            // la revise: cambiar el estado de esta evaluación la sacaría del conteo y del historial.
            if ($evaluacion->exists
                && $evaluacion->estado === 'correccion_solicitada'
                && $estadoNuevo !== 'correccion_solicitada'
                && $rondas->tieneSolapes($postulacion)) {
                abort(422, 'Esta evaluación forma parte de solicitudes de corrección antiguas simultáneas de varios evaluadores que el administrador debe revisar antes de convertirlas. Por ahora no se puede cambiar su estado.');
            }

            $existia = $evaluacion->exists;

            // El plazo y la respuesta viven en la ronda de corrección (por postulación). Estas columnas quedan como copia
            // de la última solicitud de esta evaluación, solo por compatibilidad.
            $evaluacion->correccion_enviada_at = null;
            $evaluacion->fecha_limite_correccion = null;

            $evaluacion->observaciones = $datos['observaciones'] ?? null;
            // Al pedir corrección la evaluación se guarda primero «en proceso»: si no, el servicio de rondas la leería como una
            // solicitud antigua sin plazo. Pasa a «corrección solicitada» cuando la ronda ya está creada.
            // Solo un paso a «finalizada» avisa al aspirante: guardar de nuevo una evaluación ya finalizada no repite el aviso.
            $finalizaAhora = $estadoNuevo === 'finalizada' && $evaluacion->estado !== 'finalizada';

            $evaluacion->estado = $estadoNuevo === 'correccion_solicitada' && $evaluacion->estado !== 'correccion_solicitada'
                ? 'en_proceso'
                : $estadoNuevo;
            $evaluacion->perfil = $perfilNuevo;
            $evaluacion->cumplimiento = $calculado;
            $evaluacion->pasa_seleccion = $pasaNuevo;

            $evaluacion->save();

            // Pedir corrección = abrir una ronda. Se comprueba bajo el bloqueo de la postulación (ronda vigente, límite de dos
            // ordinarias compartido entre evaluadores, autorización excepcional); si no procede, 422 y la transacción deshace
            // el guardado.
            if ($estadoNuevo === 'correccion_solicitada') {
                $marcados = collect($perfilNuevo ?? [])
                    ->filter(fn ($valor, $clave) => $valor === false && str_starts_with((string) $clave, 'doc-'))
                    ->keys()->map(fn ($clave) => (int) substr((string) $clave, 4))->all();

                $ronda = $rondas->solicitar($postulacion, $actor, $evaluacion, $marcados, (string) ($datos['observaciones'] ?? ''));
                $evaluacion->forceFill(['estado' => 'correccion_solicitada', 'fecha_limite_correccion' => $ronda->fecha_limite])->saveQuietly();
            }

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

            if ($finalizaAhora) {
                app(Avisos::class)->evaluacionFinalizada($evaluacion, $postulacion, $actor, $pasaNuevo === true);
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
            'data' => $this->presentar($postulacion, $evaluacion->load('detalles'), $request->user(), true),
        ]);
    }

}
