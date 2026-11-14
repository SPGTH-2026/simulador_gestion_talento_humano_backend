<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Designaciones de la evaluación documental de referencia (Selección).
     *
     * Cada fila es un EVENTO: rectificar agrega una fila, nunca edita ni borra.
     * La vigente es la de mayor id de la postulación. Se guarda una foto de los
     * valores de la evaluación (cumplimiento, resultado y su updated_at) para
     * detectar después si la evaluación se reabrió, se recalculó o se editó.
     * Si la evaluación o los usuarios se eliminan, el historial permanece.
     */
    public function up(): void
    {
        Schema::create('referencias_documentales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('postulacion_id')->constrained('postulaciones')->cascadeOnDelete();
            $table->foreignId('evaluacion_id')->nullable()->constrained('evaluaciones')->nullOnDelete();
            $table->foreignId('evaluador_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('designado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->string('justificacion', 500);
            // Foto de la evaluación elegida en el momento de la designación.
            $table->unsignedSmallInteger('cumplimiento');
            $table->boolean('pasa_seleccion');
            $table->timestamp('evaluacion_actualizada_en');
            $table->timestamps();

            $table->index(['postulacion_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referencias_documentales');
    }
};
