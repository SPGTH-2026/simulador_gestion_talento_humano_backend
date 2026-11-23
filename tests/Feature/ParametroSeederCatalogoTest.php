<?php

namespace Tests\Feature;

use App\Models\Parametro;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ParametroSeederAyudas;
use Tests\TestCase;

class ParametroSeederCatalogoTest extends TestCase
{
    use RefreshDatabase;
    use ParametroSeederAyudas;

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

        // skill_catalogo: 10 skills con código estable (para declararlas por id).
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
            'redaccion_informes',
        ], $catalogo->pluck('codigo')->all());

        $this->assertSame('Comunicación asertiva', Parametro::porTemaYCodigo('skill_catalogo', 'comunicacion_asertiva')->nombre);
        $this->assertSame('Atención al cliente', Parametro::porTemaYCodigo('skill_catalogo', 'atencion_al_cliente')->nombre);
        $this->assertSame('Redacción de informes', Parametro::porTemaYCodigo('skill_catalogo', 'redaccion_informes')->nombre);
    }

    public function test_las_skills_retiradas_ya_no_se_siembran(): void
    {
        $this->ejecutarSeeder();

        // Las 10 que quedan existen con su código.
        $vigentes = [
            'comunicacion_asertiva',
            'trabajo_en_equipo',
            'liderazgo',
            'resolucion_problemas',
            'gestion_del_tiempo',
            'atencion_al_cliente',
            'adaptabilidad',
            'excel',
            'word',
            'redaccion_informes',
        ];

        foreach ($vigentes as $codigo) {
            $this->assertNotNull(
                Parametro::porTemaYCodigo('skill_catalogo', $codigo),
                "La skill {$codigo} debe seguir en el catálogo."
            );
        }

        // Las 9 retiradas ya no se siembran.
        $retiradas = [
            'powerpoint',
            'ingles',
            'sql_bases_de_datos',
            'analisis_de_datos',
            'contabilidad_basica',
            'manejo_nomina',
            'seguridad_salud_trabajo',
            'manejo_inventarios',
            'gestion_documental',
        ];

        foreach ($retiradas as $codigo) {
            $this->assertNull(
                Parametro::porTemaYCodigo('skill_catalogo', $codigo),
                "La skill {$codigo} ya no debe estar en el catálogo."
            );
        }

        $this->assertSame(
            10,
            Parametro::whereHas('tema', fn ($q) => $q->where('codigo', 'skill_catalogo'))->count()
        );
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
