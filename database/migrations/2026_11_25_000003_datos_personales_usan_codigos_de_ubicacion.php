<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * El departamento y el municipio de los datos personales dejan de ser texto libre y pasan a ser el código oficial. Las
     * columnas nuevas son nullable para no fallar si ya hay filas con el texto anterior (solo existían en pruebas): la
     * validación las exige siempre al guardar.
     */
    public function up(): void
    {
        Schema::table('postulacion_datos_personales', function (Blueprint $table) {
            $table->string('departamento_codigo', 2)->nullable()->after('telefono');
            $table->string('municipio_codigo', 5)->nullable()->after('departamento_codigo');

            $table->foreign('departamento_codigo')->references('codigo')->on('departamentos')->restrictOnDelete();
            $table->foreign('municipio_codigo')->references('codigo')->on('municipios')->restrictOnDelete();
        });

        Schema::table('postulacion_datos_personales', function (Blueprint $table) {
            $table->dropColumn(['departamento', 'municipio']);
        });
    }

    public function down(): void
    {
        Schema::table('postulacion_datos_personales', function (Blueprint $table) {
            $table->string('departamento', 80)->default('')->after('telefono');
            $table->string('municipio', 80)->default('')->after('departamento');
        });

        Schema::table('postulacion_datos_personales', function (Blueprint $table) {
            $table->dropForeign(['departamento_codigo']);
            $table->dropForeign(['municipio_codigo']);
            $table->dropColumn(['departamento_codigo', 'municipio_codigo']);
        });
    }
};
