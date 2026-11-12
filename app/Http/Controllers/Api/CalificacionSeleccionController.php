<?php

namespace App\Http\Controllers\Api;

use App\Enums\ConvocatoriaEstado;
use App\Enums\PostulacionEstado;
use App\Http\Controllers\Api\Concerns\ResultadoAdicional;
use App\Http\Controllers\Api\Concerns\ResultadosDocumentales;
use App\Http\Controllers\Controller;
use App\Models\Convocatoria;
use App\Models\Parametro;
use App\Models\Postulacion;
use App\Models\SeleccionCalificacion;
use App\Models\SeleccionCalificacionCorreccion;
use App\Models\SeleccionConfiguracion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Selección, etapa 4: calificaciones presenciales (entrevista y prueba técnica).
 *
 * Reglas de escritura (todas se vuelven a comprobar dentro de la transacción,
 * con la fila de la convocatoria bloqueada, la misma que usa el PUT de la
 * configuración): convocatoria en_proceso, postulación postulada, actividad
 * activa en una configuración guardada y candidato habilitado documentalmente.
 *
 * Las notas son enteros de centésimas en el cálculo (precisión fija, sin
 * flotantes). El resultado adicional es el promedio ponderado de las
 * actividades activas, redondeado a 2 decimales con la mitad hacia arriba.
 *
 * Los ids llegan como enteros para que el 403 se resuelva antes que el 404.
 */
class CalificacionSeleccionController extends Controller
{
    use ResultadoAdicional;
    use ResultadosDocumentales;

    public function show(Request $request, int $convocatoria, int $postulacion): JsonResponse
    {
        $convocatoria = $this->convocatoriaEnAlcance($request->user(), $convocatoria);
        $postulacion = $this->postulacionDeLaConvocatoria($convocatoria, $postulacion, soloPostulada: true);

        return response()->json(['data' => $this->presentar($convocatoria, $postulacion)]);
    }

    public function update(Request $request, int $convocatoria, int $postulacion, string $actividad): JsonResponse
    {
        $convocatoria = $this->convocatoriaEnAlcance($request->user(), $convocatoria);
        $this->postulacionDeLaConvocatoria($convocatoria, $postulacion, soloPostulada: false);
        abort_unless(in_array($actividad, SeleccionCalificacion::ACTIVIDADES, true), 404);

        $datos = $request->validate([
            'nota' => ['required', function (string $atributo, mixed $valor, \Closure $fallar) {
                if (! is_int($valor) && ! is_float($valor) && ! is_string($valor)) {
                    $fallar('La nota debe ser un número.');

                    return;
                }
                $texto = trim((string) $valor);
                if (! preg_match('/^\d{1,2}(\.\d{1,2})?$/', $texto)) {
                    $fallar('La nota debe estar entre 0 y 10 con hasta dos decimales.');

                    return;
                }
                if ($this->centesimas($texto) > 1000) {
                    $fallar('La nota debe estar entre 0 y 10.');
                }
            }],
            'fecha_realizacion' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now('America/Bogota')->toDateString()],
            'observacion' => ['nullable', 'string', 'max:500'],
            'motivo' => ['nullable', 'string', 'min:10', 'max:500'],
        ], [
            'fecha_realizacion.before_or_equal' => 'La fecha de realización no puede ser futura.',
            'fecha_realizacion.date_format' => 'La fecha de realización debe tener el formato AAAA-MM-DD.',
            'motivo.min' => 'El motivo de la corrección debe tener al menos 10 caracteres.',
        ]);

        $actor = $request->user();
        $notaCentesimas = $this->centesimas(trim((string) $datos['nota']));
        $observacion = $datos['observacion'] ?? null;
        $creada = false;

