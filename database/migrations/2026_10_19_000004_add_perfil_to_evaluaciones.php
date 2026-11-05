<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Regla de negocio: si el cumplimiento del perfil es >= 60%, el aspirante
    // pasa a la etapa de Selección. Se guarda el porcentaje y el resultado para
    // que Selección pueda filtrar a los aprobados.
    public function up(): void
    {
        Schema::table('evaluaciones', function (Blueprint $table) {
            $table->json('perfil')->nullable()->after('observaciones');
            $table->unsignedTinyInteger('cumplimiento')->nullable()->after('perfil');
            $table->boolean('pasa_seleccion')->nullable()->after('cumplimiento');
        });
    }

    public function down(): void
    {
        Schema::table('evaluaciones', function (Blueprint $table) {
            $table->dropColumn(['perfil', 'cumplimiento', 'pasa_seleccion']);
        });
    }
};
