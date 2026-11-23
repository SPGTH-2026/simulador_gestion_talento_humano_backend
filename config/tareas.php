<?php

return [
    // Token secreto para que un disparador externo (GitHub Actions) ejecute tareas programadas. Sin este valor el
    // endpoint responde 404. Debe ser largo y aleatorio; nunca va en el repositorio.
    'cron_token' => env('CRON_TOKEN'),
];
