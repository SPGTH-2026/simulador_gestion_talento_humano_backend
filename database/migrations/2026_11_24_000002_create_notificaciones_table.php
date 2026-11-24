<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Notificaciones dentro de la aplicación (la campana). Cada fila pertenece a UN usuario y se marca como leída con
     * `leida_at`. `url` es la ruta del front a la que lleva el aviso al abrirlo; nula si no hay a dónde llevar.
     */
    public function up(): void
    {
        Schema::create('notificaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('tipo', 40);
            $table->string('titulo', 120);
            $table->text('mensaje');
            $table->string('url', 255)->nullable();
            $table->timestamp('leida_at')->nullable();
            $table->timestamps();

            // La campana cuenta las no leídas y la lista se ordena por fecha: ambas consultas filtran por usuario.
            $table->index(['user_id', 'leida_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notificaciones');
    }
};
