<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ítems del checklist de cada documento en esta convocatoria: un ítem por línea. Null = el documento no tiene checklist.
     */
    public function up(): void
    {
        Schema::table('convocatoria_documentos', function (Blueprint $table) {
            $table->text('items')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('convocatoria_documentos', function (Blueprint $table) {
            $table->dropColumn('items');
        });
    }
};
