<?php

use App\Models\Parametro;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Las convocatorias creadas antes de que el checklist fuera predeterminado (o por la API sin escribirlo) tienen documentos sin
     * ítems: «Certificados laborales» llevaba checklist en unas y en otras no. Se les guarda el checklist predeterminado de cada
     * documento SOLO donde está vacío; lo que un gestor escribió no se toca.
     */
    public function up(): void
    {
        foreach ((array) config('convocatorias.checklist_por_defecto', []) as $codigo => $items) {
            // Consulta directa: Parametro::porTemaYCodigo memoriza el resultado en toda la petición (y, en pruebas, en todo el proceso),
            // y aquí la tabla puede estar vacía todavía.
            $parametro = Parametro::query()
                ->whereHas('tema', fn ($q) => $q->where('codigo', 'tipo_documento'))
                ->where('codigo', (string) $codigo)
                ->first();

            if (! $parametro) {
                continue;
            }

            DB::table('convocatoria_documentos')
                ->where('parametro_id', $parametro->id)
                ->where(fn ($q) => $q->whereNull('items')->orWhere('items', ''))
                ->update(['items' => $items]);
        }
    }

    /** No se deshace: no se puede distinguir un checklist guardado por esta migración de uno escrito después a mano. */
    public function down(): void {}
};
