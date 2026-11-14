<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Decisiones internas de Selección (seleccionado / no_seleccionado).
     *
     * seleccion_decisiones guarda la decisión VIGENTE: una por postulación (restricción
     * única). Cada registro o rectificación agrega un evento en seleccion_decision_eventos
     * con el resultado anterior y el nuevo, el motivo, quién y cuándo, y la versión de la
     * lista de finalistas que sustentó el cambio. Los cupos ocupados se calculan contando
     * las decisiones vigentes `seleccionado`; Convocatoria::vacantes no se modifica.
     */
    public function up(): void
    {
        Schema::create('seleccion_decisiones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('convocatoria_id')->constrained('convocatorias')->cascadeOnDelete();
            $table->foreignId('postulacion_id')->unique()->constrained('postulaciones')->cascadeOnDelete();
            $table->string('resultado', 20); // seleccionado | no_seleccionado
            $table->string('motivo', 500);
            $table->unsignedInteger('lista_version')->nullable();
            $table->foreignId('decidido_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['convocatoria_id', 'resultado']);
        });

        Schema::create('seleccion_decision_eventos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('decision_id')->constrained('seleccion_decisiones')->cascadeOnDelete();
            $table->string('resultado_anterior', 20)->nullable(); // null = primera decisión
            $table->string('resultado_nuevo', 20);
            $table->string('motivo', 500);
            $table->unsignedInteger('lista_version')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['decision_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seleccion_decision_eventos');
        Schema::dropIfExists('seleccion_decisiones');
    }
};
