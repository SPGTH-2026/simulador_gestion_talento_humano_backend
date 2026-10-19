<?php

namespace App\Tests\Feature;

use App\Models\Parametro;
use App\Models\ParametroTema;
use Database\Seeders\ParametroSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ParametroSeederTest extends TestCase
{
    use RefreshDatabase;

    private function ejecutarSeeder(): void
    {
        $this->seed(ParametroSeeder::class);
    }

    public function test_crea_los_diez_temas_con_su_codigo(): void
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
        ];

        foreach ($esperados as $codigo) {
            $this->assertDatabaseHas('parametro_temas', ['codigo' => $codigo, 'activo' => 1]);
        }

        $this->assertSame(10, ParametroTema::count());
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
            'skill_catalogo' => 19,
        ];

        foreach ($esperados as $tema => $cantidad) {
            $this->assertSame(
                $cantidad,
                ParametroTema::where('codigo', $tema)->first()->parametros()->count(),
                "El tema {$tema} debe tener {$cantidad} parámetros."
            );
        }

        $this->assertSame(52, Parametro::count());
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

        $this->assertSame(10, ParametroTema::count());
        $this->assertSame(52, Parametro::count());
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

    public function test_catalogo_aprobado_usa_los_codigos_y_nombres_esperados(): void
    {
        $this->ejecutarSeeder();

        // tipo_convocatoria: interno / externo (externo es el defecto).
        $this->assertNotNull(Parametro::porTemaYCodigo('tipo_convocatoria', 'interno'));
        $externo = Parametro::porTemaYCodigo('tipo_convocatoria', 'externo');
        $this->assertNotNull($externo);
        $this->assertTrue($externo->es_defecto, 'tipo_convocatoria/externo debe ser el es_defecto.');

        // tipo_documento: 6 códigos aprobados; solo 2 son de sistema.
        $documentos = [
            'documento_identidad',
            'hoja_de_vida',
            'certificados_academicos',
            'certificados_laborales',
            'libreta_militar',
            'tarjeta_profesional',
        ];

        foreach ($documentos as $codigo) {
            $this->assertNotNull(
                Parametro::porTemaYCodigo('tipo_documento', $codigo),
                "Falta tipo_documento/{$codigo}."
            );
        }

        // certificados_academicos conserva el código pero cambia el nombre visible
        // (Certificados de estudio): el seeder lo resuelve por CÓDIGO para no
        // duplicar la fila.
        $certificados = Parametro::porTemaYCodigo('tipo_documento', 'certificados_academicos');
        $this->assertSame('Certificados de estudio', $certificados->nombre);
        $this->assertSame(
            1,
            Parametro::whereHas('tema', fn ($q) => $q->where('codigo', 'tipo_documento'))
                ->where('codigo', 'certificados_academicos')
                ->count()
        );

        $sistema = Parametro::whereHas('tema', fn ($q) => $q->where('codigo', 'tipo_documento'))
            ->where('es_sistema', true)
            ->pluck('codigo')
            ->sort()
            ->values()
            ->all();
        $this->assertSame(['certificados_laborales', 'hoja_de_vida'], $sistema);

        // skill_tecnica: nombres de sugerencia aprobados, sin códigos de sistema.
        $nombres = Parametro::whereHas('tema', fn ($q) => $q->where('codigo', 'skill_tecnica'))
            ->orderBy('orden')
            ->pluck('nombre')
            ->all();
        $this->assertSame([
            'Excel avanzado',
            'Power BI',
            'SAP HR',
            'Legislación laboral',
            'Selección de personal',
            'Entrevistas por competencias',
        ], $nombres);

        // skill_catalogo: 19 skills con código estable (para declararlas por id).
        $catalogo = Parametro::whereHas('tema', fn ($q) => $q->where('codigo', 'skill_catalogo'))
            ->orderBy('orden')
            ->get();

        $this->assertSame([
            'comunicacion_asertiva',
            'trabajo_en_equipo',
            'liderazgo',
            'resolucion_problemas',
            'gestion_del_tiempo',
            'atencion_al_cliente',
            'adaptabilidad',
            'excel',
            'word',
            'powerpoint',
            'ingles',
            'sql_bases_de_datos',
            'analisis_de_datos',
            'contabilidad_basica',
            'manejo_nomina',
            'seguridad_salud_trabajo',
            'manejo_inventarios',
            'gestion_documental',
            'redaccion_informes',
        ], $catalogo->pluck('codigo')->all());

        $this->assertSame('Comunicación asertiva', Parametro::porTemaYCodigo('skill_catalogo', 'comunicacion_asertiva')->nombre);
        $this->assertSame('Seguridad y salud en el trabajo', Parametro::porTemaYCodigo('skill_catalogo', 'seguridad_salud_trabajo')->nombre);
        $this->assertSame('Redacción de informes', Parametro::porTemaYCodigo('skill_catalogo', 'redaccion_informes')->nombre);
    }

    public function test_los_documentos_eliminados_ya_no_se_siembran(): void
    {
        $this->ejecutarSeeder();

        foreach (['titulos', 'soportes_laborales', 'certificacion_competencias'] as $codigo) {
            $this->assertNull(
                Parametro::porTemaYCodigo('tipo_documento', $codigo),
                "El documento {$codigo} ya no debe estar en el catálogo."
            );
        }
    }

    public function test_por_tema_y_codigo_resuelve_estados_y_documentos_obligatorios(): void
    {
        $this->ejecutarSeeder();

        $publicada = Parametro::porTemaYCodigo('estado_convocatoria', 'publicada');
        $hojaDeVida = Parametro::porTemaYCodigo('tipo_documento', Parametro::DOC_HOJA_DE_VIDA);

        $this->assertNotNull($publicada);
        $this->assertSame('Publicada', $publicada->nombre);
        $this->assertNotNull($hojaDeVida);
        $this->assertSame('Hoja de vida', $hojaDeVida->nombre);
    }

    public function test_tipo_contrato_incluye_obra_o_labor(): void
    {
        $this->ejecutarSeeder();

        $obra = Parametro::porTemaYCodigo('tipo_contrato', 'obra_labor');

        $this->assertNotNull($obra);
        $this->assertSame('Obra o labor', $obra->nombre);
        $this->assertFalse($obra->es_defecto);
    }
}