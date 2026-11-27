<?php

namespace App\Http\Controllers\Api;

use App\Enums\ConvocatoriaEstado;
use App\Enums\PostulacionEstado;
use App\Http\Controllers\Api\Concerns\ResultadosDocumentales;
use App\Http\Controllers\Controller;
use App\Models\Convocatoria;
use App\Models\Parametro;
use App\Models\Postulacion;
use App\Models\SeleccionCitacion;
use App\Models\SeleccionConfiguracion;
use App\Services\Citaciones\PresentaCitaciones;
use App\Services\Citaciones\ProgramadorCitaciones;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Selección: citaciones (entrevista o prueba técnica) de un candidato. Consultar pide seleccion:ver; programar, reprogramar y cancelar,
 * seleccion:decidir, ambos con el alcance por ficha de siempre. Solo se ofrecen para las actividades activas de la convocatoria. No
 * condicionan las calificaciones ni la elegibilidad. La hora llega y se muestra en Colombia; se guarda en UTC.
 */
class CitacionSeleccionController extends Controller
{
    use ResultadosDocumentales;

    public function __construct(private ProgramadorCitaciones $programador) {}

    public function index(Request $request, int $convocatoria, int $postulacion): JsonResponse
    {
        $conv = $this->convocatoriaEnAlcance($request->user(), $convocatoria);
        $fila = Postulacion::query()->where('convocatoria_id', $conv->id)->find($postulacion) ?? abort(404, 'Candidato no encontrado.');

        $configuracion = SeleccionConfiguracion::query()->where('convocatoria_id', $conv->id)->first();
        $actividades = collect(['entrevista', 'prueba_tecnica'])
            ->filter(fn (string $a) => $configuracion?->{$a.'_activa'})
            ->map(fn (string $a) => ['id' => $a, 'etiqueta' => PresentaCitaciones::ETIQUETAS[$a]])->values()->all();

        $citas = SeleccionCitacion::query()->with(['creadora:id,name', 'eventos.usuario:id,name'])
            ->where('postulacion_id', $fila->id)->orderByDesc('id')->get();

        return response()->json(['data' => [
            'actividades' => $actividades,
            'razon' => $actividades === [] ? 'Esta convocatoria no tiene actividades presenciales activas.' : $this->razonDeBloqueo($conv, $fila),
            'citaciones' => $citas->map(fn ($c) => PresentaCitaciones::presentar($c, true))->all(),
        ]]);
    }

    public function store(Request $request, int $convocatoria, int $postulacion): JsonResponse
    {
        $this->convocatoriaEnAlcance($request->user(), $convocatoria);
        $actividad = $request->validate(['actividad' => ['required', 'string', 'in:entrevista,prueba_tecnica']])['actividad'];

        [$cita, $creada] = $this->programador->programar($request->user(), $convocatoria, $postulacion, $actividad, $this->datos($request));

        return $this->responder($cita, $creada ? 201 : 200);
    }

    public function update(Request $request, int $convocatoria, int $postulacion, int $citacion): JsonResponse
    {
        $this->convocatoriaEnAlcance($request->user(), $convocatoria);
        [$cita] = $this->programador->reprogramar($request->user(), $convocatoria, $postulacion, $citacion, $this->datos($request));

        return $this->responder($cita, 200);
    }

    public function cancelar(Request $request, int $convocatoria, int $postulacion, int $citacion): JsonResponse
    {
        $this->convocatoriaEnAlcance($request->user(), $convocatoria);
        $motivo = trim((string) $request->validate([
            'motivo' => ['required', 'string', 'min:10', 'max:500'],
        ], ['motivo.required' => 'Escribe el motivo de la cancelación.', 'motivo.min' => 'El motivo de la cancelación debe tener al menos 10 caracteres.'])['motivo']);
        [$cita] = $this->programador->cancelar($request->user(), $convocatoria, $postulacion, $citacion, $motivo);

        return $this->responder($cita, 200);
    }

