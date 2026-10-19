<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Skills que el aspirante declara en su postulación: las que pide la
        // convocatoria ("la tengo") y las propias (texto libre). El certificado
        // es OPCIONAL por skill (declarada vs certificada).
        Schema::create('postulacion_skills', function (Blueprint $table) {
            $table->id();

            // Las skills declaradas mueren con la postulación.
            $table->foreignId('postulacion_id')->constrained('postulaciones')->cascadeOnDelete();

            // Skill de la convocatoria; NULL = skill propia (texto libre). SET NULL
            // (no CASCADE): si la convocatoria borra su skill, la fila de la
            // postulación se CONSERVA como propia (el nombre ya quedó copiado).
            $table->foreignId('convocatoria_skill_id')->nullable()
                ->constrained('convocatoria_skills')->nullOnDelete();

            // Nombre visible: copia de la skill de la convocatoria o el texto propio.
            $table->string('nombre', 100);

            // Certificado OPCIONAL (mismo tratamiento de archivo que los documentos).
            $table->string('certificado_nombre_original')->nullable();
            $table->string('certificado_ruta')->nullable();
            $table->string('certificado_disco', 30)->nullable();
            $table->string('certificado_mime', 100)->nullable();
            $table->unsignedBigInteger('certificado_tamano_bytes')->nullable();

            $table->timestamps();

            // Una misma skill no puede repetirse en la postulación. La colación
            // utf8mb4_unicode_ci ya es case-insensitive; el controlador además
            // normaliza antes de guardar (misma regla que convocatoria_skills).
            $table->unique(['postulacion_id', 'nombre']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('postulacion_skills');
    }
};
