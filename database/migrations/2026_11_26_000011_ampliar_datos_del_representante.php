<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Más datos del representante legal de un menor (16 y 17 años) para poder localizarlo: la autorización se firma en
     * persona al contratar, así que el sistema solo necesita saber quién es y cómo contactarlo. Todas las columnas son
     * nullable: solo se llenan cuando la persona es menor de edad.
     *
     * Las llaves foráneas llevan nombre corto a propósito: MySQL limita los identificadores a 64 caracteres y el nombre que
     * Laravel genera solo («postulacion_datos_personales_representante_..._foreign») lo supera.
     */
    public function up(): void
    {
        Schema::table('postulacion_datos_personales', function (Blueprint $table) {
            $table->foreignId('representante_tipo_documento_id')->nullable()->after('representante_documento')
                ->constrained('parametros', 'id', 'pdp_rep_tipo_doc_fk')->restrictOnDelete();
            $table->string('representante_correo', 150)->nullable()->after('representante_telefono');
            $table->string('representante_telefono_alterno', 20)->nullable()->after('representante_correo');

            // Residencia del representante (opcional): departamento y municipio con los códigos oficiales (DIVIPOLA).
            $table->string('representante_departamento_codigo', 2)->nullable()->after('representante_telefono_alterno');
            $table->string('representante_municipio_codigo', 5)->nullable()->after('representante_departamento_codigo');
            $table->foreign('representante_departamento_codigo', 'pdp_rep_depto_fk')->references('codigo')->on('departamentos')->restrictOnDelete();
            $table->foreign('representante_municipio_codigo', 'pdp_rep_mun_fk')->references('codigo')->on('municipios')->restrictOnDelete();

            // Declaración del menor: su representante legal conoce la postulación.
            $table->boolean('representante_conoce')->default(false)->after('representante_parentesco');
        });
    }

    public function down(): void
    {
        Schema::table('postulacion_datos_personales', function (Blueprint $table) {
            $table->dropForeign('pdp_rep_tipo_doc_fk');
            $table->dropForeign('pdp_rep_depto_fk');
            $table->dropForeign('pdp_rep_mun_fk');
        });

        Schema::table('postulacion_datos_personales', function (Blueprint $table) {
            $table->dropColumn([
                'representante_tipo_documento_id', 'representante_correo', 'representante_telefono_alterno',
                'representante_departamento_codigo', 'representante_municipio_codigo', 'representante_conoce',
            ]);
        });
    }
};
