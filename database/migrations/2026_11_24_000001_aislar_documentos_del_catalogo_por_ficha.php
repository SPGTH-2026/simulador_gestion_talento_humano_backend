<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Aislamiento del catálogo de documentos por ficha.
     *
     * Un documento nuevo que crea un gestor ya no queda visible para todas las fichas: se liga a las fichas de quien lo
     * crea (parametro_fichas) y a su creador (creado_por). Los parámetros existentes no tienen ninguna de las dos cosas y
     * siguen siendo globales, así que nada de lo ya creado cambia de visibilidad.
     */
    public function up(): void
    {
        Schema::table('parametros', function (Blueprint $table) {
            $table->foreignId('creado_por')->nullable()->after('es_defecto')->constrained('users')->nullOnDelete();
        });

        Schema::create('parametro_fichas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parametro_id')->constrained('parametros')->cascadeOnDelete();
            $table->foreignId('ficha_id')->constrained('fichas')->cascadeOnDelete();
            $table->unique(['parametro_id', 'ficha_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parametro_fichas');

        Schema::table('parametros', function (Blueprint $table) {
            $table->dropConstrainedForeignId('creado_por');
        });
    }
};
