<?php

return [
    /*
    | Estructura de versiones de textos legales y aceptaciones. Apagada por defecto: las rutas responden 404 y nada del sistema
    | pide aceptar nada. LEGAL_ACEPTACIONES_ACTIVAS=true solo en un entorno de revisión; activar casillas, bloqueo de registro o
    | reaceptación es otra decisión (jurídica y de producto) que no depende de esta bandera.
    */
    'aceptaciones_activas' => (bool) env('LEGAL_ACEPTACIONES_ACTIVAS', false),
];
