<?php

namespace App\Http\Controllers\Api;

use App\Enums\ConvocatoriaEstado;
use App\Enums\PostulacionEstado;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Convocatoria;
use App\Models\Parametro;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
     * Listado paginado dentro del alcance de fichas del usuario. Quien gestiona ve
     * todos los estados de sus fichas; el aspirante solo ve las publicadas.
     *
     * Paso 4: cada fila lleva, según el rol, `postulantes` (conteo de postuladas,
     * solo a quien puede gestionar) o `mi_postulacion` (la del autenticado, solo
     * al aspirante). N+1: el conteo es una subconsulta y las postulaciones del
     * aspirante se cargan en UNA consulta para toda la página.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $datos = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'estado' => ['nullable', 'string'],
            'ficha_id' => ['nullable', 'integer', 'exists:fichas,id'],
            'q' => ['nullable', 'string', 'max:150'],
        ], $this->mensajes(), $this->atributos());

        if (($datos['estado'] ?? null) !== null && ! in_array($datos['estado'], ConvocatoriaEstado::codigos(), true)) {
            throw ValidationException::withMessages(['estado' => 'El estado no es válido.']);
        }

        $esGestor = $user->can('convocatorias:gestionar');

        $query = Convocatoria::query()
            ->with($this->relaciones())
            ->whereIn('ficha_id', $user->fichaIds());

        if (! $esGestor) {
            // El aspirante solo ve las publicadas.
            $query->where('estado_id', $this->parametroId('estado_convocatoria', ConvocatoriaEstado::Publicada->value));
        } elseif (($datos['estado'] ?? null) !== null) {
            $query->where('estado_id', $this->parametroId('estado_convocatoria', $datos['estado']));
        }

        // Filtro por ficha del alcance (lo usan super admin e instructor cuando
        // tienen más de una). Se aplica SOBRE el whereIn previo, así que una
        // ficha ajena simplemente no devuelve filas: nunca amplía la visibilidad.
        if (($datos['ficha_id'] ?? null) !== null) {
            $query->where('ficha_id', $datos['ficha_id']);
        }

        // El buscador se aplica SOBRE la consulta ya acotada por ficha/estado:
        // nunca amplía el alcance.
        $this->aplicarBuscador($query, $datos['q'] ?? null);

        // Campos de postulación que le corresponden al usuario (sin N+1).
        $this->aplicarCamposDePostulacion($query, $user);

        $listado = $query
            ->orderByDesc('fecha_apertura')
            ->orderByDesc('id')
            ->paginate($datos['per_page'] ?? 20, ['*'], 'page', $datos['page'] ?? 1);

        return response()->json([
            'data' => $listado->getCollection()
                ->map(fn (Convocatoria $c) => $this->presentarResumen($c, $user))
                ->values(),
            'meta' => [
                'current_page' => $listado->currentPage(),
                'last_page' => $listado->lastPage(),
                'per_page' => $listado->perPage(),
                'total' => $listado->total(),
            ],
        ]);
    }

    /**
     * Buscador del listado: coincidencia parcial (LIKE) en nombre, codigo y cargo.
     * Recorta espacios, escapa los comodines % y _ (para tratarlos como texto
     * literal) y se combina con el resto de filtros. Un q vacío se ignora.
     */
    private function aplicarBuscador(Builder $query, ?string $q): void
    {
        $termino = trim((string) $q);

        if ($termino === '') {
            return;
        }

        // Se usa '!' como carácter de escape: es válido en MySQL, SQLite y
        // Postgres y no obliga a pelear con el escapado de la barra invertida.
        $escapado = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $termino);
        $patron = '%'.$escapado.'%';

        $query->where(function (Builder $sub) use ($patron) {
            $sub->whereRaw("nombre LIKE ? ESCAPE '!'", [$patron])
                ->orWhereRaw("codigo LIKE ? ESCAPE '!'", [$patron])
                ->orWhereRaw("cargo LIKE ? ESCAPE '!'", [$patron]);
        });
    }

    /**
     * Crea una convocatoria en estado borrador. La ficha, el codigo, el estado y
     * el creador los pone el sistema. La ficha es la del usuario autenticado:
     * si tiene una sola en su alcance, esa es; si tiene varias, se elige una
     * (ficha_id) y se comprueba que está en su alcance.
     * A los documentos que envie el gestor se le SUMAN los obligatorios
     * (config/convocatorias.php): los obligatorios quedan con obligatorio =
     * true y los opcionales elegidos segun la regla.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        $this->rechazarReservados($request);
        $datos = $this->validar($request, null);
        $fichaId = $this->resolverFicha($user, $request->input('ficha_id'));

        $convocatoria = new Convocatoria();
        $convocatoria->forceFill($datos['campos'] + [
            'ficha_id' => $fichaId,
            'codigo' => $this->siguienteCodigo($fichaId),
            'estado_id' => $this->parametroId('estado_convocatoria', ConvocatoriaEstado::Borrador->value),
            'creado_por' => $user->id,
        ])->save();

        $this->reemplazarSkills($convocatoria, $datos['skills'] ?? []);
        $this->reemplazarDocumentos($convocatoria, $this->conDocumentosObligatorios($datos['documentos']));

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

            if ($actual->estado?->codigo === ConvocatoriaEstado::Publicada->value
                // Regla H: un BORRADOR todavía no es postulante y NO congela la
                // convocatoria; cualquier otro estado (postulada/retirada) sí.
                && $actual->postulaciones()
                    ->where('estado_id', '!=', $this->parametroId('estado_postulacion', PostulacionEstado::Borrador->value))
                    ->exists()) {
                // Lanza 422 si algún campo bloqueado cambió. Si no, el reenvío
                // idéntico se acepta; skills/documentos iguales no se reescriben.
                $this->bloquearCamposConPostulaciones($actual, $normalizado);

                $normalizado['skills'] = null;
                // Con postulaciones NO se reinyectan los obligatorios: los
                // documentos guardados se dejan intactos (regla H).
                $normalizado['documentos'] = null;
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

        $convocatoria->load($this->relaciones());
        $this->cargarCamposDePostulacion($convocatoria, $request->user());

        return response()->json([
            'data' => $this->presentarDetalle($convocatoria, $request->user()),
        ]);
    }

    /**
     * Requisitos para publicar (borrador -> publicada): nombre, cargo, area,
     * descripcion, fecha_apertura y fecha_cierre no vacios; fecha_cierre no
     * anterior a hoy (America/Bogota) ni anterior a fecha_apertura; y
     * salario_max >= salario_min. Acumula un error POR CAMPO.
     */
    private function validarPublicacion(Convocatoria $convocatoria): void
    {
        $errores = [];

        foreach ([
            'nombre' => 'El nombre es obligatorio para publicar.',
            'cargo' => 'El cargo es obligatorio para publicar.',
            'area' => 'El área es obligatoria para publicar.',
            'descripcion' => 'La descripción es obligatoria para publicar.',
        ] as $campo => $mensaje) {
            if (! $this->noVacio($convocatoria->{$campo})) {
                $errores[$campo][] = $mensaje;
            }
        }

        foreach ([
            'fecha_apertura' => 'La fecha de apertura es obligatoria para publicar.',
            'fecha_cierre' => 'La fecha de cierre es obligatoria para publicar.',
        ] as $campo => $mensaje) {
            if ($convocatoria->{$campo} === null) {
                $errores[$campo][] = $mensaje;
            }
        }

        // Sin cambio automatico por fecha, pero al publicar la fecha de cierre no
        // puede estar en el pasado, comparando en la zona de Colombia.
        if ($convocatoria->fecha_cierre !== null
            && $convocatoria->fecha_cierre->toDateString() < now('America/Bogota')->toDateString()) {
            $errores['fecha_cierre'][] = 'La fecha de cierre no puede estar en el pasado.';
        }

        // Coherencia entre fechas: fecha_cierre >= fecha_apertura (misma regla
        // que en alta/edición).
        if ($convocatoria->fecha_apertura !== null && $convocatoria->fecha_cierre !== null
            && $convocatoria->fecha_cierre->toDateString() < $convocatoria->fecha_apertura->toDateString()) {
            $errores['fecha_cierre'][] = 'La fecha de cierre debe ser posterior o igual a la fecha de apertura.';
        }

        // Coherencia de salarios: salario_max >= salario_min (misma regla que en
        // alta/edición).
        if ($convocatoria->salario_min !== null && $convocatoria->salario_max !== null
            && (float) $convocatoria->salario_max < (float) $convocatoria->salario_min) {
            $errores['salario_max'][] = 'El salario máximo debe ser mayor o igual que el salario mínimo.';
        }

        if ($errores !== []) {
            throw ValidationException::withMessages($errores);
        }
    }

    /** Un valor de texto esta "no vacio" si al recortar espacios queda algo. */
    private function noVacio(mixed $valor): bool
    {
        return is_string($valor) ? trim($valor) !== '' : $valor !== null;
    }

    // ── Validación ─────────────────────────────────────────────────────

    /**
     * Valida y normaliza el cuerpo. Devuelve:
     *  - campos:     valores listos para forceFill (solo los enviados).
     *  - skills:     array en alta; null en edición si no se envió.
     *  - documentos: array|null (null = no tocar en edición / solo sistema en alta).
     */
    private function validar(Request $request, ?Convocatoria $actual): array
    {
        $esAlta = $actual === null;

        $maxSkills = (int) config('convocatorias.skills_max');
        $maxSkill = (int) config('convocatorias.skill_max_longitud');

        $reglas = [
            'nombre' => [$esAlta ? 'required' : 'sometimes', 'filled', 'string', 'max:150'],
            'cargo' => ['nullable', 'string', 'max:120'],
            'area' => ['nullable', 'string', 'max:120'],
            'descripcion' => ['nullable', 'string'],
            'vacantes' => ['nullable', 'integer', 'min:1'],
            'salario_min' => ['nullable', 'numeric', 'min:0', 'max:999999999999.99'],
            'salario_max' => ['nullable', 'numeric', 'min:0', 'max:999999999999.99'],
            'funciones' => ['nullable', 'string'],
            'responsabilidades' => ['nullable', 'string'],
            'perfil_requerido' => ['nullable', 'string'],
            'formacion' => ['nullable', 'string'],
            'experiencia' => ['nullable', 'string'],
            'competencias' => ['nullable', 'string'],
            'conocimientos_especificos' => ['nullable', 'string'],
            'fecha_apertura' => ['nullable', 'date'],
            'fecha_cierre' => ['nullable', 'date'],
            'fecha_evaluacion' => ['nullable', 'date'],
            'skills' => ['nullable', 'array', 'max:'.$maxSkills],
            'skills.*' => ['nullable', 'string', 'max:'.$maxSkill],
            'documentos' => ['nullable', 'array'],
            'tipo_convocatoria' => ['nullable', 'string'],
            'tipo_convocatoria_id' => ['nullable', 'integer'],
            'tipo_empleo' => ['nullable', 'string'],
            'tipo_empleo_id' => ['nullable', 'integer'],
            'tipo_contrato' => ['nullable', 'string'],
            'tipo_contrato_id' => ['nullable', 'integer'],
            'modalidad' => ['nullable', 'string'],
            'modalidad_id' => ['nullable', 'integer'],
        ];

        $datos = $request->validate($reglas, $this->mensajes(), $this->atributos());

        $errores = [];

        // Salarios: salario_max >= salario_min (con el valor actual en edición).
        $min = array_key_exists('salario_min', $datos) ? $datos['salario_min'] : $actual?->salario_min;
        $max = array_key_exists('salario_max', $datos) ? $datos['salario_max'] : $actual?->salario_max;
        if ($min !== null && $max !== null && (float) $max < (float) $min) {
            $errores['salario_max'][] = 'El salario máximo debe ser mayor o igual que el salario mínimo.';
        }

        // Fechas: fecha_cierre >= fecha_apertura (con el valor actual en edición).
        $apertura = array_key_exists('fecha_apertura', $datos)
            ? $datos['fecha_apertura']
            : $actual?->fecha_apertura?->toDateString();
        $cierre = array_key_exists('fecha_cierre', $datos)
            ? $datos['fecha_cierre']
            : $actual?->fecha_cierre?->toDateString();
        if ($apertura !== null && $cierre !== null && strtotime((string) $cierre) < strtotime((string) $apertura)) {
            $errores['fecha_cierre'][] = 'La fecha de cierre debe ser posterior o igual a la fecha de apertura.';
        }

        // Clasificación por código o id.
        $clasificacion = [];
        foreach (self::CLASIFICACIONES as $campo => $tema) {
            $id = $this->resolverClasificacion($datos, $campo, $tema, $esAlta, $errores);
            if ($id !== null) {
                $clasificacion[$campo.'_id'] = $id;
            }
        }

        // Documentos: el gestor elige opcionales; los obligatorios se agregan
        // aparte con conDocumentosObligatorios (store y edición sin postulaciones).
        if ($esAlta) {
            $documentos = $this->normalizarDocumentos($datos['documentos'] ?? null, $errores);
        } else {
            $documentos = array_key_exists('documentos', $datos)
                ? $this->normalizarDocumentos($datos['documentos'] ?? [], $errores)
                : null;
        }

        if ($errores !== []) {
            throw ValidationException::withMessages($errores);
        }

        $campos = [];
        foreach ([
            'nombre', 'cargo', 'area', 'descripcion', 'vacantes', 'salario_min', 'salario_max',
            'funciones', 'responsabilidades', 'perfil_requerido', 'formacion', 'experiencia',
            'competencias', 'conocimientos_especificos', 'fecha_apertura', 'fecha_cierre', 'fecha_evaluacion',
        ] as $campo) {
            if (array_key_exists($campo, $datos)) {
                $campos[$campo] = $datos[$campo];
            }
        }

        $campos += $clasificacion;

        if ($esAlta) {
            $skills = $this->normalizarSkills($datos['skills'] ?? []);
        } else {
            $skills = array_key_exists('skills', $datos)
                ? $this->normalizarSkills($datos['skills'] ?? [])
                : null;
        }

        return [
            'campos' => $campos,
            'skills' => $skills,
            'documentos' => $documentos,
        ];
    }

    /**
     * Resuelve una clasificación por código (`$campo`) o por id (`$campo_id`).
     * En alta, si no viene ninguno, usa el es_defecto del tema.
     */
    private function resolverClasificacion(array $datos, string $campo, string $tema, bool $esAlta, array &$errores): ?int
    {
        $tieneCodigo = array_key_exists($campo, $datos) && $datos[$campo] !== null && $datos[$campo] !== '';
        $tieneId = array_key_exists($campo.'_id', $datos) && $datos[$campo.'_id'] !== null;

        if ($tieneCodigo && $tieneId) {
            $errores[$campo][] = 'Envía el código o el id, no ambos.';
            return null;
        }

        if ($tieneCodigo) {
            $parametro = Parametro::porTemaYCodigo($tema, (string) $datos[$campo]);
            if (! $parametro || ! $parametro->activo) {
                $errores[$campo][] = 'El valor seleccionado no es válido.';
                return null;
            }

            return (int) $parametro->id;
        }

        if ($tieneId) {
            $parametro = Parametro::query()
                ->whereKey($datos[$campo.'_id'])
                ->whereHas('tema', fn ($q) => $q->where('codigo', $tema))
                ->where('activo', true)
                ->first();

            if (! $parametro) {
                $errores[$campo.'_id'][] = 'El valor seleccionado no es válido.';
                return null;
            }

            return (int) $parametro->id;
        }

        if (! $esAlta) {
            return null;
        }

        $porDefecto = Parametro::query()
            ->whereHas('tema', fn ($q) => $q->where('codigo', $tema))
            ->where('es_defecto', true)
            ->where('activo', true)
            ->first();

        abort_unless($porDefecto, 500, "No hay valor por defecto para el tema {$tema}. ¿Se ejecutó ParametroSeeder?");

        return (int) $porDefecto->id;
    }

    /**
     * Documentos válidos del tema tipo_documento enviados por el gestor. Acepta
     * dos formatos por ítem:
     *  - clásico: un código (string) o un id (int).
     *  - extendido: ['codigo'|'id' => ..., 'obligatorio' => bool]. Se acepta por
     *    compatibilidad, pero el valor 'obligatorio' se IGNORA: la obligatoriedad
     *    la decide el backend (config/convocatorias.php), no el cliente.
     * Devuelve una lista de ['id' => int] sin duplicados (gana la primera
     * aparición), o null si no se envió la clave.
     */
    private function normalizarDocumentos(?array $documentos, array &$errores): ?array
    {
        if ($documentos === null) {
            return null;
        }

        $normalizados = [];
        foreach (array_values($documentos) as $indice => $documento) {
            $referencia = $documento;

            if (is_array($documento)) {
                // El 'obligatorio' del formato extendido se acepta pero se ignora.
                $referencia = $documento['codigo'] ?? $documento['id'] ?? null;
            }

            $parametro = null;

            if (is_int($referencia)) {
                $parametro = Parametro::query()
                    ->whereKey($referencia)
                    ->whereHas('tema', fn ($q) => $q->where('codigo', 'tipo_documento'))
                    ->where('activo', true)
                    ->first();
            } elseif (is_string($referencia) && $referencia !== '') {
                $parametro = Parametro::porTemaYCodigo('tipo_documento', $referencia);
                if ($parametro && ! $parametro->activo) {
                    $parametro = null;
                }
            }

            if (! $parametro) {
                $errores["documentos.{$indice}"][] = 'El documento seleccionado no es válido.';
                continue;
            }

            $id = (int) $parametro->id;
            if (! array_key_exists($id, $normalizados)) {
                $normalizados[$id] = ['id' => $id];
            }
        }

        return array_values($normalizados);
    }

    /** Recorta, descarta vacías y deduplica sin distinguir mayúsculas. */
    private function normalizarSkills(array $skills): array
    {
        $limpias = [];
        $vistas = [];

        foreach ($skills as $skill) {
            $texto = trim((string) $skill);
            if ($texto === '') {
                continue;
            }

            $clave = mb_strtolower($texto);
            if (isset($vistas[$clave])) {
                continue;
            }

            $vistas[$clave] = true;
            $limpias[] = $texto;
        }

        return $limpias;
    }

    /**
     * Rechaza los campos que asigna el sistema. `$extra` son los que además
     * están reservados en este contexto concreto (ficha_id al editar).
     */
    private function rechazarReservados(Request $request, array $extra = []): void
    {
        $presentes = array_values(array_filter(
            array_merge(self::RESERVADOS, $extra),
            fn (string $campo) => $request->has($campo),
        ));

        if ($presentes !== []) {
            throw ValidationException::withMessages([
                'campos' => 'Estos campos los asigna el sistema y no se pueden enviar: '.implode(', ', $presentes).'.',
            ]);
        }
    }

    // ── Persistencia ───────────────────────────────────────────────────

    /**
     * Ficha de la convocatoria. Si el gestor elige una (ficha_id), tiene que
     * estar en su alcance; si no elige ninguna y solo tiene una ficha en su
     * alcance, esa es; si tiene varias y no elige, se le pide que elija (422).
     */
    private function resolverFicha(User $user, mixed $elegida = null): int
    {
        $alcance = array_map('intval', $user->fichaIds());

        if ($elegida !== null && $elegida !== '') {
            $fichaId = (int) $elegida;

            if (! in_array($fichaId, $alcance, true)) {
                throw ValidationException::withMessages([
                    'ficha_id' => 'La ficha debe ser una de las tuyas.',
                ]);
            }

            return $fichaId;
        }

        if (count($alcance) !== 1) {
            throw ValidationException::withMessages([
                'ficha' => 'Elige la ficha de la convocatoria: tu usuario tiene '.count($alcance).' fichas en su alcance.',
            ]);
        }

        return $alcance[0];
    }

    /** Código CONV-AAAA-NNN, secuencial por ficha y año. */
    private function siguienteCodigo(int $fichaId): string
    {
        $anio = now('America/Bogota')->year;

        $ultimo = Convocatoria::query()
            ->where('ficha_id', $fichaId)
            ->where('codigo', 'like', "CONV-{$anio}-%")
            ->orderByDesc('codigo')
            ->value('codigo');

        $secuencia = 1;
        if ($ultimo) {
            $partes = explode('-', $ultimo);
            $secuencia = ((int) end($partes)) + 1;
        }

        return sprintf('CONV-%d-%03d', $anio, $secuencia);
    }

    private function reemplazarSkills(Convocatoria $convocatoria, array $skills): void
    {
        $convocatoria->skills()->delete();

        foreach ($skills as $nombre) {
            $convocatoria->skills()->create(['nombre' => $nombre]);
        }
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
     * Ids de los documentos opcionales que, si el gestor los incluye, se guardan
     * como obligatorios (config/convocatorias.php: libreta_militar y
     * tarjeta_profesional).
     *
     * @return array<int, int>
     */
    private function idsDocumentosOpcionalesObligatorios(): array
    {
        $ids = [];

        foreach ((array) config('convocatorias.documentos_opcionales', []) as $codigo) {
            $parametro = Parametro::porTemaYCodigo('tipo_documento', (string) $codigo);

            if ($parametro && $parametro->activo) {
                $ids[(int) $parametro->id] = true;
            }
        }

        return array_keys($ids);
    }

    /**
     * Combina los documentos elegidos por el gestor con los obligatorios y fija
     * la obligatoriedad: true para los siempre obligatorios y para los
     * opcionales que la regla marca como obligatorios cuando se incluyen
     * (libreta_militar y tarjeta_profesional). Los obligatorios van primero y
     * siempre, aunque el gestor no los haya enviado. Sin duplicados.
     *
     * @param  array<int, array{id:int}>|null  $documentos
     * @return array<int, array{id:int, obligatorio:bool}>
     */
    private function conDocumentosObligatorios(?array $documentos): array
    {
        $obligatorios = array_fill_keys($this->idsDocumentosObligatorios(), true);
        $opcionalesObligatorios = array_fill_keys($this->idsDocumentosOpcionalesObligatorios(), true);

        $final = [];

        // 1) Los siempre obligatorios, en el orden de config y con true.
        foreach (array_keys($obligatorios) as $id) {
            $final[$id] = ['id' => $id, 'obligatorio' => true];
        }

        // 2) Los elegidos por el gestor (sin repetir los obligatorios).
        foreach ($documentos ?? [] as $documento) {
            $id = (int) $documento['id'];

            if (array_key_exists($id, $final)) {
                continue;
            }

            $final[$id] = ['id' => $id, 'obligatorio' => isset($opcionalesObligatorios[$id])];
        }

        return array_values($final);
    }

    /**
     * Reemplaza los documentos de la convocatoria. La lista recibida ya viene
     * fusionada (conDocumentosObligatorios): los obligatorios no se pueden quitar
     * y se guardan con obligatorio = true.
     */
    private function reemplazarDocumentos(Convocatoria $convocatoria, ?array $documentos): void
    {
        $sincronizar = [];
        foreach ($documentos ?? [] as $documento) {
            $sincronizar[(int) $documento['id']] = ['obligatorio' => (bool) ($documento['obligatorio'] ?? false)];
        }

        $convocatoria->documentos()->sync($sincronizar);
    }

    // ── Regla H (Paso 5): bloqueo de campos con postulaciones ──────────

    /**
     * Con CUALQUIER postulación (postulada o retirada) y la convocatoria
     * publicada, estos campos quedan bloqueados: cargo, area, vacantes,
     * salario_min, salario_max, tipo_convocatoria, tipo_empleo, tipo_contrato,
     * modalidad, fecha_apertura, skills y documentos. Siempre editables: nombre,
     * texts descriptivos y fecha_evaluacion. fecha_cierre: solo ampliar.
     *
     * Solo se bloquea si el valor enviado DIFIERE del guardado (el wizard
     * reenvía todo el formulario). Acumula un error por campo y lanza un único
     * 422, con todos los campos que cambiaron.
     */
    private function bloquearCamposConPostulaciones(Convocatoria $actual, array $normalizado): void
    {
        $campos = $normalizado['campos'];
        $errores = [];
        $bloqueado = 'No se puede modificar %s porque la convocatoria ya tiene postulaciones.';

        // Texto exacto (recortando espacios).
        foreach (['cargo', 'area'] as $campo) {
            if (array_key_exists($campo, $campos)
                && trim((string) $campos[$campo]) !== trim((string) $actual->{$campo})) {
                $errores[$campo][] = sprintf($bloqueado, $campo);
            }
        }

        // Vacantes.
        if (array_key_exists('vacantes', $campos)
            && $this->enteroDistinto($campos['vacantes'], $actual->vacantes)) {
            $errores['vacantes'][] = sprintf($bloqueado, 'vacantes');
        }

        // Salarios (decimal vs string).
        foreach (['salario_min', 'salario_max'] as $campo) {
            if (array_key_exists($campo, $campos)
                && $this->decimalDistinto($campos[$campo], $actual->{$campo})) {
                $errores[$campo][] = sprintf($bloqueado, $campo);
            }
        }

        // Clasificaciones: se compara el id ya resuelto (aceptan código o id) y el
        // error se reporta bajo la clave canónica, aunque el cliente use *_id.
        foreach (self::CLASIFICACIONES as $campo => $tema) {
            $clave = $campo.'_id';
            if (array_key_exists($clave, $campos)
                && (int) $campos[$clave] !== (int) $actual->{$clave}) {
                $errores[$campo][] = sprintf($bloqueado, $campo);
            }
        }

        // Fecha de apertura (solo día).
        if (array_key_exists('fecha_apertura', $campos)
            && $this->fechaDia($campos['fecha_apertura']) !== $actual->fecha_apertura?->toDateString()) {
            $errores['fecha_apertura'][] = sprintf($bloqueado, 'fecha_apertura');
        }

        // Fecha de cierre: solo se bloquea si la REDUCEN (igualar o ampliar se acepta).
        if (array_key_exists('fecha_cierre', $campos)) {
            $nueva = $this->fechaDia($campos['fecha_cierre']);
            $actualCierre = $actual->fecha_cierre?->toDateString();
            if ($nueva !== null && $actualCierre !== null && $nueva < $actualCierre) {
                $errores['fecha_cierre'][] = 'La fecha de cierre solo se puede ampliar.';
            }
        }

        // Skills: conjunto sin distinguir orden ni mayúsculas.
        if ($normalizado['skills'] !== null
            && $this->conjuntoSkills($normalizado['skills']) !== $this->conjuntoSkills($actual->skills()->pluck('nombre')->all())) {
            $errores['skills'][] = sprintf($bloqueado, 'skills');
        }

        // Documentos: conjunto de ids (la obligatoriedad no varía).
        if ($normalizado['documentos'] !== null && $this->documentosDistintos($actual, $normalizado['documentos'])) {
            $errores['documentos'][] = sprintf($bloqueado, 'documentos');
        }

        if ($errores !== []) {
            throw ValidationException::withMessages($errores);
        }
    }

    /** Entero nullable distinto (null vs null = igual). */
    private function enteroDistinto(mixed $nuevo, mixed $actual): bool
    {
        $a = $nuevo === null || $nuevo === '' ? null : (int) $nuevo;
        $b = $actual === null ? null : (int) $actual;

        return $a !== $b;
    }

    /** Decimal nullable distinto (acepta "3500000" o 3500000.0). */
    private function decimalDistinto(mixed $nuevo, mixed $actual): bool
    {
        $a = $nuevo === null || $nuevo === '' ? null : (float) $nuevo;
        $b = $actual === null ? null : (float) $actual;

        return $a !== $b;
    }

    /**
     * Fecha reducida a día. Un valor solo-fecha (Y-m-d) es ese día calendario;
     * una fecha-hora se lleva a America/Bogota. Esto evita el corrimiento de un
     * día que produce interpretar "2026-10-11" como medianoche UTC y convertir a
     * Bogotá (UTC-5), que daría 2026-10-10.
     */
    private function fechaDia(mixed $valor): ?string
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        $texto = trim((string) $valor);

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $texto) === 1) {
            return $texto;
        }

        return Carbon::parse($texto, 'America/Bogota')->toDateString();
    }

    /**
     * Conjunto de skills recortadas, en minúscula y ordenado: igualdad sin
     * distinguir orden ni mayúsculas.
     *
     * @return string[]
     */
    private function conjuntoSkills(array $skills): array
    {
        $conjunto = [];
        foreach ($skills as $skill) {
            $texto = mb_strtolower(trim((string) $skill));
            if ($texto !== '') {
                $conjunto[$texto] = true;
            }
        }

        $claves = array_keys($conjunto);
        sort($claves);

        return $claves;
    }

    /** ¿Difiere el conjunto de ids de documentos del guardado? */
    private function documentosDistintos(Convocatoria $actual, array $documentos): bool
    {
        $actuales = $actual->documentos()->get()
            ->map(fn ($parametro) => (int) $parametro->id)
            ->all();

        $nuevos = [];
        foreach ($documentos as $documento) {
            $nuevos[] = (int) $documento['id'];
        }

        sort($actuales);
        sort($nuevos);

        return $actuales !== $nuevos;
    }

    // ── Autorización ───────────────────────────────────────────────────

    /** Frontera de aislamiento: fuera del alcance (o estado no visible) -> 404. */
    private function autorizar(Convocatoria $convocatoria, Request $request): void
    {
        $user = $request->user();

        if (! in_array($convocatoria->ficha_id, $user->fichaIds(), true)) {
            abort(404, 'Convocatoria no encontrada.');
        }

        if (! $user->can('convocatorias:gestionar')
            && $convocatoria->estado?->codigo !== ConvocatoriaEstado::Publicada->value) {
            abort(404, 'Convocatoria no encontrada.');
        }
    }

    /** Id de un parámetro por tema+código, o 500 si falta el seeder. */
    private function parametroId(string $tema, string $codigo): int
    {
        $parametro = Parametro::porTemaYCodigo($tema, $codigo);

        abort_unless($parametro, 500, "Parámetro no encontrado: {$tema}.{$codigo}. ¿Se ejecutó ParametroSeeder?");

        return (int) $parametro->id;
    }

    private function relaciones(): array
    {
        return ['ficha', 'estado', 'tipoConvocatoria', 'tipoEmpleo', 'tipoContrato', 'modalidad', 'skills', 'documentos'];
    }

    // ── Campos de postulación (Paso 4) ─────────────────────────────────

    /**
     * Prepara la consulta del listado con los campos de postulación que le
     * corresponden al usuario, SIN N+1:
     *  - quien gestiona: `postulantes_count` como subconsulta (withCount).
     *  - aspirante: sus propias postulaciones de la página (1 consulta extra).
     *  - cualquier otro: no se añade nada.
     *
     * El id del estado se resuelve FUERA del closure: dentro de él, `$this` es
     * el Query Builder, no este controlador.
     */
    private function aplicarCamposDePostulacion(Builder $query, User $user): void
    {
        if ($user->can('convocatorias:gestionar')) {
            $estadoPostulada = $this->parametroId('estado_postulacion', PostulacionEstado::Postulada->value);

            $query->withCount([
                'postulaciones as postulantes_count' => fn ($sub) => $sub->where('estado_id', $estadoPostulada),
            ]);

            return;
        }

        if ($user->role === Role::Aspirante) {
            $query->with([
                'postulaciones' => fn ($sub) => $sub->where('user_id', $user->id)->with('estado'),
            ]);
        }
    }

    /** Igual que aplicarCamposDePostulacion, para un recurso ya cargado (+1 consulta). */
    private function cargarCamposDePostulacion(Convocatoria $convocatoria, User $user): void
    {
        if ($user->can('convocatorias:gestionar')) {
            $estadoPostulada = $this->parametroId('estado_postulacion', PostulacionEstado::Postulada->value);

            $convocatoria->loadCount([
                'postulaciones as postulantes_count' => fn ($sub) => $sub->where('estado_id', $estadoPostulada),
            ]);

            return;
        }

        if ($user->role === Role::Aspirante) {
            $convocatoria->load([
                'postulaciones' => fn ($sub) => $sub->where('user_id', $user->id)->with('estado'),
            ]);
        }
    }

    /**
     * Claves de postulación que le corresponden al usuario (o ninguna):
     *  - gestor -> ['postulantes' => int]
     *  - aspirante -> ['mi_postulacion' => null | ['id', 'estado' => ['codigo','nombre']]]
     *  - otro -> []
     * Nunca aparecen las dos.
     */
    private function camposDePostulacion(Convocatoria $c, User $user): array
    {
        if ($user->can('convocatorias:gestionar')) {
            return ['postulantes' => (int) ($c->postulantes_count ?? 0)];
        }

        if ($user->role === Role::Aspirante && $c->relationLoaded('postulaciones')) {
            $propia = $c->postulaciones->first();

            return [
                'mi_postulacion' => $propia === null ? null : [
                    'id' => $propia->id,
                    'estado' => [
                        'codigo' => $propia->estado?->codigo,
                        'nombre' => $propia->estado?->nombre,
                    ],
                ],
            ];
        }

        return [];
    }

    // ── Presentación ───────────────────────────────────────────────────

    private function presentarResumen(Convocatoria $c, User $user): array
    {
        return [
            'id' => $c->id,
            'codigo' => $c->codigo,
            'nombre' => $c->nombre,
            'cargo' => $c->cargo,
            'area' => $c->area,
            'estado' => $this->parametro($c->estado),
            'ficha' => $this->ficha($c),
            'tipo_convocatoria' => $this->parametro($c->tipoConvocatoria),
            'tipo_empleo' => $this->parametro($c->tipoEmpleo),
            'vacantes' => (int) $c->vacantes,
            'salario_min' => $this->numero($c->salario_min),
            'salario_max' => $this->numero($c->salario_max),
            'fecha_apertura' => $c->fecha_apertura?->toDateString(),
            'fecha_cierre' => $c->fecha_cierre?->toDateString(),
        ] + $this->camposDePostulacion($c, $user);
    }

    private function presentarDetalle(Convocatoria $c, User $user): array
    {
        return [
            'id' => $c->id,
            'codigo' => $c->codigo,
            'ficha' => $this->ficha($c),
            'nombre' => $c->nombre,
            'cargo' => $c->cargo,
            'area' => $c->area,
            'descripcion' => $c->descripcion,
            'estado' => $this->parametro($c->estado),
            'vacantes' => (int) $c->vacantes,
            'salario_min' => $this->numero($c->salario_min),
            'salario_max' => $this->numero($c->salario_max),
            'tipo_convocatoria' => $this->parametro($c->tipoConvocatoria),
            'tipo_empleo' => $this->parametro($c->tipoEmpleo),
            'tipo_contrato' => $this->parametro($c->tipoContrato),
            'modalidad' => $this->parametro($c->modalidad),
            'funciones' => $c->funciones,
            'responsabilidades' => $c->responsabilidades,
            'perfil_requerido' => $c->perfil_requerido,
            'formacion' => $c->formacion,
            'experiencia' => $c->experiencia,
            'competencias' => $c->competencias,
            'conocimientos_especificos' => $c->conocimientos_especificos,
            'fecha_apertura' => $c->fecha_apertura?->toDateString(),
            'fecha_cierre' => $c->fecha_cierre?->toDateString(),
            'fecha_evaluacion' => $c->fecha_evaluacion?->toDateString(),
            'skills' => $c->skills->pluck('nombre')->values(),
            'documentos' => $c->documentos->map(fn (Parametro $d) => [
                'id' => $d->id,
                'codigo' => $d->codigo,
                'nombre' => $d->nombre,
                // Obligatoriedad real guardada: true para los siempre obligatorios
                // (config/convocatorias.php) y para los opcionales que el gestor
                // incluyó como obligatorios.
                'obligatorio' => (bool) $d->pivot->obligatorio,
            ])->values(),
        ] + $this->camposDePostulacion($c, $user);
    }

    private function ficha(Convocatoria $c): ?array
    {
        return $c->ficha
            ? [
                'id' => $c->ficha->id,
                'codigo' => $c->ficha->codigo,
                'nombre_programa' => $c->ficha->nombre_programa,
            ]
            : null;
    }

    private function parametro(?Parametro $parametro): ?array
    {
        return $parametro
            ? ['id' => $parametro->id, 'codigo' => $parametro->codigo, 'nombre' => $parametro->nombre]
            : null;
    }

    private function numero(mixed $valor): ?float
    {
        return $valor === null ? null : round((float) $valor, 2);
    }

    // ── Mensajes en español (sin cambiar APP_LOCALE) ────────────────────

    /** @return array<string, string> */
    private function mensajes(): array
    {
        return [
            'required' => 'El campo :attribute es obligatorio.',
            'filled' => 'El campo :attribute no puede quedar vacío.',
            'exists' => 'El campo :attribute seleccionado no es válido.',
            'string' => 'El campo :attribute debe ser texto.',
            'integer' => 'El campo :attribute debe ser un número entero.',
            'numeric' => 'El campo :attribute debe ser un número.',
            'array' => 'El campo :attribute debe ser una lista.',
            'date' => 'El campo :attribute no es una fecha válida.',
            'min' => 'El campo :attribute debe ser al menos :min.',
            'max' => 'El campo :attribute no debe superar :max.',
            'gte' => 'El campo :attribute debe ser mayor o igual que :other.',
            'skills.max' => 'No puedes registrar más de :max skills.',
            'skills.*.string' => 'Cada skill debe ser texto.',
            'skills.*.max' => 'Cada skill no debe superar los :max caracteres.',
        ];
    }

    /** @return array<string, string> */
    private function atributos(): array
    {
        return [
            'nombre' => 'nombre',
            'cargo' => 'cargo',
            'area' => 'área',
            'descripcion' => 'descripción',
            'vacantes' => 'número de vacantes',
            'salario_min' => 'salario mínimo',
            'salario_max' => 'salario máximo',
            'fecha_apertura' => 'fecha de apertura',
            'fecha_cierre' => 'fecha de cierre',
            'fecha_evaluacion' => 'fecha de evaluación',
            'tipo_convocatoria' => 'tipo de convocatoria',
            'tipo_convocatoria_id' => 'tipo de convocatoria',
            'tipo_empleo' => 'tipo de empleo',
            'tipo_empleo_id' => 'tipo de empleo',
            'tipo_contrato' => 'tipo de contrato',
            'tipo_contrato_id' => 'tipo de contrato',
            'modalidad' => 'modalidad',
            'modalidad_id' => 'modalidad',
            'page' => 'página',
            'per_page' => 'elementos por página',
            'estado' => 'estado',
            'ficha_id' => 'ficha',
            'q' => 'búsqueda',
        ];
    }
}
