<?php

namespace App\Http\Controllers\Api;

use App\Enums\ConvocatoriaEstado;
use App\Enums\PostulacionEstado;
use App\Http\Controllers\Api\Concerns\IdentificadorConvocatoria;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreConvocatoriaRequest;
use App\Models\Convocatoria;
use App\Models\Ficha;
use App\Services\Notificaciones\Avisos;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use App\Http\Controllers\Api\Concerns\FiltraConvocatorias;
use App\Http\Controllers\Api\Concerns\ValidaConvocatoria;
use App\Http\Controllers\Api\Concerns\DocumentosDeConvocatoria;
use App\Http\Controllers\Api\Concerns\SkillsDeConvocatoria;
use App\Http\Controllers\Api\Concerns\ReglasDeConvocatoria;
use App\Http\Controllers\Api\Concerns\PersisteConvocatoria;
use App\Http\Controllers\Api\Concerns\PresentaConvocatoria;
use App\Http\Controllers\Api\Concerns\NombresDeAtributos;

/**
 * API del módulo de convocatorias (Paso 2b: crear, listar, ver y editar).
 *
 * Reglas de negocio:
 *  - La ficha se toma del usuario autenticado. Si el usuario tiene una sola
 *    ficha en su alcance, esa es; si tiene varias, el gestor elige una de
 *    ellas al crear (ficha_id) y el backend comprueba que está en su alcance.
 *    En la EDICIÓN la ficha no se puede cambiar: la convocatoria ya tiene
 *    dueño (grupo) y moverla dejaría sus postulaciones en otro grupo.
 *  - El codigo (CONV-AAAA-NNN) y el estado inicial (borrador) los pone el sistema.
 *  - Los estados y los documentos de sistema se resuelven SIEMPRE por codigo.
 *  - La clasificación (tipo_convocatoria, tipo_empleo, tipo_contrato, modalidad)
 *    acepta codigo o id; el frontend debe usar codigo.
 *  - Los mensajes de validación de este módulo salen en español sin tocar APP_LOCALE.
 */
class ConvocatoriaController extends Controller
{
    use NombresDeAtributos;

    use PresentaConvocatoria;

    use PersisteConvocatoria;

    use ReglasDeConvocatoria;

    use SkillsDeConvocatoria;

    use DocumentosDeConvocatoria;

    use ValidaConvocatoria;

    use FiltraConvocatorias;

    use IdentificadorConvocatoria;

    /** Campos que asigna el sistema y que nunca se aceptan en el cuerpo. */
    private const RESERVADOS = ['id', 'codigo', 'estado', 'estado_id', 'creado_por'];

    /** Clasificaciones: campo en el body => tema del catálogo. */
    private const CLASIFICACIONES = [
        'tipo_convocatoria' => 'tipo_convocatoria',
        'tipo_empleo' => 'tipo_empleo',
        'tipo_contrato' => 'tipo_contrato',
        'modalidad' => 'modalidad',
    ];

    /**
     * Buscador del listado: coincidencia parcial (LIKE) en nombre, codigo y cargo.
     * Recorta espacios, escapa los comodines % y _ (para tratarlos como texto
     * literal) y se combina con el resto de filtros. Un q vacío se ignora.
     *
     * El CÓDIGO (CONV-AAAA-NNN) solo se busca para quien tiene
     * 'convocatorias:gestionar': es un dato de gestión y el aspirante no lo
     * ve (tampoco lo recibe en la respuesta).
     */

