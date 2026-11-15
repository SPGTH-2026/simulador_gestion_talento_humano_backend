<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Plazo para que el aspirante corrija los documentos marcados. Se fija cuando el
     * evaluador pide la corrección (dos días) y se limpia cuando la evaluación sale
     * de "correccion_solicitada".
     */
    public function up(): void
    {
        Schema::table('evaluaciones', function (Blueprint $table) {
            $table->timestamp('fecha_limite_correccion')->nullable()->after('correccion_enviada_at');
        });
    }

    public function down(): void
    {
        Schema::table('evaluaciones', function (Blueprint $table) {
            $table->dropColumn('fecha_limite_correccion');
        });
    }
};