        DB::transaction(function () use ($convocatoria, $postulacion, $actividad, $datos, $notaCentesimas, $observacion, $actor, &$creada) {
            // Mismo bloqueo que el PUT de configuración: serializa notas y cambios de configuración.
            $conv = Convocatoria::query()->whereKey($convocatoria->id)->lockForUpdate()->first();
            $enProceso = (int) Parametro::porTemaYCodigo('estado_convocatoria', ConvocatoriaEstado::EnProceso->value)->id;
            if (! $conv || (int) $conv->estado_id !== $enProceso) {
                $this->rechazar('convocatoria', 'Solo se pueden registrar calificaciones con la convocatoria en proceso.');
            }

            $fila = Postulacion::query()->whereKey($postulacion)->lockForUpdate()->first();
            $postulada = (int) Parametro::porTemaYCodigo('estado_postulacion', PostulacionEstado::Postulada->value)->id;
            if (! $fila || (int) $fila->estado_id !== $postulada) {
                $this->rechazar('postulacion', 'La postulación ya no está en estado postulada.');
            }

            $configuracion = SeleccionConfiguracion::query()->where('convocatoria_id', $conv->id)->first();
            if ($configuracion === null) {
                $this->rechazar('actividad', 'La convocatoria aún no tiene configuradas las actividades de selección.');
            }
            if (! $configuracion->{$actividad.'_activa'}) {
                $this->rechazar('actividad', 'Esta actividad está desactivada en la configuración de la convocatoria.');
            }

            [$estadoDocumental] = $this->estadoDocumentalDe($fila->id);
            if ($estadoDocumental !== 'habilitado') {
                $this->rechazar('postulacion', 'El candidato no está habilitado documentalmente (estado: '.$estadoDocumental.').');
            }

            $existente = SeleccionCalificacion::query()
                ->where('postulacion_id', $fila->id)
                ->where('actividad', $actividad)
                ->lockForUpdate()
                ->first();

            if ($existente === null) {
                SeleccionCalificacion::create([
                    'convocatoria_id' => $conv->id,
                    'postulacion_id' => $fila->id,
                    'actividad' => $actividad,
                    'nota' => $this->formatear($notaCentesimas),
                    'fecha_realizacion' => $datos['fecha_realizacion'],
                    'observacion' => $observacion,
                    'registrado_por' => $actor->id,
                ]);
                $creada = true;

                return;
            }

            $igual = $this->centesimas((string) $existente->nota) === $notaCentesimas
                && $existente->fecha_realizacion->toDateString() === $datos['fecha_realizacion']
                && ($existente->observacion ?? null) === $observacion;

            // Reenvío idéntico: no duplica ni genera corrección.
            if ($igual) {
                return;
            }

            if (empty($datos['motivo'])) {
                $this->rechazar('motivo', 'Para corregir una nota registrada debes indicar el motivo.');
            }

            SeleccionCalificacionCorreccion::create([
                'calificacion_id' => $existente->id,
                'nota_anterior' => $existente->nota,
                'nota_nueva' => $this->formatear($notaCentesimas),
                'fecha_anterior' => $existente->fecha_realizacion->toDateString(),
                'fecha_nueva' => $datos['fecha_realizacion'],
                'observacion_anterior' => $existente->observacion,
                'observacion_nueva' => $observacion,
                'motivo' => $datos['motivo'],
                'corregido_por' => $actor->id,
            ]);

            $existente->update([
                'nota' => $this->formatear($notaCentesimas),
                'fecha_realizacion' => $datos['fecha_realizacion'],
                'observacion' => $observacion,
            ]);
        });

