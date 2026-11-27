<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ocultar una notificación (no es borrarla ni leerla): `ocultada_at` la saca de la lista normal y del contador de pendientes, y
     * restaurarla la devuelve. El estado de lectura (`leida_at`) no se toca.
     */
    public function up(): void
    {
        Schema::table('notificaciones', function (Blueprint $table) {
            $table->timestamp('ocultada_at')->nullable()->after('leida_at');
            $table->index(['user_id', 'ocultada_at']);
        });
    }

    public function down(): void
    {
        Schema::table('notificaciones', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'ocultada_at']);
            $table->dropColumn('ocultada_at');
        });
    }
};
