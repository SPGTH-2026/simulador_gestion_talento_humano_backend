<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Ponderación (%) de cada documento dentro de la evaluación de UNA
        // convocatoria. La define el gestor al crearla: no es un valor fijo
        // global. NULL = no configurada en esa convocatoria y el sistema
        // reparte el 100 % en partes iguales entre todos sus documentos.
        Schema::table('convocatoria_documentos', function (Blueprint $table) {
            $table->decimal('ponderacion', 5, 2)->nullable()->after('obligatorio');
        });
    }

    public function down(): void
    {
        Schema::table('convocatoria_documentos', function (Blueprint $table) {
            $table->dropColumn('ponderacion');
        });
    }
};
