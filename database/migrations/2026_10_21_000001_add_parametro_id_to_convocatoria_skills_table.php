<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Origen de una skill de convocatoria:
     *  - parametro_id != NULL -> viene del CATÁLOGO (tema skill_catalogo).
     *  - parametro_id IS NULL -> TEXTO LIBRE escrito por el gestor.
     *
     * La columna es NULLABLE: las skills ya guardadas (texto libre) siguen
     * válidas y no se tocan. Si el parámetro del catálogo se retira, la fila se
     * CONSERVA con su nombre copiado (SET NULL), igual que ya ocurre en
     * postulacion_skills. El historial de la convocatoria no se rompe.
     *
     * El UNIQUE(convocatoria_id, nombre) no cambia. La collation de la tabla
     * (utf8mb4_unicode_ci) ya hace que ese índice sea case-insensitive; la
     * deduplica sin distinguir mayúsculas y espacios sobrantes la reinforce el
     * controlador antes de guardar.
     */
    public function up(): void
    {
        Schema::table('convocatoria_skills', function (Blueprint $table) {
            $table->foreignId('parametro_id')->nullable()->after('nombre')
                ->constrained('parametros')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('convocatoria_skills', function (Blueprint $table) {
            $table->dropForeign(['parametro_id']);
            $table->dropColumn('parametro_id');
        });
    }
};
