<?php

namespace App\Enums;

// Motivos de aviso. El código se guarda en la fila (`notificaciones.tipo`): no cambiar un valor existente sin migrar los
// datos. Quién recibe cada uno lo decide Avisos (ver su docblock).
enum TipoNotificacion: string
{
    // El aspirante envió su postulación: confirmación para él y aviso de postulación nueva para el personal de la ficha.
    case PostulacionEnviada = 'postulacion_enviada';

    // El aspirante retiró su postulación.
    case PostulacionRetirada = 'postulacion_retirada';

    // El evaluador pidió corregir documentos: el aspirante tiene un plazo para responder.
    case CorreccionSolicitada = 'correccion_solicitada';

    // El aspirante respondió la corrección: el evaluador que la pidió y el instructor deben revisarla.
    case CorreccionRespondida = 'correccion_respondida';

    // Una evaluación pasó a finalizada: el aspirante puede consultar su resultado en «Mis evaluaciones».
    case EvaluacionFinalizada = 'evaluacion_finalizada';

    // Una evaluación finalizada aprobó el umbral: el aspirante queda disponible para selección.
    case PasaSeleccion = 'pasa_seleccion';

    // Selección programó, reprogramó o canceló una cita (entrevista o prueba técnica): el aspirante la consulta en «Mis postulaciones».
    case Citacion = 'citacion';

    // El super admin convirtió la cuenta en instructor y todavía no tiene ninguna ficha: debe crear la suya.
    case InstructorCrearFicha = 'instructor_crear_ficha';

    // Estados de la convocatoria, vistos por el aspirante.
    case ConvocatoriaPublicada = 'convocatoria_publicada';
    case ConvocatoriaRecepcionCerrada = 'convocatoria_recepcion_cerrada';
    case ConvocatoriaCerrada = 'convocatoria_cerrada';

    // Resumen diario de pendientes (lo calcula ResumenPendientes; no lo dispara un evento).
    case PendientesEvaluar = 'pendientes_evaluar';
    case PendientesSeleccion = 'pendientes_seleccion';
    case ConvocatoriasPorCerrar = 'convocatorias_por_cerrar';
    case BorradorPendiente = 'borrador_pendiente';
    case CorreccionPorVencer = 'correccion_por_vencer';
    case CierreProximo = 'cierre_proximo';
}
