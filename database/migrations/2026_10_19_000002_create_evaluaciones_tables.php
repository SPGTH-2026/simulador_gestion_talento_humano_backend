<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Una evaluación es de un evaluador sobre una postulación. El índice
        // único permite que varios evaluadores califiquen la misma postulación
        // sin pisarse, y evita que uno la evalúe dos veces.
        Schema::create('evaluaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('postulacion_id')->constrained('postulaciones')->cascadeOnDelete();
            $table->foreignId('evaluador_id')->constrained('users')->cascadeOnDelete();
            $table->decimal('puntaje_total', 5, 2)->nullable();
            $table->string('estado')->default('en_proceso'); // en_proceso | finalizada
            $table->text('observaciones')->nullable();
            $table->timestamps();
            $table->unique(['postulacion_id', 'evaluador_id']);
        });

        // Detalle por criterio: el puntaje_total es el promedio ponderado de
        // estas filas (suma puntaje*ponderacion / suma ponderacion).
        Schema::create('evaluacion_detalles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('evaluacion_id')->constrained('evaluaciones')->cascadeOnDelete();
            $table->string('criterio');
            $table->decimal('puntaje', 5, 2);
            $table->decimal('ponderacion', 5, 2)->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evaluacion_detalles');
        Schema::dropIfExists('evaluaciones');
    }
};
