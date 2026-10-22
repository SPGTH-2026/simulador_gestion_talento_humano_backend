<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Quién creó la ficha. Con esto el instructor que la registra queda
        // como dueño (auto-ownership) y el super admin puede auditar el origen.
        // Es nullable: las fichas sembradas antes de esta columna no tienen
        // creador. Si el usuario se borra, la ficha se queda (nullOnDelete).
        Schema::table('fichas', function (Blueprint $table) {
            $table->foreignId('creado_por')->nullable()->after('estado')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('fichas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('creado_por');
        });
    }
};