    private function responder(SeleccionCitacion $cita, int $estado): JsonResponse
    {
        $cita->load(['creadora:id,name', 'eventos.usuario:id,name']);

        return response()->json(['data' => PresentaCitaciones::presentar($cita, true)], $estado);
    }

    /** @return array{fecha_hora: Carbon, lugar_o_enlace: string, indicaciones: ?string} */
    private function datos(Request $request): array
    {
        $d = $request->validate([
            'fecha' => ['required', 'date_format:Y-m-d'],
            'hora' => ['required', 'date_format:H:i'],
            'lugar_o_enlace' => ['required', 'string', 'max:255', $this->reglaLugarOEnlace(...)],
            'indicaciones' => ['nullable', 'string', 'max:500'],
        ], [
            'fecha.required' => 'Indica la fecha.',
            'hora.required' => 'Indica la hora.',
            'lugar_o_enlace.required' => 'Indica el lugar o el enlace.',
            'lugar_o_enlace.max' => 'El lugar o enlace admite hasta 255 caracteres.',
            'indicaciones.max' => 'Las indicaciones admiten hasta 500 caracteres.',
            'fecha.date_format' => 'La fecha debe tener el formato AAAA-MM-DD.',
            'hora.date_format' => 'La hora debe tener el formato HH:MM (24 horas).',
        ]);

        // La fecha y la hora que escribe la persona son de Colombia; se guardan en UTC.
        $cuando = Carbon::createFromFormat('Y-m-d H:i', $d['fecha'].' '.$d['hora'], 'America/Bogota')->utc();
        if ($cuando->lte(now())) {
            throw ValidationException::withMessages(['fecha' => 'La cita debe ser en una fecha y hora futuras (hora de Colombia).']);
        }

        return [
            'fecha_hora' => $cuando,
            'lugar_o_enlace' => trim($d['lugar_o_enlace']),
            'indicaciones' => isset($d['indicaciones']) && trim($d['indicaciones']) !== '' ? trim($d['indicaciones']) : null,
        ];
    }

    /** Un lugar es texto sin saltos de línea ni «<» «>»; un enlace solo puede ser http o https (nunca javascript:, data: u otro). */
    private function reglaLugarOEnlace(string $atributo, mixed $valor, \Closure $fallar): void
    {
        $texto = trim((string) $valor);
        if ($texto === '') {
            $fallar('Indica el lugar o el enlace.');

            return;
        }
        if (preg_match('/[\x00-\x1F\x7F<>]/', $texto)) {
            $fallar('El lugar o enlace no puede tener saltos de línea ni los símbolos < y >.');

            return;
        }
        if (preg_match('/^[a-z][a-z0-9+.\-]*:/i', $texto) && (! preg_match('#^https?://#i', $texto) || ! filter_var($texto, FILTER_VALIDATE_URL) || preg_match('/\s/', $texto))) {
            $fallar('Si es un enlace, debe empezar por http:// o https:// y no tener espacios.');
        }
    }

    /** Por qué ahora no se puede programar (o null si se puede), con las mismas condiciones del servicio. */
    private function razonDeBloqueo(Convocatoria $conv, Postulacion $fila): ?string
    {
        $enProceso = (int) Parametro::porTemaYCodigo('estado_convocatoria', ConvocatoriaEstado::EnProceso->value)->id;
        if ((int) $conv->estado_id !== $enProceso) {
            return 'Solo se pueden programar citas con la convocatoria en proceso.';
        }
        $postulada = (int) Parametro::porTemaYCodigo('estado_postulacion', PostulacionEstado::Postulada->value)->id;
        if ((int) $fila->estado_id !== $postulada) {
            return 'La postulación ya no está en estado postulada.';
        }
        [$estado] = $this->estadoDocumentalDe($fila->id);

        return $estado === 'habilitado' ? null : 'El candidato no está habilitado documentalmente.';
    }
}
