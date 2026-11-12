<?php

namespace App\Http\Controllers\Api;

use App\Enums\ConvocatoriaEstado;
use App\Http\Controllers\Api\Concerns\ResultadosDocumentales;
use App\Http\Controllers\Controller;
use App\Models\Convocatoria;
use App\Models\Parametro;
use App\Models\SeleccionCalificacion;
use App\Models\SeleccionConfiguracion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Selección, etapa 3: configuración de entrevista y prueba técnica por convocatoria.
 *
 * Los ids llegan como enteros (no como modelos enlazados) para que el 403 se
 * resuelva antes que el 404. El alcance por ficha usa convocatoriaEnAlcance().
 */
class ConfiguracionSeleccionController extends Controller
{
    use ResultadosDocumentales;

    public function show(Request $request, int $convocatoria): JsonResponse
    {
        $convocatoria = $this->convocatoriaEnAlcance($request->user(), $convocatoria);

        return response()->json([
            'data' => $this->presentar(
                $convocatoria,
                SeleccionConfiguracion::query()->with('actualizador:id,name')->where('convocatoria_id', $convocatoria->id)->first(),
            ),
        ]);
    }

    public function update(Request $request, int $convocatoria): JsonResponse
    {
        $this->convocatoriaEnAlcance($request->user(), $convocatoria);

        $datos = $request->validate([
            'entrevista_activa' => ['required', 'boolean'],
            'prueba_tecnica_activa' => ['required', 'boolean'],
            'peso_entrevista' => ['nullable', 'integer', 'between:1,100'],
            'peso_prueba_tecnica' => ['nullable', 'integer', 'between:1,100'],
        ], [
            'peso_entrevista.between' => 'El peso de la entrevista debe ser un porcentaje entero entre 1 y 100.',
            'peso_entrevista.integer' => 'El peso de la entrevista debe ser un porcentaje entero.',
            'peso_prueba_tecnica.between' => 'El peso de la prueba técnica debe ser un porcentaje entero entre 1 y 100.',
            'peso_prueba_tecnica.integer' => 'El peso de la prueba técnica debe ser un porcentaje entero.',
        ]);

        $entrevista = (bool) $datos['entrevista_activa'];
        $prueba = (bool) $datos['prueba_tecnica_activa'];
        [$pesoEntrevista, $pesoPrueba] = $this->resolverPesos(
            $entrevista,
            $prueba,
            isset($datos['peso_entrevista']) ? (int) $datos['peso_entrevista'] : null,
            isset($datos['peso_prueba_tecnica']) ? (int) $datos['peso_prueba_tecnica'] : null,
        );

        $actor = $request->user();

        $configuracion = DB::transaction(function () use ($convocatoria, $entrevista, $prueba, $pesoEntrevista, $pesoPrueba, $actor) {
            // Bloquea la convocatoria y vuelve a leer su estado: un cierre concurrente
            // espera a que esta transacción termine, y uno ya confirmado se ve aquí.
            $fila = Convocatoria::query()->whereKey($convocatoria)->lockForUpdate()->first();

            if (! $fila || ! $this->admiteConfiguracion($fila)) {
                throw ValidationException::withMessages([
                    'convocatoria' => 'La configuración solo se puede guardar con la convocatoria publicada o en proceso.',
                ]);
            }

            $existente = SeleccionConfiguracion::query()->where('convocatoria_id', $fila->id)->first();

            // Reenviar exactamente lo guardado no reescribe nada (ni siquiera con notas registradas).
            if ($existente !== null
                && $existente->entrevista_activa === $entrevista
                && $existente->prueba_tecnica_activa === $prueba
                && $existente->peso_entrevista === $pesoEntrevista
                && $existente->peso_prueba_tecnica === $pesoPrueba) {
                return $existente;
            }

            // Con la primera nota de cualquier candidato, la configuración queda congelada.
            if (SeleccionCalificacion::query()->where('convocatoria_id', $fila->id)->exists()) {
                throw ValidationException::withMessages([
                    'convocatoria' => 'La configuración ya no se puede cambiar: la convocatoria tiene calificaciones registradas.',
                ]);
            }

            return SeleccionConfiguracion::query()->updateOrCreate(
                ['convocatoria_id' => $fila->id],
                [
                    'entrevista_activa' => $entrevista,
                    'prueba_tecnica_activa' => $prueba,
                    'peso_entrevista' => $pesoEntrevista,
                    'peso_prueba_tecnica' => $pesoPrueba,
                    'actualizado_por' => $actor->id,
                ],
            );
        });

        $configuracion->load('actualizador:id,name');

        return response()->json([
            'data' => $this->presentar(Convocatoria::query()->findOrFail($convocatoria), $configuracion),
        ]);
    }

