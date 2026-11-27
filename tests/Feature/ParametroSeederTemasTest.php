<?php

namespace Tests\Feature;

use App\Models\Parametro;
use App\Models\ParametroTema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ParametroSeederAyudas;
use Tests\TestCase;

class ParametroSeederTemasTest extends TestCase
{
    use RefreshDatabase;
    use ParametroSeederAyudas;

    public function test_crea_los_doce_temas_con_su_codigo(): void
    {
        $this->ejecutarSeeder();

        $esperados = [
            'estado_convocatoria',
            'estado_postulacion',
            'tipo_convocatoria',
            'tipo_documento',
            'tipo_empleo',
            'tipo_contrato',
            'modalidad',
            'sexo',
            'skill_tecnica',
            'skill_catalogo',
            'tipo_documento_identidad',
            'nivel_formacion',
        ];

        foreach ($esperados as $codigo) {
            $this->assertDatabaseHas('parametro_temas', ['codigo' => $codigo, 'activo' => 1]);
        }

        $this->assertSame(12, ParametroTema::count());
    }

    public function test_crea_la_cantidad_esperada_de_parametros_por_tema(): void
    {
        $this->ejecutarSeeder();

        $esperados = [
            'estado_convocatoria' => 4,
            'estado_postulacion' => 3,
            'tipo_convocatoria' => 2,
            'tipo_documento' => 6,
            'tipo_empleo' => 3,
            'tipo_contrato' => 4,
            'modalidad' => 3,
            'sexo' => 2,
            'skill_tecnica' => 6,
            'skill_catalogo' => 10,
            'tipo_documento_identidad' => 5,
            'nivel_formacion' => 5,
        ];

        foreach ($esperados as $tema => $cantidad) {
            $this->assertSame(
                $cantidad,
                ParametroTema::where('codigo', $tema)->first()->parametros()->count(),
                "El tema {$tema} debe tener {$cantidad} parámetros."
            );
        }

        $this->assertSame(53, Parametro::count());
    }

    public function test_valores_de_sistema_quedan_con_codigo_activos_y_es_sistema(): void
    {
        $this->ejecutarSeeder();

        $deSistema = [
            ['estado_convocatoria', 'borrador'],
            ['estado_convocatoria', 'publicada'],
            ['estado_convocatoria', 'en_proceso'],
            ['estado_convocatoria', 'cerrada'],
            ['estado_postulacion', 'borrador'],
            ['estado_postulacion', 'postulada'],
            ['estado_postulacion', 'retirada'],
            ['tipo_documento', 'hoja_de_vida'],
            ['tipo_documento', 'certificados_laborales'],
        ];

        foreach ($deSistema as [$tema, $codigo]) {
            $parametro = Parametro::porTemaYCodigo($tema, $codigo);

            $this->assertNotNull($parametro, "Falta el parámetro {$tema}/{$codigo}.");
            $this->assertTrue($parametro->es_sistema, "{$tema}/{$codigo} debe ser de sistema.");
            $this->assertTrue($parametro->activo, "{$tema}/{$codigo} debe quedar activo.");
        }
    }

    public function test_el_seeder_es_idempotente(): void
    {
        $this->ejecutarSeeder();
        $this->ejecutarSeeder();

        $this->assertSame(12, ParametroTema::count());
        $this->assertSame(53, Parametro::count());
    }

    /**
     * Agregar 'borrador' y reordenar NO debe reasignar ids: el seeder resuelve
     * cada parámetro por (tema, codigo) con updateOrCreate. Se comprueba que
     * re-ejecutarlo deja los ids de postulada y retirada intactos.
     */
    public function test_volver_a_sembrar_no_cambia_los_ids_de_postulada_ni_retirada(): void
    {
        $this->ejecutarSeeder();

        $antes = [
            'postulada' => Parametro::porTemaYCodigo('estado_postulacion', 'postulada')->id,
            'retirada' => Parametro::porTemaYCodigo('estado_postulacion', 'retirada')->id,
        ];
        $borrador = Parametro::porTemaYCodigo('estado_postulacion', 'borrador')->id;

        $this->ejecutarSeeder();

        $this->assertSame($antes['postulada'], Parametro::porTemaYCodigo('estado_postulacion', 'postulada')->id);
        $this->assertSame($antes['retirada'], Parametro::porTemaYCodigo('estado_postulacion', 'retirada')->id);
        $this->assertSame($borrador, Parametro::porTemaYCodigo('estado_postulacion', 'borrador')->id);

        // Y el orden aprobado queda borrador(1), postulada(2), retirada(3).
        $this->assertSame(
            ['borrador', 'postulada', 'retirada'],
            Parametro::whereHas('tema', fn ($q) => $q->where('codigo', 'estado_postulacion'))
                ->orderBy('orden')
                ->pluck('codigo')
                ->all(),
        );
    }

}
