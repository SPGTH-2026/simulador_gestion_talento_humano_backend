<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Models\Convocatoria;
use App\Models\Ficha;
use App\Models\Parametro;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

trait PersisteConvocatoria
{
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
            // Primero el más largo: a partir de la secuencia 1000 el orden por
            // texto puso CONV-AAAA-999 por encima de CONV-AAAA-1000.
            ->orderByRaw('LENGTH(codigo) DESC')
            ->orderByDesc('codigo')
            ->value('codigo');

        $secuencia = 1;
        if ($ultimo) {
            $partes = explode('-', $ultimo);
            $secuencia = ((int) end($partes)) + 1;
        }

        return sprintf('CONV-%d-%03d', $anio, $secuencia);
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
}