    /**
     * Crea una convocatoria en estado borrador. La ficha, el codigo, el estado y
     * el creador los pone el sistema. La ficha es la del usuario autenticado:
     * si tiene una sola en su alcance, esa es; si tiene varias, se elige una
     * (ficha_id) y se comprueba que está en su alcance.
     * A los documentos que envie el gestor se le SUMAN los obligatorios
     * (config/convocatorias.php): los obligatorios quedan con obligatorio =
     * true y los opcionales elegidos segun la regla.
     */
    public function store(StoreConvocatoriaRequest $request): JsonResponse
    {
        $user = $request->user();

        // Los reservados los rechaza StoreConvocatoriaRequest antes de validar; validar() toma los datos ya validados y
        // después normaliza (documentos, clasificaciones, skills, fechas), como antes.
        $datos = $this->validar($request, null);
        $fichaId = $this->resolverFicha($user, $request->input('ficha_id'));

        $estadoBorrador = $this->parametroId('estado_convocatoria', ConvocatoriaEstado::Borrador->value);

        // El código se calcula y se guarda dentro de una transacción que bloquea
        // la fila de la ficha: así dos altas simultáneas en la misma ficha se
        // numeran una tras otra y no chocan con la unicidad (ficha_id, codigo).
        // Todo el alta va en la misma transacción: si falla una habilidad o un documento no queda una convocatoria a medias.
        $convocatoria = DB::transaction(function () use ($datos, $fichaId, $estadoBorrador, $user) {
            Ficha::query()->whereKey($fichaId)->lockForUpdate()->first();

            $nueva = new Convocatoria();
            $nueva->forceFill($datos['campos'] + [
                'ficha_id' => $fichaId,
                'codigo' => $this->siguienteCodigo($fichaId),
                'estado_id' => $estadoBorrador,
                'creado_por' => $user->id,
            ])->save();

            $this->reemplazarSkills($nueva, $datos['skills'] ?? []);
            $this->reemplazarDocumentos($nueva, $this->conDocumentosObligatorios($datos['documentos']));

            return $nueva;
        });

        // Refresca para leer los defaults de la base (p. ej. vacantes) antes de responder.
        $convocatoria->refresh();
        $convocatoria->load($this->relaciones());
        $this->cargarCamposDePostulacion($convocatoria, $user);

        return response()->json([
            'data' => $this->presentarDetalle($convocatoria, $user),
        ], 201);
    }

    /**
     * Detalle de una convocatoria. Fuera del alcance (otra ficha o, para un
     * aspirante, no publicada) se responde 404, sin revelar que existe.
     */
    public function show(Request $request, Convocatoria $convocatoria): JsonResponse
    {
        $this->autorizar($convocatoria, $request);

        $convocatoria->load($this->relaciones());
        $this->cargarCamposDePostulacion($convocatoria, $request->user());

        return response()->json([
            'data' => $this->presentarDetalle($convocatoria, $request->user()),
        ]);
    }