        return response()->json(
            ['data' => $this->presentar($convocatoria->refresh(), Postulacion::query()->findOrFail($postulacion))],
            $creada ? 201 : 200,
        );
    }

    /** La postulación dentro de la convocatoria; si no pertenece (o no figura como candidata), 404. */
    private function postulacionDeLaConvocatoria(Convocatoria $convocatoria, int $postulacion, bool $soloPostulada): Postulacion
    {
        $consulta = Postulacion::query()
            ->with('user:id,name')
            ->where('convocatoria_id', $convocatoria->id);

        if ($soloPostulada) {
            $consulta->where('estado_id', Parametro::porTemaYCodigo('estado_postulacion', PostulacionEstado::Postulada->value)->id);
        }

        return $consulta->find($postulacion) ?? abort(404, 'Candidato no encontrado.');
    }

    private function rechazar(string $campo, string $mensaje): never
    {
        throw ValidationException::withMessages([$campo => $mensaje]);
    }

    private function presentar(Convocatoria $convocatoria, Postulacion $postulacion): array
    {
        $postulacion->loadMissing('user:id,name');
        $configuracion = SeleccionConfiguracion::query()->where('convocatoria_id', $convocatoria->id)->first();
        $calificaciones = SeleccionCalificacion::query()
            ->with(['registrador:id,name', 'correcciones.corrector:id,name'])
            ->where('postulacion_id', $postulacion->id)
            ->get()
            ->keyBy('actividad');

        [$estadoDocumental] = $this->estadoDocumentalDe($postulacion->id);
        $enProceso = (int) $convocatoria->estado_id === (int) Parametro::porTemaYCodigo('estado_convocatoria', ConvocatoriaEstado::EnProceso->value)->id;

        $actividades = [];
        $requeridas = [];
        $pendientes = [];
        $notasCentesimas = [];

        foreach (SeleccionCalificacion::ACTIVIDADES as $actividad) {
            $activa = $configuracion?->{$actividad.'_activa'} ?? false;
            $peso = $configuracion?->{'peso_'.$actividad};
            $nota = $calificaciones->get($actividad);

            // Una nota real (incluso 0) existe aunque la actividad se desactive después: sigue visible.
            $estado = $nota !== null ? 'registrada' : ($activa ? 'pendiente' : 'desactivada');
            if ($activa) {
                $requeridas[] = $actividad;
                if ($nota === null) {
                    $pendientes[] = $actividad;
                }
            }
            if ($nota !== null) {
                $notasCentesimas[$actividad] = $this->centesimas((string) $nota->nota);
            }

            $actividades[$actividad] = [
                'activa' => $activa,
                'peso' => $peso,
                'estado' => $estado,
                'calificacion' => $nota === null ? null : [
                    'nota' => (string) $nota->nota,
                    'fecha_realizacion' => $nota->fecha_realizacion->toDateString(),
                    'observacion' => $nota->observacion,
                    'registrada_por' => $nota->registrador?->name,
                    'registrada_en' => $nota->created_at?->toIso8601String(),
                    'actualizada_en' => $nota->updated_at?->toIso8601String(),
                    'correcciones' => $nota->correcciones->map(fn (SeleccionCalificacionCorreccion $c) => [
                        'nota_anterior' => (string) $c->nota_anterior,
                        'nota_nueva' => (string) $c->nota_nueva,
                        'fecha_anterior' => $c->fecha_anterior->toDateString(),
                        'fecha_nueva' => $c->fecha_nueva->toDateString(),
                        'observacion_anterior' => $c->observacion_anterior,
                        'observacion_nueva' => $c->observacion_nueva,
                        'motivo' => $c->motivo,
                        'corregida_por' => $c->corrector?->name,
                        'corregida_en' => $c->created_at?->toIso8601String(),
                    ])->values(),
                ],
            ];
        }

        // pendiente_de_configurar != sin_actividades: sin fila de configuración no hay resultado.
        $estadoResultado = match (true) {
            $configuracion === null => 'pendiente_de_configurar',
            $requeridas === [] => 'sin_actividades',
            $pendientes !== [] => 'incompleto',
            default => 'completo',
        };

        return [
            'postulacion_id' => $postulacion->id,
            'aspirante' => $postulacion->user->name,
            'estado_documental' => $estadoDocumental,
            // Se puede calificar solo con la convocatoria en proceso y el candidato habilitado.
            'calificable' => $enProceso && $estadoDocumental === 'habilitado',
            'configuracion' => [
                'configurada' => $configuracion !== null,
                'bloqueada' => SeleccionCalificacion::query()->where('convocatoria_id', $convocatoria->id)->exists(),
            ],
            'estado_convocatoria' => $convocatoria->estado()->value('codigo'),
            'actividades' => $actividades,
            'progreso' => [
                'requeridas' => count($requeridas),
                'registradas' => count($requeridas) - count($pendientes),
                'pendientes' => $pendientes,
                'completo' => $estadoResultado === 'completo',
            ],
            'resultado_adicional' => [
                'estado' => $estadoResultado,
                // Promedio ponderado en centésimas, mitad hacia arriba. null si no está completo.
                'valor' => $estadoResultado === 'completo' ? $this->formatear($this->resultadoPonderado($configuracion, $notasCentesimas)) : null,
            ],
        ];
    }
}
