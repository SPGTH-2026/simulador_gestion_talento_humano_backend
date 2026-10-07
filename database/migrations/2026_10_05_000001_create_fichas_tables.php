<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Una ficha es el grupo de formación SENA. Las convocatorias y los
        // procesos de selección pertenecen a una ficha y no se mezclan.
        Schema::create('fichas', function (Blueprint $table) {
            $table->id();
            $table->string('codigo')->unique();
            $table->string('nombre_programa');
            $table->string('estado')->default('activa'); // activa | cerrada
            $table->timestamps();
        });

        // Un instructor acompaña varias fichas y una ficha puede tener varios
        // instructores (N:M). El filtro por ficha usa este pivot.
        Schema::create('ficha_instructor', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ficha_id')->constrained()->cascadeOnDelete();
            $table->foreignId('usuario_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['ficha_id', 'usuario_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ficha_instructor');
        Schema::dropIfExists('fichas');
    }
};