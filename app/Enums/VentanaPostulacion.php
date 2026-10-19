<?php

namespace App\Enums;

// Resultado de evaluar si una convocatoria acepta postulaciones HOY (en zona
// America/Bogota). Un solo lugar decide la ventana (Convocatoria::ventanaPostulacion)
// para que el POST y, más adelante, el listado y el retiro usen la misma regla.
enum VentanaPostulacion: string
{
    case Abierta = 'abierta';
    case NoPublicada = 'no_publicada';
    case AntesDeApertura = 'antes_de_apertura';
    case Cerrada = 'cerrada';
}
