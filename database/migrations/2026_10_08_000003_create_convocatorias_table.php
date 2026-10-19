<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('convocatorias', function (Blueprint $table) {
            $table->id();

            // Marca de visibilidad, no identidad de negocio: la convocatoria se
            // identifica con su codigo (CONV-AAAA-NNN) y ficha_id solo dice a qué
            // grupo mostrarla. Se toma del usuario autenticado; nunca viene del
            // formulario. RESTRICT: una ficha con convocatorias no se elimina.
            // Sin índice suelto para ficha_id: lo cubre el UNIQUE(ficha_id, codigo).
            $table->foreignId('ficha_id')->constrained('fichas')->restrictOnDelete();

            // Generado siempre (CONV-AAAA-NNN) por ficha; único dentro de la ficha.
            $table->string('codigo', 50);
            $table->string('nombre', 150);

            // Obligatorios solo al publicar (validación de la aplicación);
            // al guardar borrador pueden quedar nulos.
            $table->string('cargo', 120)->nullable();
            $table->string('area', 120)->nullable();
            $table->text('descripcion')->nullable();

            // Sin CHECK en la base: salario_min <= salario_max y
            // fecha_cierre >= fecha_apertura se validan en la aplicación.
            $table->decimal('salario_min', 12, 2)->unsigned()->nullable();
            $table->decimal('salario_max', 12, 2)->unsigned()->nullable();

            $table->text('funciones')->nullable();
            $table->text('responsabilidades')->nullable();
            $table->text('perfil_requerido')->nullable();
            $table->text('formacion')->nullable();
            $table->text('experiencia')->nullable();
            $table->text('competencias')->nullable();
            $table->text('conocimientos_especificos')->nullable();

            $table->unsignedInteger('vacantes')->default(1);

            // Clasificación: FK a parametros RESTRICT, NOT NULL. Si el formulario
            // no envía el valor, la aplicación resuelve el es_defecto del tema.
            $table->foreignId('tipo_convocatoria_id')->constrained('parametros')->restrictOnDelete();
            $table->foreignId('tipo_empleo_id')->constrained('parametros')->restrictOnDelete();
            $table->foreignId('tipo_contrato_id')->constrained('parametros')->restrictOnDelete();
            $table->foreignId('modalidad_id')->constrained('parametros')->restrictOnDelete();

            // Estado (borrador/publicada/en_proceso/cerrada), resuelto por codigo.
            $table->foreignId('estado_id')->constrained('parametros')->restrictOnDelete();

            $table->date('fecha_apertura')->nullable();
            $table->date('fecha_cierre')->nullable();
            $table->date('fecha_evaluacion')->nullable();

            $table->foreignId('creado_por')->constrained('users')->restrictOnDelete();

            $table->timestamps();

            // Un codigo por ficha.
            $table->unique(['ficha_id', 'codigo']);
            $table->index('estado_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('convocatorias');
    }
};
