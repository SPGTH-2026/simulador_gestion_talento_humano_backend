<?php

// Capacidad contra la que se calcula el porcentaje de uso. Los valores por defecto son los de los planes gratuitos:
//  - Base de datos (Supabase, plan gratis): 500 MB.
//  - Documentos (Cloudflare R2, plan gratis): 10 GB.
// Si el plan cambia, se ajusta con las variables de entorno, sin tocar el código.
return [
    'limite_base_mb' => (float) env('ALMACENAMIENTO_LIMITE_BASE_MB', 500),
    'limite_documentos_mb' => (float) env('ALMACENAMIENTO_LIMITE_DOCUMENTOS_MB', 10240),
];
