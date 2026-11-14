<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Configuración de las actividades presenciales de Selección (entrevista y
     * prueba técnica) por convocatoria. Una fila por convocatoria: la ausencia de
     * fila significa "pendiente de configurar"; una fila con ambas actividades en
     * false significa "sin actividades" elegido de forma explícita.
     * El peso de una actividad desactivada es null.
     */
    public function up(): void
    {
        Schema::create('seleccion_configuraciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('convocatoria_id')->unique()->constrained('convocatorias')->cascadeOnDelete();
            $table->boolean('entrevista_activa');
            $table->boolean('prueba_tecnica_activa');
            $table->unsignedTinyInteger('peso_entrevista')->nullable();
            $table->unsignedTinyInteger('peso_prueba_tecnica')->nullable();
            $table->foreignId('actualizado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seleccion_configuraciones');
    }
};