    /**
     * Edición parcial. Se permite en borrador y en publicada (todo salvo codigo y
     * ficha). En proceso y cerrada no se edita (422). La regla de "publicada" se
     * revisará cuando exista el módulo de postulaciones.
     */
    public function update(Request $request, Convocatoria $convocatoria): JsonResponse
    {
        $this->autorizar($convocatoria, $request);
        // La ficha no se puede cambiar en la edición: mover la convocatoria
        // dejaría sus postulaciones en otro grupo.
        $this->rechazarReservados($request, ['ficha_id']);

        $estado = $convocatoria->estado?->codigo;

        if (in_array($estado, [ConvocatoriaEstado::EnProceso->value, ConvocatoriaEstado::Cerrada->value], true)) {
            throw ValidationException::withMessages([
                'estado' => $estado === ConvocatoriaEstado::Cerrada->value
                    ? 'No se puede editar una convocatoria cerrada.'
                    : 'No se puede editar una convocatoria en proceso.',
            ]);
        }

        // Paso 5 (regla H): comprobar postulaciones y guardar van en UNA
        // transacción con candado de fila. El SELECT ... FOR UPDATE serializa con
        // el INSERT del aspirante (la FK de postulaciones bloquea la fila padre),
        // así no puede colarse una postulación entre el existe() y el UPDATE.
        $convocatoria = DB::transaction(function () use ($request, $convocatoria) {
            $actual = Convocatoria::query()
                ->whereKey($convocatoria->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $normalizado = $this->validar($request, $actual);

            // Documentos: con CUALQUIER postulación (también borrador) no se agregan,
            // quitan ni cambian de obligatoriedad o porcentaje. Si el reenvío es
            // idéntico se acepta y no se reescribe nada.
            if ($normalizado['documentos'] !== null && $actual->postulaciones()->exists()) {
                $this->bloquearDocumentosConPostulaciones($actual, $normalizado['documentos']);
                $normalizado['documentos'] = null;
            }

            if ($actual->estado?->codigo === ConvocatoriaEstado::Publicada->value
                // Regla H: un BORRADOR todavía no es postulante y NO congela la
                // convocatoria; cualquier otro estado (postulada/retirada) sí.
                && $actual->postulaciones()
                    ->where('estado_id', '!=', $this->parametroId('estado_postulacion', PostulacionEstado::Borrador->value))
                    ->exists()) {
                // Lanza 422 si algún campo bloqueado cambió. Si no, el reenvío
                // idéntico se acepta; skills iguales no se reescriben.
                $this->bloquearCamposConPostulaciones($actual, $normalizado);

                $normalizado['skills'] = null;
            } elseif ($normalizado['documentos'] !== null) {
                // Sin el bloqueo de postulaciones, se garantizan los documentos
                // siempre obligatorios (config/convocatorias.php).
                $normalizado['documentos'] = $this->conDocumentosObligatorios($normalizado['documentos']);
            }

            $actual->forceFill($normalizado['campos'])->save();

            if ($normalizado['skills'] !== null) {
                $this->reemplazarSkills($actual, $normalizado['skills']);
            }

            if ($normalizado['documentos'] !== null) {
                $this->reemplazarDocumentos($actual, $normalizado['documentos']);
            }

            return $actual;
        });

        $convocatoria->load($this->relaciones());
        $this->cargarCamposDePostulacion($convocatoria, $request->user());

        return response()->json([
            'data' => $this->presentarDetalle($convocatoria, $request->user()),
        ]);
    }

    /**
     * Cambio de estado por CODIGO (borrador -> publicada -> en_proceso -> cerrada).
     * El estado se resuelve SIEMPRE por codigo (nunca por id ni por nombre: los ids
     * cambian entre local y Render). Avanza de a UN paso, no permite repetir, saltar
     * ni retroceder, y no cambia solo por fecha. Al publicar exige los campos minimos.
     */
    public function estado(Request $request, Convocatoria $convocatoria): JsonResponse
    {
        $this->autorizar($convocatoria, $request);

        $datos = $request->validate([
            'estado' => ['required', 'string'],
        ], $this->mensajes(), $this->atributos());

        $actual = $convocatoria->estado?->codigo;
        $destino = $datos['estado'];

        // Se identifica por codigo: un numero (id) no es un estado valido.
        if (ctype_digit($destino)) {
            throw ValidationException::withMessages([
                'estado' => 'El estado debe enviarse por su código, no por su id.',
            ]);
        }

        if (! in_array($destino, ConvocatoriaEstado::codigos(), true)) {
            throw ValidationException::withMessages(['estado' => 'El estado no es válido.']);
        }

        if ($actual === $destino) {
            throw ValidationException::withMessages(['estado' => "La convocatoria ya está en estado {$destino}."]);
        }

        $permitidas = ConvocatoriaEstado::transiciones()[$actual] ?? [];

        if ($permitidas === []) {
            throw ValidationException::withMessages([
                'estado' => "No se puede cambiar el estado: la convocatoria ya está {$actual} y no admite más transiciones.",
            ]);
        }

        if (! in_array($destino, $permitidas, true)) {
            throw ValidationException::withMessages([
                'estado' => "No se puede pasar de {$actual} a {$destino}. El siguiente estado permitido es {$permitidas[0]}.",
            ]);
        }

        if ($actual === ConvocatoriaEstado::Borrador->value && $destino === ConvocatoriaEstado::Publicada->value) {
            $this->validarPublicacion($convocatoria);
        }

        $convocatoria->forceFill([
            'estado_id' => $this->parametroId('estado_convocatoria', $destino),
        ])->save();

        app(Avisos::class)->convocatoriaCambio($convocatoria, ConvocatoriaEstado::from($destino));

        $convocatoria->load($this->relaciones());
        $this->cargarCamposDePostulacion($convocatoria, $request->user());

        return response()->json([
            'data' => $this->presentarDetalle($convocatoria, $request->user()),
        ]);
    }

    // ── Mensajes en español (sin cambiar APP_LOCALE) ────────────────────

}
