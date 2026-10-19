<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Documentos que el aspirante adjunta a una postulación. Cubre tanto los
        // documentos REQUERIDOS por la convocatoria (uno por tipo) como los
        // ADICIONALES ("tipo otro", parametro_id NULL + título obligatorio).
        Schema::create('postulacion_documentos', function (Blueprint $table) {
            $table->id();

            // Los documentos mueren con la postulación.
            $table->foreignId('postulacion_id')->constrained('postulaciones')->cascadeOnDelete();

            // Tipo de documento (tema tipo_documento). NULL = adicional. RESTRICT:
            // un parámetro en uso no se borra.
            $table->foreignId('parametro_id')->nullable()->constrained('parametros')->restrictOnDelete();

            // Solo para adicionales: título obligatorio y nota opcional.
            $table->string('titulo', 120)->nullable();
            $table->text('nota')->nullable();

            // Datos del archivo. Se guarda el disco para poder migrar a
            // almacenamiento externo sin romper las filas ya existentes.
            $table->string('nombre_original');
            $table->string('ruta');
            $table->string('disco', 30);
            $table->string('mime', 100);
            $table->unsignedBigInteger('tamano_bytes');

            $table->timestamps();

            // Un solo documento por tipo requerido en la misma postulación
            // (reemplazar = actualizar la misma fila). MySQL trata los NULL como
            // distintos, así que los adicionales no chocan entre sí.
            $table->unique(['postulacion_id', 'parametro_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('postulacion_documentos');
    }
};
