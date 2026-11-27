<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * El sexo se declara en el formulario de datos personales de cada postulación, así que se guarda también ahí (además del
     * perfil, del que dependen otras reglas como la libreta militar). Sin él, el formulario guardado no se podía reconstruir
     * completo. Las filas que ya existen toman el sexo del perfil de su dueño.
     */
    public function up(): void
    {
        Schema::table('postulacion_datos_personales', function (Blueprint $table) {
            $table->foreignId('sexo_id')->nullable()->after('postulacion_id')
                ->constrained('parametros', 'id', 'pdp_sexo_fk')->restrictOnDelete();
        });

        DB::statement(
            'UPDATE postulacion_datos_personales d
             JOIN postulaciones p ON p.id = d.postulacion_id
             JOIN perfiles_aspirante pa ON pa.user_id = p.user_id
             SET d.sexo_id = pa.sexo_id
             WHERE d.sexo_id IS NULL',
        );
    }

    public function down(): void
    {
        Schema::table('postulacion_datos_personales', function (Blueprint $table) {
            $table->dropForeign('pdp_sexo_fk');
        });

        Schema::table('postulacion_datos_personales', function (Blueprint $table) {
            $table->dropColumn('sexo_id');
        });
    }
};
