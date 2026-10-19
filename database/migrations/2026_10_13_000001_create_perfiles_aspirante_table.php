<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Perfil mínimo del aspirante. Se crea la fila la PRIMERA vez que el
        // aspirante responde su sexo (en el paso de documentos, solo si la
        // convocatoria pide libreta militar). No se toca users ni el registro.
        Schema::create('perfiles_aspirante', function (Blueprint $table) {
            $table->id();

            // Data derivada del usuario: si se borra el usuario, el perfil no
            // tiene sentido y se va con él (CASCADE). Un usuario tiene UN perfil.
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();

            // Parámetro del tema 'sexo' (códigos hombre/mujer). RESTRICT: un
            // parámetro en uso no se borra (los parámetros se desactivan, no se borran).
            $table->foreignId('sexo_id')->constrained('parametros')->restrictOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('perfiles_aspirante');
    }
};
