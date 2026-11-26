<?php

use App\Models\Parametro;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * La autorización del representante legal de un menor se firma EN PERSONA al contratar: el sistema ya no la pide como
     * documento. El parámetro se DESACTIVA (no se borra): los archivos que alguien ya subió siguen referenciándolo y el
     * historial se conserva. Si el parámetro no existe (base nueva) no hace nada.
     */
    public function up(): void
    {
        Parametro::query()
            ->where('codigo', 'autorizacion_representante')
            ->whereHas('tema', fn ($q) => $q->where('codigo', 'tipo_documento'))
            ->update(['activo' => false]);
    }

    public function down(): void
    {
        Parametro::query()
            ->where('codigo', 'autorizacion_representante')
            ->whereHas('tema', fn ($q) => $q->where('codigo', 'tipo_documento'))
            ->update(['activo' => true]);
    }
};
