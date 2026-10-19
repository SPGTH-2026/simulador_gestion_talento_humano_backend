<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Origen de una skill declarada en la postulación:
     *  - convocatoria_skill_id != NULL -> "convocatoria"
     *  - parametro_id != NULL          -> "catalogo" (tema skill_catalogo)
     *  - ambos NULL                    -> "propia" (texto libre)
     *
     * `parametro_id` es NULL para las skills de convocatoria y las propias. Si el
     * parámetro del catálogo se borra, la fila se CONSERVA con su nombre copiado
     * (SET NULL), igual que con la skill de la convocatoria.
     *
     * El UNIQUE(postulacion_id, nombre) no cambia: sigue impidiendo duplicados
     * sin importar el origen ni las mayúsculas.
     */
    public function up(): void
    {
        Schema::table('postulacion_skills', function (Blueprint $table) {
            $table->foreignId('parametro_id')->nullable()->after('convocatoria_skill_id')
                ->constrained('parametros')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('postulacion_skills', function (Blueprint $table) {
            $table->dropForeign(['parametro_id']);
            $table->dropColumn('parametro_id');
        });
    }
};
