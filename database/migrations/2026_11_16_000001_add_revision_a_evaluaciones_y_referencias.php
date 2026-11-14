<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Revisión de la evaluación documental.
     *
     * `evaluaciones.revision` sube en 1 con cada cambio REAL de la evaluación y con cada invalidación por un cambio
     * documental; un reenvío idéntico la conserva. `referencias_documentales.evaluacion_revision` guarda la revisión de
     * la evaluación elegida al designarla: la referencia es vigente solo mientras la revisión siga siendo esa. Así la
     * vigencia no depende del reloj (dos cambios en el mismo segundo no se confunden).
     *
     * Referencias existentes (sin respaldo por fecha después de migrar): se les fija la revisión según su vigencia de
     * hoy. Las vigentes quedan con la revisión actual de su evaluación (siguen vigentes hasta el primer cambio real) y
     * las que ya eran inválidas quedan en 0, que ninguna evaluación tiene (revisión mínima 1): siguen inválidas hasta
     * que se reconfirmen.
     */
    public function up(): void
    {
        Schema::table('evaluaciones', function (Blueprint $table) {
            $table->unsignedInteger('revision')->default(1);
        });

        Schema::table('referencias_documentales', function (Blueprint $table) {
            // 0 = nunca vigente. Toda referencia nueva guarda la revisión de su evaluación.
            $table->unsignedInteger('evaluacion_revision')->default(0);
        });

        $this->rellenarRevisionDeReferencias();
    }

    /**
     * Misma regla de vigencia que aplicaba antes (fecha de actualización, cumplimiento y resultado), una sola vez y para
     * todas las referencias. Pública para poder probarla.
     */
    public function rellenarRevisionDeReferencias(): void
    {
        $segundo = fn ($valor) => $valor === null ? null : Carbon::parse($valor)->getTimestamp();
        // Un cumplimiento nulo NO es 0: la regla anterior comparaba con === y casts de entero que respetan el nulo
        // (null === 0 es falso), así que una evaluación sin cumplimiento nunca coincidía con una referencia en 0.
        $entero = fn ($valor) => $valor === null ? null : (int) $valor;

        DB::table('referencias_documentales')->orderBy('id')->chunkById(500, function ($referencias) use ($segundo, $entero) {
            $evaluaciones = DB::table('evaluaciones')
                ->whereIn('id', $referencias->pluck('evaluacion_id')->filter()->all())
                ->get(['id', 'estado', 'cumplimiento', 'pasa_seleccion', 'updated_at', 'revision'])
                ->keyBy('id');

            foreach ($referencias as $referencia) {
                $e = $evaluaciones->get($referencia->evaluacion_id);
                $vigente = $e !== null
                    && $e->estado === 'finalizada'
                    && $e->pasa_seleccion !== null
                    && $entero($e->cumplimiento) === $entero($referencia->cumplimiento)
                    && (bool) $e->pasa_seleccion === (bool) $referencia->pasa_seleccion
                    && $segundo($e->updated_at) === $segundo($referencia->evaluacion_actualizada_en);

                DB::table('referencias_documentales')
                    ->where('id', $referencia->id)
                    ->update(['evaluacion_revision' => $vigente ? (int) $e->revision : 0]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('referencias_documentales', function (Blueprint $table) {
            $table->dropColumn('evaluacion_revision');
        });

        Schema::table('evaluaciones', function (Blueprint $table) {
            $table->dropColumn('revision');
        });
    }
};
