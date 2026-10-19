<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('postulaciones', function (Blueprint $table) {
            $table->id();

            // La ficha NO se copia aquí: se deriva de la convocatoria. Cada FK es
            // RESTRICT porque borrar una convocatoria, un usuario o un parámetro
            // en uso dejaría postulaciones huérfanas.
            $table->foreignId('convocatoria_id')->constrained('convocatorias')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('estado_id')->constrained('parametros')->restrictOnDelete();

            // datetime: se guarda en la zona de la aplicación (config/app.php).
            // El controlador compara contra hoy en America/Bogota al validar la
            // ventana de la convocatoria.
            $table->dateTime('fecha_postulacion')->useCurrent();
            $table->dateTime('fecha_retiro')->nullable();

            $table->timestamps();

            // Respaldo de la regla "no se postula dos veces a la misma
            // convocatoria": si la aplicación falla, el índice evita el duplicado.
            // También sirve de índice para las búsquedas por convocatoria.
            // El índice para "mis postulaciones" (user_id) lo crea la FK de MySQL.
            $table->unique(['convocatoria_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('postulaciones');
    }
};
