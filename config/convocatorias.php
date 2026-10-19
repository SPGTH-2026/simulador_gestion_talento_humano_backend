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

    // Límites del paso de documentos de la postulación.
    //  - Máximo de documentos ADICIONALES ("tipo otro") por postulación.
    //  - Máximo de skills que el aspirante declara (de la convocatoria o propias).
    //  - Longitudes máximas del título y la nota de un adicional.
    'documentos_adicionales_max' => 5,
    'skills_postulacion_max' => 30,
    'titulo_adicional_max_longitud' => 120,
    'nota_adicional_max_longitud' => 500,

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