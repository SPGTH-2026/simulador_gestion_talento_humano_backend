<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Ficha a la que pertenece un aspirante/aprendiz. El instructor se liga
        // por el pivot ficha_instructor; el super admin no pertenece a ninguna.
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('ficha_id')->nullable()->after('active')->constrained('fichas')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ficha_id');
        });
    }
};