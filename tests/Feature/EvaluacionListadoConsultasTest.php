<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Postulacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RondasCorreccionAyudas;
use Tests\TestCase;

/** El listado de evaluaciones hace las mismas consultas con pocos o con muchos aspirantes (nada de una consulta por fila). */
class EvaluacionListadoConsultasTest extends TestCase
{
    use RefreshDatabase;
    use RondasCorreccionAyudas;

    private function consultasDelListado(User|\Illuminate\Contracts\Auth\Authenticatable $actor): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($actor)->getJson('/api/evaluaciones')->assertOk();
        $total = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $total;
    }

    private function agregar(int $cuantos): void
    {
        for ($i = 0; $i < $cuantos; $i++) {
            $p = Postulacion::create([
                'convocatoria_id' => $this->postulacion->convocatoria_id,
                'user_id' => $this->usuario(Role::Aspirante)->id,
                'estado_id' => $this->parametroId('estado_postulacion', 'postulada'),
                'fecha_postulacion' => now(),
            ]);
            $p->evaluaciones()->create(['evaluador_id' => $this->evaluador->id, 'estado' => 'en_proceso']);
        }
    }

    public function test_el_listado_no_hace_una_consulta_por_aspirante(): void
    {
        $this->agregar(2);
        $pocos = $this->consultasDelListado($this->instructor);

        $this->agregar(15);
        $muchos = $this->consultasDelListado($this->instructor);

        // Solo cambian detalles de la página (nunca una consulta por cada aspirante nuevo).
        $this->assertLessThanOrEqual($pocos + 2, $muchos, "Con 15 aspirantes más pasó de {$pocos} a {$muchos} consultas.");
    }
}
