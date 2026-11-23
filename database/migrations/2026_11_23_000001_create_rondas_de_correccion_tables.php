<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rondas de corrección por POSTULACIÓN (no por evaluador): así el límite de dos rondas ordinarias se comparte entre
     * evaluadores y se conserva el historial completo. Solo crea la estructura: las correcciones antiguas se convierten
     * aparte, de forma explícita, con `php artisan correcciones:migrar-legado`.
     *
     * Las fechas son timestamps en UTC (zona de la aplicación); la interfaz las muestra en America/Bogota.
     */
    public function up(): void
    {
        Schema::create('correcciones_postulacion', function (Blueprint $table) {
            $table->id();
            $table->foreignId('postulacion_id')->constrained('postulaciones')->cascadeOnDelete();
            // 'ordinaria' (máximo dos) o 'excepcional' (autorizada por Instructor/Superadmin). El número cuenta dentro del tipo.
            $table->string('tipo', 12)->default('ordinaria');
            $table->unsignedSmallInteger('numero');
            $table->foreignId('solicitada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('evaluacion_id')->nullable()->constrained('evaluaciones')->nullOnDelete();
            $table->json('documentos_marcados');
            // Archivo (ruta) de cada documento marcado en el momento de la solicitud: un documento cuenta como reemplazado
            // solo si su archivo cambió, sin depender de relojes ni de que otro evaluador guarde su evaluación. Nulo en las
            // solicitudes antiguas, que usan la fecha del documento.
            $table->json('documentos_base')->nullable();
            $table->text('observaciones')->nullable();
            $table->timestamp('solicitada_at')->nullable();
            // Nulo solo en las solicitudes antiguas que nunca tuvieron plazo («Sin plazo registrado»).
            $table->timestamp('fecha_limite')->nullable();
            $table->timestamp('respondida_at')->nullable();
            $table->json('documentos_respondidos')->nullable();
            $table->timestamp('cerrada_at')->nullable();
            // 1 mientras la ronda espera respuesta; NULL cuando se cierra (respondida o vencida). El índice único deja que
            // la base garantice «una sola solicitud pendiente por postulación»: NULL no choca con NULL en MySQL.
            $table->unsignedTinyInteger('abierta')->nullable();
            $table->unsignedBigInteger('autorizacion_id')->nullable();
            // 'nueva' o 'legado' (convertida desde una evaluación anterior). La evaluación de origen es única: evita contarla dos veces.
            $table->string('origen', 10)->default('nueva');
            $table->unsignedBigInteger('evaluacion_legada_id')->nullable()->unique();
            $table->timestamps();

            $table->unique(['postulacion_id', 'abierta']);
            $table->unique(['postulacion_id', 'tipo', 'numero']);
        });

        Schema::create('correccion_autorizaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('postulacion_id')->constrained('postulaciones')->cascadeOnDelete();
            $table->foreignId('autorizada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->text('justificacion');
            $table->foreignId('usada_en_correccion_id')->nullable()->constrained('correcciones_postulacion')->nullOnDelete();
            $table->timestamp('usada_at')->nullable();
            // 1 mientras no se use; NULL al usarse. Único por postulación: solo una autorización sin usar.
            $table->unsignedTinyInteger('sin_usar')->nullable();
            $table->timestamps();

            $table->unique(['postulacion_id', 'sin_usar']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('correccion_autorizaciones');
        Schema::dropIfExists('correcciones_postulacion');
    }
};
