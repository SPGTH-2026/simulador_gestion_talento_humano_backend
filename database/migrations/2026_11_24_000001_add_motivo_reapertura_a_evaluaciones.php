<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Motivo de reapertura de una evaluación finalizada.
     *
     * Cuando un cambio en un documento requerido devuelve una evaluación finalizada a «en proceso», se guarda aquí qué
     * documento cambió y cuándo, para que el evaluador lo vea. Se limpia cuando el evaluador vuelve a guardar la
     * evaluación. Las evaluaciones existentes quedan sin motivo.
     */
    public function up(): void
    {
        Schema::table('evaluaciones', function (Blueprint $table) {
            $table->string('motivo_reapertura', 255)->nullable();
            $table->timestamp('reabierta_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('evaluaciones', function (Blueprint $table) {
            $table->dropColumn(['motivo_reapertura', 'reabierta_at']);
        });
    }
};
