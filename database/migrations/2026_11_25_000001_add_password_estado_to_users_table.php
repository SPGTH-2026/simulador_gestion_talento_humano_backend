<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marca de cómo se originó la contraseña de una cuenta. NULL = estado histórico DESCONOCIDO: las cuentas que ya existían se
 * quedan así (no se rellena nada por inferencias de fechas ni de otro tipo). Solo se escribe en eventos comprobados:
 *  - 'definida'    la persona (o un administrador, por consola) eligió esa contraseña: registro, restablecer, establecer.
 *  - 'sin_definir' la contraseña la generó el sistema (cuenta creada con Google, o credenciales retiradas por seguridad).
 * La marca NO decide quién puede iniciar sesión: la autenticación sigue dependiendo solo de la contraseña guardada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('password_estado', 20)->nullable()->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('password_estado');
        });
    }
};
