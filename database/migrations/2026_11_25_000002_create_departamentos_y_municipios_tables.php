<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * División político-administrativa de Colombia (DIVIPOLA, DANE). La clave es el código oficial: 2 dígitos para el
     * departamento y 5 para el municipio (los dos primeros son los de su departamento). Se siembra desde
     * database/data/divipola.json con UbicacionSeeder.
     */
    public function up(): void
    {
        Schema::create('departamentos', function (Blueprint $table) {
            $table->string('codigo', 2)->primary();
            $table->string('nombre', 80);
        });

        Schema::create('municipios', function (Blueprint $table) {
            $table->string('codigo', 5)->primary();
            $table->string('departamento_codigo', 2);
            $table->string('nombre', 80);
            // municipio | isla | area_no_municipalizada (hay zonas habitadas sin municipio, sobre todo en la Amazonía).
            $table->string('tipo', 30)->default('municipio');

            $table->foreign('departamento_codigo')->references('codigo')->on('departamentos')->restrictOnDelete();
            $table->index(['departamento_codigo', 'nombre']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('municipios');
        Schema::dropIfExists('departamentos');
    }
};
