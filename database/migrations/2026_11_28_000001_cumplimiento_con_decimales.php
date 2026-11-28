<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * El cumplimiento de la evaluación ya no se redondea a un entero: se guarda con dos decimales (96,67 %) para que el total sea
     * justo. Los valores que ya estaban guardados (enteros) se conservan tal cual.
     */
    public function up(): void
    {
        Schema::table('evaluaciones', function (Blueprint $table) {
            $table->decimal('cumplimiento', 5, 2)->nullable()->change();
        });

        // Foto del cumplimiento de la evaluación elegida como referencia documental: misma precisión.
        Schema::table('referencias_documentales', function (Blueprint $table) {
            $table->decimal('cumplimiento', 5, 2)->change();
        });
    }

    public function down(): void
    {
        Schema::table('evaluaciones', function (Blueprint $table) {
            $table->unsignedTinyInteger('cumplimiento')->nullable()->change();
        });

        Schema::table('referencias_documentales', function (Blueprint $table) {
            $table->unsignedSmallInteger('cumplimiento')->change();
        });
    }
};
