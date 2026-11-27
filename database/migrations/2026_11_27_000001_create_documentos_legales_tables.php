<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Versiones de textos legales (política de privacidad, términos) y aceptaciones de los usuarios.
     *
     * Una versión nace «borrador»; el superadministrador la aprueba y desde ese momento es inmutable (el contenido queda sellado con
     * su huella SHA-256). La aprobación es ADMINISTRATIVA: no certifica que el texto sea jurídicamente válido.
     *
     * La evidencia no depende de que la cuenta siga existiendo: quien aprobó y quien aceptó quedan también como copia (nombre y correo
     * del momento), y las claves hacia `users` se ponen en NULL al borrar la cuenta en vez de borrar la fila. Desactivar una cuenta no
     * toca nada. No se guarda IP ni datos del navegador.
     */
    public function up(): void
    {
        Schema::create('documentos_legales', function (Blueprint $table) {
            $table->id();
            $table->string('tipo', 30); // privacidad | terminos
            $table->string('version', 20);
            $table->string('titulo', 150);
            $table->longText('contenido');
            $table->string('estado', 20)->default('borrador'); // borrador | aprobado
            $table->string('huella', 64)->nullable(); // SHA-256 del título y el contenido, fijada al aprobar
            $table->timestamp('aprobado_en')->nullable();
            $table->foreignId('aprobado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->string('aprobado_por_nombre', 255)->nullable();
            $table->string('aprobado_por_correo', 255)->nullable();
            $table->foreignId('creado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tipo', 'version']);
        });

        Schema::create('aceptaciones_legales', function (Blueprint $table) {
            $table->id();
            // restrict: una versión con aceptaciones no se puede borrar.
            $table->foreignId('documento_legal_id')->constrained('documentos_legales')->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('usuario_nombre', 255);
            $table->string('usuario_correo', 255);
            $table->string('huella_aceptada', 64); // la del documento en el momento de aceptar
            $table->timestamp('aceptado_en');
            $table->timestamp('created_at')->nullable();

            // Idempotencia: una aceptación por usuario y versión. (Con user_id NULL no choca: son cuentas ya borradas.)
            $table->unique(['user_id', 'documento_legal_id'], 'aceptacion_unica');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aceptaciones_legales');
        Schema::dropIfExists('documentos_legales');
    }
};
