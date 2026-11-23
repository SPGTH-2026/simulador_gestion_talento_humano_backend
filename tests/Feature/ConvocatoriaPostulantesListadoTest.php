<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ConvocatoriaPostulacionesAyudas;
use Tests\TestCase;

class ConvocatoriaPostulantesListadoTest extends TestCase
{
    use RefreshDatabase;
    use ConvocatoriaPostulacionesAyudas;

    public function test_listado_instructor_recibe_postulantes_y_no_mi_postulacion(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->convocatoriaAbierta($ficha, $instructor);

        $this->postular($convocatoria, $this->aspirante($ficha));
        $this->postular($convocatoria, $this->aspirante($ficha));

        $item = $this->actingAs($instructor)->getJson('/api/convocatorias')->assertOk()->json('data.0');

        $this->assertSame(2, $item['postulantes']);
        $this->assertArrayNotHasKey('mi_postulacion', $item);
    }

    public function test_listado_super_admin_recibe_postulantes_y_no_mi_postulacion(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->convocatoriaAbierta($ficha, $instructor);

        $this->postular($convocatoria, $this->aspirante($ficha));

        $item = $this->actingAs($this->superAdmin())->getJson('/api/convocatorias')->assertOk()->json('data.0');

        $this->assertSame(1, $item['postulantes']);
        $this->assertArrayNotHasKey('mi_postulacion', $item);
    }

    public function test_listado_aprendiz_gestor_recibe_postulantes_y_no_mi_postulacion(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->convocatoriaAbierta($ficha, $instructor);

        $this->postular($convocatoria, $this->aspirante($ficha));

        $item = $this->actingAs($this->aprendizGestor($ficha))->getJson('/api/convocatorias')->assertOk()->json('data.0');

        $this->assertSame(1, $item['postulantes']);
        $this->assertArrayNotHasKey('mi_postulacion', $item);
    }

    public function test_postulantes_cuenta_solo_estado_postulada(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);
        $convocatoria = $this->convocatoriaAbierta($ficha, $instructor);

        $this->postular($convocatoria, $this->aspirante($ficha));
        $this->postular($convocatoria, $this->aspirante($ficha));
        $this->retirar($convocatoria, $this->aspirante($ficha));

        $item = $this->actingAs($instructor)->getJson('/api/convocatorias')->assertOk()->json('data.0');

        // Dos postuladas + una retirada: la retirada NO suma.
        $this->assertSame(2, $item['postulantes']);
    }

    public function test_listado_gestor_no_dispara_n_mas_1(): void
    {
        $ficha = $this->ficha();
        $instructor = $this->instructor($ficha);

        $convocatorias = [];
        for ($i = 0; $i < 10; $i++) {
            $convocatorias[] = $this->convocatoriaAbierta($ficha, $instructor);
        }

        $this->postular($convocatorias[0], $this->aspirante($ficha));
        $this->postular($convocatorias[1], $this->aspirante($ficha));

        DB::enableQueryLog();
        $this->actingAs($instructor->fresh())->getJson('/api/convocatorias?per_page=100')->assertOk();
        $conDos = count(DB::getQueryLog());
        DB::disableQueryLog();

        for ($i = 2; $i < 10; $i++) {
            $this->postular($convocatorias[$i], $this->aspirante($ficha));
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($instructor->fresh())->getJson('/api/convocatorias?per_page=100')->assertOk();
        $conDiez = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame($conDos, $conDiez);
    }

    // ── Detalle ────────────────────────────────────────────────────────

}
