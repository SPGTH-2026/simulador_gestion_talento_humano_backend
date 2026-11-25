<?php

return [
    /*
    | Establecer o cambiar la contraseña desde una sesión abierta (con código al correo de la sesión). Apagado por defecto: las
    | rutas responden 404 hasta que se coordine su publicación. SEGURIDAD_CONTRASENA_SESION=true solo en el entorno de revisión.
    */
    'contrasena_desde_sesion' => (bool) env('SEGURIDAD_CONTRASENA_SESION', false),
];
