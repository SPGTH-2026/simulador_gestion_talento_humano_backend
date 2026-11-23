<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Models\Convocatoria;
use App\Models\Parametro;

trait SkillsDeConvocatoria
{
    /**
     * Normaliza las skills de la convocatoria y deja los errores en $errores.
     *
     * Acepta DOS formatos por ítem (compatibilidad con lo que ya enviaba el
     * frontend y con las skills guardadas):
     *  - texto libre: 'Excel'
     *  - del catálogo: ['nombre' => 'Excel', 'parametro_id' => 44]
     *
     * Reglas (todas con 422 y mensaje en español):
     *  - el tope de cantidad lo aplica la regla 'skills' => max (ver validar()).
     *  - cada nombre, como máximo config('convocatorias.skill_max_longitud').
     *  - sin duplicados, ignorando mayúsculas y espacios sobrantes.
     *  - si viene parametro_id, debe existir y ser del tema skill_catalogo; el
     *    nombre se toma SIEMPRE del catálogo (el cliente no decide cómo se
     *    llama). Sin parametro_id, la skill es texto libre.
     *
     * Los errores de conjunto van bajo la clave 'skills' y los de un ítem
     * bajo 'skills.{indice}', que es la forma que el frontend ya sabe leer.
     *
     * @return array<int, array{nombre: string, parametro_id: int|null}>
     */
    private function normalizarSkills(mixed $skills, array &$errores): array
    {
        if (! is_array($skills)) {
            return [];
        }

        $maxSkill = (int) config('convocatorias.skill_max_longitud');

        $normalizadas = [];
        $vistas = [];

        foreach (array_values($skills) as $indice => $item) {
            $parametroId = null;
            $nombre = null;

            if ($item === null) {
                // Cadena vacía (Laravel la convierte a null): se descarta.
                continue;
            }

            if (is_array($item)) {
                $parametroId = $item['parametro_id'] ?? null;
                $nombre = $item['nombre'] ?? null;
            } elseif (is_string($item)) {
                $nombre = $item;
            } else {
                $errores["skills.{$indice}"][] = 'Cada skill debe ser texto.';
                continue;
            }

            if ($parametroId !== null && $parametroId !== '') {
                if (! is_numeric($parametroId)) {
                    $errores["skills.{$indice}"][] = 'La skill seleccionada no es válida.';
                    continue;
                }

                $parametro = $this->parametroSkillCatalogo((int) $parametroId);

                if ($parametro === null) {
                    $errores["skills.{$indice}"][] = 'La skill seleccionada no es válida.';
                    continue;
                }

                // El nombre lo pone el catálogo, no el cliente.
                $nombre = $parametro->nombre;
                $parametroId = (int) $parametro->id;
            } else {
                $parametroId = null;
                $nombre = is_string($nombre) ? $nombre : '';
            }

            $nombre = $this->recortarTexto($nombre);

            if ($nombre === '') {
                // Vacía: se descarta (no ocupa lugar ni es un error).
                continue;
            }

            if (mb_strlen($nombre) > $maxSkill) {
                $errores["skills.{$indice}"][] = "Cada skill no debe superar los {$maxSkill} caracteres.";
                continue;
            }

            $clave = mb_strtolower($nombre);

            if (isset($vistas[$clave])) {
                $errores['skills'][] = 'No puedes repetir la misma skill.';
                continue;
            }

            $vistas[$clave] = true;
            $normalizadas[] = ['nombre' => $nombre, 'parametro_id' => $parametroId];
        }

        return $normalizadas;
    }

    /**
     * Parámetro del catálogo de skills (tema skill_catalogo) y activo. El tema
     * se resuelve por CODIGO, nunca por id ni por nombre.
     */
    private function parametroSkillCatalogo(int $id): ?Parametro
    {
        // Memoizado por petición (como Parametro::porTemaYCodigo): una consulta por skill distinta, no una por cada ítem.
        $memo = app('parametros.memo');
        $clave = 'skill_catalogo.'.$id;

        if (! $memo->offsetExists($clave)) {
            $memo[$clave] = Parametro::query()
                ->whereKey($id)
                ->whereHas('tema', fn ($q) => $q->where('codigo', 'skill_catalogo'))
                ->where('activo', true)
                ->first();
        }

        return $memo[$clave];
    }

    /**
     * Reemplaza el conjunto de skills de la convocatoria. Cada skill guarda su
     * nombre (siempre) y, si vino del catálogo, el parametro_id (FK SET NULL:
     * si la skill se retira del catálogo, la fila sobrevive con su nombre).
     */
    private function reemplazarSkills(Convocatoria $convocatoria, array $skills): void
    {
        $convocatoria->skills()->delete();

        foreach ($skills as $skill) {
            $convocatoria->skills()->create([
                'nombre' => $skill['nombre'],
                'parametro_id' => $skill['parametro_id'] ?? null,
            ]);
        }
    }

    /**
     * Conjunto de skills recortadas, en minúscula y ordenado: igualdad sin
     * distinguir orden ni mayúsculas. Acepta las dos formas que llegan aquí:
     * los nombres guardados (strings) y las ya normalizadas
     * (['nombre' => ..., 'parametro_id' => ...]).
     *
     * @return string[]
     */
    private function conjuntoSkills(array $skills): array
    {
        $conjunto = [];
        foreach ($skills as $skill) {
            $texto = is_array($skill)
                ? (string) ($skill['nombre'] ?? '')
                : (string) $skill;

            $texto = $this->recortarTexto($texto);

            if ($texto !== '') {
                $conjunto[mb_strtolower($texto)] = true;
            }
        }

        $claves = array_keys($conjunto);
        sort($claves);

        return $claves;
    }

    /**
     * Recorta el texto y deja UN solo espacio entre palabras: así '  Excel  ' y
     * 'Excel avanzado' se comparan siempre igual (sin distinguir mayúsculas ni
     * espacios sobrantes).
     */
    private function recortarTexto(string $texto): string
    {
        $recortado = preg_replace('/\s+/u', ' ', trim($texto));

        return is_string($recortado) ? $recortado : trim($texto);
    }
}
