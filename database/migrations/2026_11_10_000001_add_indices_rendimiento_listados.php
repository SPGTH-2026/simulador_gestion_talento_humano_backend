<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Índices para que los listados no recorran la tabla completa:
//  - postulaciones.user_id: "mis postulaciones" y el conteo por aspirante filtran por
//    usuario; el índice único actual empieza por convocatoria_id y no ayuda.
//  - convocatorias.fecha_apertura: el listado se ordena por esa columna.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('postulaciones', function (Blueprint $table) {
            $table->index('user_id');
        });

        Schema::table('convocatorias', function (Blueprint $table) {
            $table->index('fecha_apertura');
        });
    }

    public function down(): void
    {
        Schema::table('postulaciones', function (Blueprint $table) {
            $table->dropIndex(['user_id']);
        });

        Schema::table('convocatorias', function (Blueprint $table) {
            $table->dropIndex(['fecha_apertura']);
        });
    }
};
