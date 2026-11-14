<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ranking y finalistas de Selección.
     *
     * - seleccion_ranking_parametros: objetivo de finalistas por convocatoria
     *   (null = sugerencia por defecto de 2 x vacantes).
     * - seleccion_finalistas_listas: cada confirmación es una VERSIÓN de la lista de
     *   finalistas; confirmar de nuevo agrega una versión y nunca edita las anteriores.
     * - seleccion_finalistas_miembros: candidatos de cada versión, con la posición, el
     *   resultado y una huella del estado en que estaban al confirmar. La huella permite
     *   detectar después que una nota o la habilitación documental cambió.
     */
    public function up(): void
    {
        Schema::create('seleccion_ranking_parametros', function (Blueprint $table) {
            $table->id();
            $table->foreignId('convocatoria_id')->unique()->constrained('convocatorias')->cascadeOnDelete();
            $table->unsignedSmallInteger('objetivo_finalistas')->nullable();
            $table->foreignId('actualizado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('seleccion_finalistas_listas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('convocatoria_id')->constrained('convocatorias')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('modo', 30); // con_actividades | sin_actividades
            $table->unsignedSmallInteger('objetivo');
            $table->string('motivo', 500)->nullable();
            $table->foreignId('confirmado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['convocatoria_id', 'version']);
        });

        Schema::create('seleccion_finalistas_miembros', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lista_id')->constrained('seleccion_finalistas_listas')->cascadeOnDelete();
            $table->foreignId('postulacion_id')->constrained('postulaciones')->cascadeOnDelete();
            $table->unsignedSmallInteger('posicion')->nullable();
            $table->decimal('resultado', 4, 2)->nullable();
            $table->string('huella', 40);
            $table->timestamps();

            $table->unique(['lista_id', 'postulacion_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seleccion_finalistas_miembros');
        Schema::dropIfExists('seleccion_finalistas_listas');
        Schema::dropIfExists('seleccion_ranking_parametros');
    }
};
