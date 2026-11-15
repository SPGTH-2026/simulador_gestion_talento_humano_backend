<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Momento en que el aspirante envía la corrección de los documentos marcados
     * con "Corregir". Se limpia cuando el evaluador vuelve a calificar.
     */
    public function up(): void
    {
        Schema::table('evaluaciones', function (Blueprint $table) {
            $table->timestamp('correccion_enviada_at')->nullable()->after('pasa_seleccion');
        });
    }

    public function down(): void
    {
        Schema::table('evaluaciones', function (Blueprint $table) {
            $table->dropColumn('correccion_enviada_at');
        });
    }
};
