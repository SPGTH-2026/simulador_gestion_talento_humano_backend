<?php

// Reglas del módulo de convocatorias. Fuente única para estos valores: cuando
// se implemente la subida de documentos, el módulo debe leerlos de aquí.
return [
    // Formatos de archivo que aceptan las convocatorias (postulaciones).
    // Por ahora NO se implementa subida de archivos; la regla queda definida
    // en un solo lugar para que el módulo de documentos la reutilice.
    // Al validar se debe comprobar extensión, tipo MIME real (no el nombre del
    // archivo) y tamaño, y rechazar con 422 y mensaje claro.
    'formatos_permitidos' => ['pdf'],

    // Tamaño máximo por archivo en MB. Solo se aceptan PDF (formatos_permitidos).
    'tamano_maximo_mb' => 3,

    // Límites de las skills de una convocatoria (texto libre por convocatoria).
    'skills_max' => 20,
    'skill_max_longitud' => 100,

    // Documentos SIEMPRE obligatorios en toda convocatoria: el backend los
    // agrega solos (aunque el gestor no los envíe) y el gestor no puede
    // quitarlos. Se resuelven por CÓDIGO del tema tipo_documento.
    //
    // Cualquier otro documento del catálogo que el gestor incluya se guarda como
    // OBLIGATORIO: ya no hay opcionales "blandos". En particular
    // libreta_militar y tarjeta_profesional: las decide el gestor (si las
    // incluye, se guardan como obligatorias: ver más abajo).
    'documentos_obligatorios' => [
        'hoja_de_vida',
        'documento_identidad',
        'certificados_academicos',   // nombre visible: "Certificados de estudio"
        'certificados_laborales',
    ],

    // Documentos opcionales que el gestor decide incluir (no se agregan solos).
    // Si el gestor los incluye, se guardan como obligatorios (obligatorio=true):
    // tarjeta_profesional solo si el cargo la exige; libreta_militar solo aplica
    // a hombres (ver regla pendiente abajo).
    'documentos_opcionales' => [
        'libreta_militar',
        'tarjeta_profesional',
    ],

    // Checklist predeterminado de cada documento (un ítem por línea): lo que el evaluador marca «Cumple» / «No cumple». Cada
    // convocatoria guarda su propia copia al crearse o editarse (si el gestor no escribe otra), así todos los documentos básicos
    // llevan checklist y el puntaje por ítems funciona igual en todas. Debe coincidir con CHECKLIST_POR_DEFECTO del frontend
    // (lib/documentosConvocatoria.js), que lo precarga en el asistente.
    'checklist_por_defecto' => [
        'hoja_de_vida' => "Datos personales completos (nombre, documento, dirección, teléfono, correo)
Formación académica registrada
Experiencia laboral con cargo y fechas
Firmada",
        'documento_identidad' => "Fotocopia legible
Documento vigente (no vencido)
Datos coinciden con la hoja de vida",
        'certificados_academicos' => "Certificado de bachiller
Certificado de tecnólogo o profesional (si el cargo lo pide)
Certificados de educación informal (si los tiene)
Certificados legibles y con firma o sello de la institución",
        'certificados_laborales' => "Certificación laboral
Indica cargo, funciones y fechas de inicio y fin
Firmada por el empleador, con datos de contacto",
        'libreta_militar' => "Fotocopia legible
Situación militar definida",
        'tarjeta_profesional' => "Fotocopia legible
Vigente (no vencida)",
    ],

    // Límites del paso de documentos de la postulación.
    //  - Máximo de skills que el aspirante declara (solo las que pide la convocatoria).
    'skills_postulacion_max' => 30,

    // Regla de negocio PENDIENTE (NO implementada todavía; queda escrita aquí y
    // en el módulo de documentos para cuando se construya):
    //   - libreta_militar: solo aplica a aspirantes HOMBRES. Si el aspirante es
    //     mujer, el documento queda como "No aplica" y bloqueado. El backend
    //     debe validarlo, no solo el frontend.
    //   - tarjeta_profesional: solo aplica si el cargo la exige.
    //   - El sexo del aspirante se captura en el paso de documentos y se
    //     guardará en una tabla nueva pequeña perfiles_aspirante
    //     (user_id UNIQUE, sexo con códigos hombre/mujer desde parámetros).
    //   - El aspirante NO debe documentos al postularse; solo en el paso de
    //     documentos.
];