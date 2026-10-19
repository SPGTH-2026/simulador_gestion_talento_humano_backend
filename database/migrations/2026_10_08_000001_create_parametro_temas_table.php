<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Clasificación superior de parámetros: todo parámetro cuelga de un tema.
        // El codigo único evita que un mismo tema (p. ej. estado_convocatoria)
        // exista dos veces con nombres distintos.
        Schema::create('parametro_temas', function (Blueprint $table) {
            $table->id();
            $table->string('codigo')->unique();
            $table->string('nombre');
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parametro_temas');
    }
};