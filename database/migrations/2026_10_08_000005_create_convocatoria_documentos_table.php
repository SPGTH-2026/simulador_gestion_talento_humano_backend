<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Documentos (parámetros del tema tipo_documento) que exige una
        // convocatoria. Se llenan automáticamente con "Hoja de vida" y
        // "Certificados laborales" (obligatorios), resueltos por código.
        Schema::create('convocatoria_documentos', function (Blueprint $table) {
            $table->id();
            // Los documentos mueren con la convocatoria.
            $table->foreignId('convocatoria_id')->constrained('convocatorias')->cascadeOnDelete();
            // No se borra un parámetro en uso.
            $table->foreignId('parametro_id')->constrained('parametros')->restrictOnDelete();
            $table->boolean('obligatorio')->default(true);
            $table->timestamps();

            // Un mismo documento no puede repetirse en la misma convocatoria.
            $table->unique(['convocatoria_id', 'parametro_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('convocatoria_documentos');
    }
};