<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Porcentaje de cada habilidad de la convocatoria. Junto con el porcentaje de los documentos suma 100 %.
     * Null = la habilidad no tiene porcentaje (convocatoria sin ponderar).
     */
    public function up(): void
    {
        Schema::table('convocatoria_skills', function (Blueprint $table) {
            $table->decimal('ponderacion', 5, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('convocatoria_skills', function (Blueprint $table) {
            $table->dropColumn('ponderacion');
        });
    }
};
