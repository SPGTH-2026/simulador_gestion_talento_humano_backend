<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Calificaciones presenciales de Selección (entrevista y prueba técnica).
     *
     * seleccion_calificaciones guarda la nota VIGENTE: una por postulación y
     * actividad (restricción única). Cada corrección agrega una fila en
     * seleccion_calificacion_correcciones con el valor anterior y el nuevo, el
     * motivo, quién y cuándo, de modo que el historial nunca se pierde.
     * convocatoria_id se copia de la postulación para saber, con una consulta
     * simple, si una convocatoria ya tiene notas (bloquea su configuración).
     */
    public function up(): void
    {
        Schema::create('seleccion_calificaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('convocatoria_id')->constrained('convocatorias')->cascadeOnDelete();
            $table->foreignId('postulacion_id')->constrained('postulaciones')->cascadeOnDelete();
            $table->string('actividad', 30); // entrevista | prueba_tecnica
            $table->decimal('nota', 4, 2);
            $table->date('fecha_realizacion');
            $table->string('observacion', 500)->nullable();
            $table->foreignId('registrado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['postulacion_id', 'actividad']);
            $table->index('convocatoria_id');
        });

        Schema::create('seleccion_calificacion_correcciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('calificacion_id')->constrained('seleccion_calificaciones')->cascadeOnDelete();
            $table->decimal('nota_anterior', 4, 2);
            $table->decimal('nota_nueva', 4, 2);
            $table->date('fecha_anterior');
            $table->date('fecha_nueva');
            $table->string('observacion_anterior', 500)->nullable();
            $table->string('observacion_nueva', 500)->nullable();
            $table->string('motivo', 500);
            $table->foreignId('corregido_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['calificacion_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seleccion_calificacion_correcciones');
        Schema::dropIfExists('seleccion_calificaciones');
    }
};
