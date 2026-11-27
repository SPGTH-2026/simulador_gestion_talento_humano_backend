<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Una postulación RETIRADA cuya convocatoria ya cerró no se puede reabrir: la persona puede «descartarla» para que deje de
     * aparecer en su lista. No se borra nada (sus documentos, evaluaciones e historial se conservan para quien gestiona la
     * convocatoria): solo se marca cuándo se descartó.
     */
    public function up(): void
    {
        Schema::table('postulaciones', function (Blueprint $table) {
            $table->timestamp('descartada_at')->nullable()->after('fecha_retiro');
        });
    }

    public function down(): void
    {
        Schema::table('postulaciones', function (Blueprint $table) {
            $table->dropColumn('descartada_at');
        });
    }
};
