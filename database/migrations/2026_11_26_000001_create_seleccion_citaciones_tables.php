<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Citaciones de Selección (entrevista o prueba técnica) por postulación.
     *
     * Cada fila es UNA cita. Reprogramarla cambia la fila y deja un evento; cancelarla la marca cancelada (con su motivo) y programar de
     * nuevo crea otra fila, así que lo anterior nunca se pierde. La «cita vigente» por postulación y actividad se garantiza con la
     * restricción única sobre `vigente`, que vale true mientras está programada y NULL al cancelarse (los NULL no chocan entre sí).
     * `fecha_hora` se guarda en UTC, igual que el resto del sistema; la hora de Colombia se calcula al presentarla.
     */
    public function up(): void
    {
        Schema::create('seleccion_citaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('convocatoria_id')->constrained('convocatorias')->cascadeOnDelete();
            $table->foreignId('postulacion_id')->constrained('postulaciones')->cascadeOnDelete();
            $table->string('actividad', 30); // entrevista | prueba_tecnica
            $table->timestamp('fecha_hora');
            $table->string('lugar_o_enlace', 255);
            $table->string('indicaciones', 500)->nullable();
            $table->string('estado', 20)->default('programada'); // programada | cancelada
            $table->boolean('vigente')->nullable()->default(true);
            $table->string('motivo_cancelacion', 500)->nullable();
            $table->foreignId('creada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['postulacion_id', 'actividad', 'vigente'], 'citacion_vigente_unica');
            $table->index('convocatoria_id');
        });

        Schema::create('seleccion_citacion_eventos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('citacion_id')->constrained('seleccion_citaciones')->cascadeOnDelete();
            $table->string('tipo', 20); // programada | reprogramada | cancelada
            $table->foreignId('usuario_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('detalle')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('citacion_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seleccion_citacion_eventos');
        Schema::dropIfExists('seleccion_citaciones');
    }
};