    /**
     * Pesos finales según las activas. Con una sola activa vale 100; con ambas,
     * 50/50 si no se envía ninguno, o dos enteros positivos que sumen 100.
     *
     * @return array{0: ?int, 1: ?int}
     */
    private function resolverPesos(bool $entrevista, bool $prueba, ?int $pesoEntrevista, ?int $pesoPrueba): array
    {
        $errores = [];

        if (! $entrevista && $pesoEntrevista !== null) {
            $errores['peso_entrevista'] = 'No se puede asignar peso a la entrevista porque está desactivada.';
        }
        if (! $prueba && $pesoPrueba !== null) {
            $errores['peso_prueba_tecnica'] = 'No se puede asignar peso a la prueba técnica porque está desactivada.';
        }

        if ($entrevista xor $prueba) {
            $enviado = $entrevista ? $pesoEntrevista : $pesoPrueba;
            if ($enviado !== null && $enviado !== 100) {
                $errores[$entrevista ? 'peso_entrevista' : 'peso_prueba_tecnica'] = 'Con una sola actividad activa el peso debe ser 100.';
            }
        }

        if ($entrevista && $prueba) {
            if (($pesoEntrevista === null) !== ($pesoPrueba === null)) {
                $errores['peso_entrevista'] = 'Con ambas actividades activas debes enviar los dos pesos o ninguno (se asigna 50 y 50).';
            } elseif ($pesoEntrevista !== null && $pesoEntrevista + $pesoPrueba !== 100) {
                $errores['peso_entrevista'] = 'Los pesos de la entrevista y de la prueba técnica deben sumar 100.';
            }
        }

        if ($errores !== []) {
            throw ValidationException::withMessages($errores);
        }

        if ($entrevista && $prueba) {
            return [$pesoEntrevista ?? 50, $pesoPrueba ?? 50];
        }

        return [$entrevista ? 100 : null, $prueba ? 100 : null];
    }

    private function admiteConfiguracion(Convocatoria $convocatoria): bool
    {
        $codigos = [ConvocatoriaEstado::Publicada->value, ConvocatoriaEstado::EnProceso->value];

        return collect($codigos)->contains(
            fn (string $codigo) => (int) $convocatoria->estado_id === (int) Parametro::porTemaYCodigo('estado_convocatoria', $codigo)->id
        );
    }

    private function presentar(Convocatoria $convocatoria, ?SeleccionConfiguracion $configuracion): array
    {
        $entrevista = $configuracion?->entrevista_activa ?? false;
        $prueba = $configuracion?->prueba_tecnica_activa ?? false;
        // Se calcula una sola vez: true con la primera nota de cualquier candidato.
        $bloqueada = SeleccionCalificacion::query()->where('convocatoria_id', $convocatoria->id)->exists();

        return [
            'convocatoria_id' => $convocatoria->id,
            'estado_convocatoria' => $convocatoria->estado()->value('codigo'),
            // Editable = el estado lo admite y no hay notas; `bloqueada` explica por qué no lo es.
            'editable' => $this->admiteConfiguracion($convocatoria) && ! $bloqueada,
            'configurada' => $configuracion !== null,
            'bloqueada' => $bloqueada,
            // null = pendiente de configurar; sin_actividades = elegido de forma explícita.
            'opcion' => $configuracion === null ? null : match (true) {
                $entrevista && $prueba => 'ambas',
                $entrevista => 'solo_entrevista',
                $prueba => 'solo_prueba_tecnica',
                default => 'sin_actividades',
            },
            'entrevista' => ['activa' => $entrevista, 'peso' => $configuracion?->peso_entrevista],
            'prueba_tecnica' => ['activa' => $prueba, 'peso' => $configuracion?->peso_prueba_tecnica],
            'actualizada_por' => $configuracion?->actualizador?->name,
            'actualizada_en' => $configuracion?->updated_at?->toIso8601String(),
        ];
    }
}
