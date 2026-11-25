<?php

return [
    /*
    | DESACTIVADA por defecto. Mientras `NOTIFICACIONES_DEPURACION_ACTIVA` no sea `true` el comando no borra nada y el scheduler no lo
    | programa: ninguna notificación se elimina. Activarla es una decisión de conservación de datos que se toma al desplegar.
    */
    'depuracion_activa' => (bool) env('NOTIFICACIONES_DEPURACION_ACTIVA', false),

    /*
    | Retención de notificaciones (solo si la depuración está activa). El comando `notificaciones:depurar` (diario) elimina DEFINITIVAMENTE:
    |   - las ocultas, a los N días de haberlas ocultado (`ocultada_at`);
    |   - las leídas que no están ocultas, a los N días de haberlas leído (`leida_at`).
    | Las NO leídas y no ocultas no se borran nunca. Una oculta se rige solo por la fecha en que se ocultó (restaurarla antes
    | reinicia el reloj de las leídas, no el de las ocultas).
    */
    'dias_ocultas' => (int) env('NOTIFICACIONES_DIAS_OCULTAS', 30),
    'dias_leidas' => (int) env('NOTIFICACIONES_DIAS_LEIDAS', 30),
];
