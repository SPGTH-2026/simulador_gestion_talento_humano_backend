<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Datos personales con los que el aspirante se postuló. Viven en la postulación (no en el perfil): cada convocatoria
     * empieza en blanco y lo que ve el evaluador es exactamente lo que se declaró en ella. Si la postulación se borra,
     * estos datos se van con ella.
     */
    public function up(): void
    {
        Schema::create('postulacion_datos_personales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('postulacion_id')->unique()->constrained('postulaciones')->cascadeOnDelete();

            $table->foreignId('tipo_documento_identidad_id')->constrained('parametros')->restrictOnDelete();
            $table->string('numero_documento', 20);
            $table->string('nombres', 100);
            $table->string('apellidos', 100);
            $table->date('fecha_nacimiento');

            $table->string('telefono', 20);
            $table->string('departamento', 80);
            $table->string('municipio', 80);
            $table->string('direccion', 150)->nullable();

            $table->foreignId('nivel_formacion_id')->constrained('parametros')->restrictOnDelete();

            // Solo para menores de edad (16 y 17 años): quién autoriza la postulación.
            $table->string('representante_nombre', 150)->nullable();
            $table->string('representante_documento', 20)->nullable();
            $table->string('representante_telefono', 20)->nullable();
            $table->string('representante_parentesco', 40)->nullable();

            // Autorización de tratamiento de datos personales (Ley 1581 de 2012) y cuándo se aceptó.
            $table->timestamp('acepta_tratamiento_datos_at');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('postulacion_datos_personales');
    }
};
