<?php

// Única fuente de verdad de permisos. Debe coincidir con el listado del front (permissions.js).
return [
    'all' => [
        'dashboard:ver',
        'convocatorias:ver', 'convocatorias:gestionar', 'convocatorias:postular',
        'documentos:ver',
        'evaluacion:ver', 'evaluacion:evaluar',
        'seleccion:ver', 'seleccion:decidir',
        'reportes:ver', 'usuarios:gestionar',
        // Almacenamiento: uso de la base y de los documentos por ficha, y borrado de documentos. Solo super admin e
        // instructor (y el instructor solo ve y borra en sus fichas: ver AlmacenamientoController).
        'almacenamiento:ver', 'almacenamiento:gestionar',
        'propio:postulaciones', 'propio:documentos', 'propio:evaluaciones', 'propio:notificaciones',
    ],

    // Permisos EXCLUSIVOS del super admin. Hoy no hay ninguno: el instructor tiene todo lo
    // demás (incluido usuarios:gestionar, propio:* y convocatorias:postular).
    'super_admin_only' => [],

    'aspirante' => [
        'dashboard:ver', 'convocatorias:ver', 'convocatorias:postular',
        'propio:postulaciones', 'propio:documentos', 'propio:evaluaciones', 'propio:notificaciones',
    ],

    // Sub-roles del aprendiz
    'aprendiz' => [
        'general' => [
            'dashboard:ver', 'convocatorias:ver', 'convocatorias:gestionar',
            'documentos:ver',
            'evaluacion:ver', 'evaluacion:evaluar', 'seleccion:ver', 'seleccion:decidir', 'reportes:ver',
            'propio:evaluaciones', 'propio:notificaciones',
            // Solo lectura: ve el almacenamiento de su ficha, sin borrar (no tiene almacenamiento:gestionar).
            'almacenamiento:ver',
        ],
        'evaluador' => [
            'dashboard:ver', 'documentos:ver', 'evaluacion:ver', 'evaluacion:evaluar', 'reportes:ver',
            'propio:evaluaciones', 'propio:notificaciones',
        ],
        'seleccionador' => ['dashboard:ver', 'seleccion:ver', 'seleccion:decidir', 'reportes:ver', 'propio:notificaciones'],
        'gestor_convocatorias' => ['dashboard:ver', 'convocatorias:ver', 'convocatorias:gestionar', 'reportes:ver', 'propio:notificaciones'],
    ],
    // El super admin recibe 'all'; el instructor recibe 'all' menos lo exclusivo
    // (ver User::permissions()).
];
