<?php

namespace Database\Seeders;

use App\Models\Parametro;
use App\Models\ParametroTema;
use Illuminate\Database\Seeder;

class ParametroSeeder extends Seeder
{
    /**
     * Catálogo de temas y parámetros. Idempotente (updateOrCreate) porque en
     * Render se ejecutará en cada arranque. NUNCA borra ni desactiva nada, y
     * los parámetros es_sistema conservan su codigo y quedan siempre activos.
     *
     * 10 temas / 43 parámetros:
     *  - estado_convocatoria (4, es_sistema): borrador, publicada, en_proceso, cerrada
     *  - estado_postulacion (3, es_sistema): borrador, postulada, retirada
     *  - tipo_convocatoria (2): interno, externo (es_defecto)
     *  - tipo_empleo (3): tiempo_completo (es_defecto), medio_tiempo, por_horas
     *  - tipo_contrato (4): termino_fijo (es_defecto), termino_indefinido, obra_labor, prestacion_servicios
     *  - modalidad (3): presencial (es_defecto), remoto, hibrido
     *  - sexo (2): hombre, mujer (para el perfil del aspirante)
     *  - skill_tecnica (6): sugerencias para el campo de skills de texto libre
     *  - skill_catalogo (10): catálogo general de skills (blandas + técnicas) para
     *    el paso de la postulación; se resuelven por código y traen el `codigo`
     *    del parámetro para poder declararlas por `parametro_id`. Las 9 skills
     *    retiradas del catálogo ya NO se siembran (ver comentario del tema).
     *  - tipo_documento (6): hoja_de_vida y certificados_laborales (es_sistema).
     *    Cuáles son obligatorios en toda convocatoria se define en
     *    config/convocatorias.php (no aquí). Los 3 documentos retirados del
     *    catálogo (titulos, soportes_laborales, certificacion_competencias) NO
     *    se crean aquí: se desactivan con la migración
     *    2026_10_17_000001_retirar_documentos_del_catalogo (historial).
     */
    public function run(): void
    {
        $temas = [
            'estado_convocatoria' => ['nombre' => 'Estado de convocatoria', 'parametros' => [
                ['codigo' => 'borrador', 'nombre' => 'Borrador', 'orden' => 1, 'es_sistema' => true],
                ['codigo' => 'publicada', 'nombre' => 'Publicada', 'orden' => 2, 'es_sistema' => true],
                ['codigo' => 'en_proceso', 'nombre' => 'En proceso', 'orden' => 3, 'es_sistema' => true],
                ['codigo' => 'cerrada', 'nombre' => 'Cerrada', 'orden' => 4, 'es_sistema' => true],
            ]],
            'estado_postulacion' => ['nombre' => 'Estado de postulación', 'parametros' => [
                // Se resuelven por CÓDIGO (updateOrCreate tema_id+codigo): agregar
                // 'borrador' y reordenar NO cambia los ids de postulada/retirada.
                ['codigo' => 'borrador', 'nombre' => 'Borrador', 'orden' => 1, 'es_sistema' => true],
                ['codigo' => 'postulada', 'nombre' => 'Postulada', 'orden' => 2, 'es_sistema' => true],
                ['codigo' => 'retirada', 'nombre' => 'Retirada', 'orden' => 3, 'es_sistema' => true],
            ]],
            'tipo_convocatoria' => ['nombre' => 'Tipo de convocatoria', 'parametros' => [
                ['codigo' => 'interno', 'nombre' => 'Interno', 'orden' => 1],
                ['codigo' => 'externo', 'nombre' => 'Externo', 'orden' => 2, 'es_defecto' => true],
            ]],
            'tipo_empleo' => ['nombre' => 'Tipo de empleo', 'parametros' => [
                ['codigo' => 'tiempo_completo', 'nombre' => 'Tiempo completo', 'orden' => 1, 'es_defecto' => true],
                ['codigo' => 'medio_tiempo', 'nombre' => 'Medio tiempo', 'orden' => 2],
                ['codigo' => 'por_horas', 'nombre' => 'Por horas', 'orden' => 3],
            ]],
            'tipo_contrato' => ['nombre' => 'Tipo de contrato', 'parametros' => [
                ['codigo' => 'termino_fijo', 'nombre' => 'Término fijo', 'orden' => 1, 'es_defecto' => true],
                ['codigo' => 'termino_indefinido', 'nombre' => 'Término indefinido', 'orden' => 2],
                ['codigo' => 'obra_labor', 'nombre' => 'Obra o labor', 'orden' => 3],
                ['codigo' => 'prestacion_servicios', 'nombre' => 'Prestación de servicios', 'orden' => 4],
            ]],
            'modalidad' => ['nombre' => 'Modalidad', 'parametros' => [
                ['codigo' => 'presencial', 'nombre' => 'Presencial', 'orden' => 1, 'es_defecto' => true],
                ['codigo' => 'remoto', 'nombre' => 'Remoto', 'orden' => 2],
                ['codigo' => 'hibrido', 'nombre' => 'Híbrido', 'orden' => 3],
            ]],
            'skill_tecnica' => ['nombre' => 'Skill técnica', 'parametros' => [
                ['nombre' => 'Excel avanzado', 'orden' => 1],
                ['nombre' => 'Power BI', 'orden' => 2],
                ['nombre' => 'SAP HR', 'orden' => 3],
                ['nombre' => 'Legislación laboral', 'orden' => 4],
                ['nombre' => 'Selección de personal', 'orden' => 5],
                ['nombre' => 'Entrevistas por competencias', 'orden' => 6],
            ]],
            // Catálogo general de skills del aspirante (Paso 4). Cada una lleva
            // código estable para poder declararla por `parametro_id` y para que
            // el seeder sea idempotente sin tocar ids existentes.
            //
            // Solo 10 skills, válidas para cualquier convocatoria sin importar el
            // cargo. Se retiraron del catálogo (ya no se siembran): powerpoint,
            // ingles, sql_bases_de_datos, analisis_de_datos, contabilidad_basica,
            // manejo_nomina, seguridad_salud_trabajo, manejo_inventarios y
            // gestion_documental. Las 10 que quedan conservan su codigo y su id.
            'skill_catalogo' => ['nombre' => 'Catálogo de skills', 'parametros' => [
                // Blandas.
                ['codigo' => 'comunicacion_asertiva', 'nombre' => 'Comunicación asertiva', 'orden' => 1],
                ['codigo' => 'trabajo_en_equipo', 'nombre' => 'Trabajo en equipo', 'orden' => 2],
                ['codigo' => 'liderazgo', 'nombre' => 'Liderazgo', 'orden' => 3],
                ['codigo' => 'resolucion_problemas', 'nombre' => 'Resolución de problemas', 'orden' => 4],
                ['codigo' => 'gestion_del_tiempo', 'nombre' => 'Gestión del tiempo', 'orden' => 5],
                ['codigo' => 'atencion_al_cliente', 'nombre' => 'Atención al cliente', 'orden' => 6],
                ['codigo' => 'adaptabilidad', 'nombre' => 'Adaptabilidad', 'orden' => 7],
                // Técnicas.
                ['codigo' => 'excel', 'nombre' => 'Excel', 'orden' => 8],
                ['codigo' => 'word', 'nombre' => 'Word', 'orden' => 9],
                ['codigo' => 'redaccion_informes', 'nombre' => 'Redacción de informes', 'orden' => 10],
            ]],
            'sexo' => ['nombre' => 'Sexo', 'parametros' => [
                ['codigo' => 'hombre', 'nombre' => 'Masculino', 'orden' => 1],
                ['codigo' => 'mujer', 'nombre' => 'Femenino', 'orden' => 2],
            ]],
            'tipo_documento' => ['nombre' => 'Tipo de documento', 'parametros' => [
                ['codigo' => 'documento_identidad', 'nombre' => 'Documento de identidad', 'orden' => 1],
                ['codigo' => 'hoja_de_vida', 'nombre' => 'Hoja de vida', 'orden' => 2, 'es_sistema' => true],
                ['codigo' => 'certificados_academicos', 'nombre' => 'Certificados de estudio', 'orden' => 3],
                ['codigo' => 'certificados_laborales', 'nombre' => 'Certificados laborales', 'orden' => 4, 'es_sistema' => true],
                ['codigo' => 'libreta_militar', 'nombre' => 'Libreta militar', 'orden' => 5],
                ['codigo' => 'tarjeta_profesional', 'nombre' => 'Tarjeta profesional', 'orden' => 6],
            ]],

        ];

        foreach ($temas as $codigoTema => $datos) {
            $tema = ParametroTema::updateOrCreate(
                ['codigo' => $codigoTema],
                ['nombre' => $datos['nombre'], 'activo' => true],
            );

            foreach ($datos['parametros'] as $p) {
                $esSistema = (bool) ($p['es_sistema'] ?? false);

                if ($esSistema) {
                    // Clave por codigo: el codigo de los de sistema es estable y
                    // nunca cambia; además quedan siempre activos y es_sistema.
                    Parametro::updateOrCreate(
                        ['tema_id' => $tema->id, 'codigo' => $p['codigo']],
                        [
                            'nombre' => $p['nombre'],
                            'orden' => $p['orden'],
                            'activo' => true,
                            'es_sistema' => true,
                            'es_defecto' => false,
                        ],
                    );
                } else {
                    // Clave por codigo cuando lo tiene (estable entre entornos);
                    // si no, por nombre (los skill_tecnica no llevan codigo). Así,
                    // renombrar un parámetro con codigo (p. ej.
                    // certificados_academicos → "Certificados de estudio")
                    // actualiza la fila y NO crea una duplicada.
                    // No se toca es_sistema, para no "subir" nada a sistema.
                    $clave = ! empty($p['codigo'])
                        ? ['tema_id' => $tema->id, 'codigo' => $p['codigo']]
                        : ['tema_id' => $tema->id, 'nombre' => $p['nombre']];

                    Parametro::updateOrCreate(
                        $clave,
                        [
                            'nombre' => $p['nombre'],
                            'codigo' => $p['codigo'] ?? null,
                            'orden' => $p['orden'],
                            'activo' => true,
                            'es_defecto' => (bool) ($p['es_defecto'] ?? false),
                        ],
                    );
                }
            }
        }
    }
}