<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Skills de una convocatoria: TEXTO LIBRE por convocatoria (no parámetros).
        // El controlador las trimea, las deduplica sin distinguir mayúsculas y las
        // limita (máx. config('convocatorias.skills_max') y 100 caracteres cada una).
        Schema::create('convocatoria_skills', function (Blueprint $table) {
            $table->id();
            // Las skills mueren con la convocatoria.
            $table->foreignId('convocatoria_id')->constrained('convocatorias')->cascadeOnDelete();
            $table->string('nombre', 100);
            $table->timestamps();

            // Una misma skill no puede repetirse (case-insensitive: la dedupe la hace
            // la capa de aplicación normalizando a minúsculas antes de guardar).
            $table->unique(['convocatoria_id', 'nombre']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('convocatoria_skills');
    }
};