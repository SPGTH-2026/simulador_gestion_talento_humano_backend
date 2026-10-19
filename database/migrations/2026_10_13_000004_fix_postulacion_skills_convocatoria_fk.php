<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ajuste #4: la FK convocatoria_skill_id de postulacion_skills debe ser
     * ON DELETE SET NULL (no CASCADE), para CONSERVAR la fila de la postulación
     * como skill propia cuando la convocatoria borra su skill (el nombre ya
     * quedó copiado en la fila).
     *
     * La migración 2026_10_13_000003 ya se había aplicado con CASCADE antes de
     * corregirla, así que aquí se recrea la FK en los entornos ya migrados.
     * En una instalación nueva la 000003 ya la crea como SET NULL y este paso la
     * deja igual (idempotente).
     */
    public function up(): void
    {
        Schema::table('postulacion_skills', function (Blueprint $table) {
            $table->dropForeign(['convocatoria_skill_id']);
            $table->foreign('convocatoria_skill_id')
                ->references('id')->on('convocatoria_skills')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('postulacion_skills', function (Blueprint $table) {
            $table->dropForeign(['convocatoria_skill_id']);
            $table->foreign('convocatoria_skill_id')
                ->references('id')->on('convocatoria_skills')
                ->cascadeOnDelete();
        });
    }
};
