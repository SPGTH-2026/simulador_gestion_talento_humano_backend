<?php

// Única fuente de verdad de permisos. Debe coincidir con el listado del front (permissions.js).
return [
    'all' => [
        'dashboard:ver',
        'convocatorias:ver', 'convocatorias:gestionar', 'convocatorias:postular',
        'documentos:ver', 'documentos:cargar', 'documentos:validar',
        'evaluacion:ver', 'evaluacion:evaluar',
        'seleccion:ver', 'seleccion:decidir',
        'reportes:ver', 'supervision:gestionar', 'usuarios:gestionar',
        'propio:postulaciones', 'propio:documentos', 'propio:evaluaciones', 'propio:notificaciones',
    ],

    // Permisos EXCLUSIVOS del super admin. El instructor tiene todo lo demás
    // (incluido usuarios:gestionar, propio:* y convocatorias:postular): es el
    // administrador de las personas de sus fichas, pero la supervisión global
    // (ver todo en todas las fichas) queda solo para el super admin.
    'super_admin_only' => [
        'supervision:gestionar',
    ],

    'aspirante' => [
        'dashboard:ver', 'convocatorias:ver', 'convocatorias:postular',
        'propio:postulaciones', 'propio:documentos', 'propio:evaluaciones', 'propio:notificaciones',
    ],

    // Sub-roles del aprendiz
    'aprendiz' => [
        'general' => [
            'dashboard:ver', 'convocatorias:ver', 'convocatorias:gestionar',
            'documentos:ver', 'documentos:cargar', 'documentos:validar',
            'evaluacion:ver', 'evaluacion:evaluar', 'seleccion:ver', 'seleccion:decidir', 'reportes:ver',
        ],
        'evaluador' => ['dashboard:ver', 'evaluacion:ver', 'evaluacion:evaluar', 'reportes:ver'],
        'seleccionador' => ['dashboard:ver', 'seleccion:ver', 'seleccion:decidir', 'reportes:ver'],
        'revisor_documental' => ['dashboard:ver', 'documentos:ver', 'documentos:cargar', 'documentos:validar', 'reportes:ver'],
        'gestor_convocatorias' => ['dashboard:ver', 'convocatorias:ver', 'convocatorias:gestionar', 'reportes:ver'],
    ],
    // El super admin recibe 'all'; el instructor recibe 'all' menos lo exclusivo
    // (ver User::permissions()).
];
