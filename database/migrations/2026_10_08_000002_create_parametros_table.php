<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Catálogo de parámetros. Los parámetros NUNCA se borran: se desactivan.
        // Los que tienen es_sistema = true no se pueden desactivar ni cambiar su
        // codigo (estados de convocatoria y documentos obligatorios).
        Schema::create('parametros', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tema_id')->constrained('parametro_temas')->cascadeOnDelete();
            // Código estable para los valores de sistema (los ids cambian entre
            // local y Render); NULL para los parámetros comunes.
            $table->string('codigo')->nullable();
            $table->string('nombre');
            $table->unsignedInteger('orden')->default(0);
            $table->boolean('activo')->default(true);
            $table->boolean('es_sistema')->default(false);
            // Valor prefijado en el formulario para los temas que lo definen
            // (tipo_convocatoria, tipo_empleo, tipo_contrato, modalidad).
            $table->boolean('es_defecto')->default(false);
            $table->timestamps();

            // Un mismo nombre no puede repetirse dentro de un tema.
            $table->unique(['tema_id', 'nombre']);
            // Un mismo código no puede repetirse dentro de un tema (dos temas
            // distintos sí pueden compartir código; MySQL admite varios NULL).
            $table->unique(['tema_id', 'codigo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parametros');
    }
};