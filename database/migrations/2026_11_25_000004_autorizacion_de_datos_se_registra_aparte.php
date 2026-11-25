<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Los datos personales se guardan solos, a medida que se completan; la autorización del tratamiento de datos se
     * registra aparte cuando la persona la marca. Por eso la fecha de autorización pasa a ser nullable: null = todavía no
     * autorizó (y entonces no puede enviar la postulación).
     */
    public function up(): void
    {
        Schema::table('postulacion_datos_personales', function (Blueprint $table) {
            $table->timestamp('acepta_tratamiento_datos_at')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Las filas sin autorización no caben en la columna obligatoria: se les pone la fecha de creación.
        DB::table('postulacion_datos_personales')->whereNull('acepta_tratamiento_datos_at')->update([
            'acepta_tratamiento_datos_at' => DB::raw('created_at'),
        ]);

        Schema::table('postulacion_datos_personales', function (Blueprint $table) {
            $table->timestamp('acepta_tratamiento_datos_at')->nullable(false)->change();
        });
    }
};
