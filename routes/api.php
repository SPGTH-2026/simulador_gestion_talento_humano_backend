<?php

use App\Http\Controllers\Api\AlmacenamientoController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CalificacionSeleccionController;
use App\Http\Controllers\Api\ComparacionSeleccionController;
use App\Http\Controllers\Api\ConfiguracionSeleccionController;
use App\Http\Controllers\Api\ConvocatoriaController;
use App\Http\Controllers\Api\ListadoConvocatoriasController;
use App\Http\Controllers\Api\TareasProgramadasController;
use App\Http\Controllers\Api\DecisionSeleccionController;
use App\Http\Controllers\Api\EvaluacionController;
use App\Http\Controllers\Api\EvaluacionAspiranteController;
use App\Http\Controllers\Api\EvaluacionCorreccionController;
use App\Http\Controllers\Api\EvaluacionListadoController;
use App\Http\Controllers\Api\FichaController;
use App\Http\Controllers\Api\FinalistasSeleccionController;
use App\Http\Controllers\Api\NotificacionController;
use App\Http\Controllers\Api\ParametroController;
use App\Http\Controllers\Api\PerfilAspiranteController;
use App\Http\Controllers\Api\PortafolioController;
use App\Http\Controllers\Api\PostulacionController;
use App\Http\Controllers\Api\PostulacionEstadoController;
use App\Http\Controllers\Api\PostulacionDocumentoController;
use App\Http\Controllers\Api\PostulacionSkillController;
use App\Http\Controllers\Api\RankingSeleccionController;
use App\Http\Controllers\Api\ReferenciaDocumentalController;
use App\Http\Controllers\Api\SeleccionController;
use App\Http\Controllers\Api\SocialAuthController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('register', [AuthController::class, 'register'])->middleware('throttle:login');
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login');

    // Recuperar contraseña: públicas (no hay sesión todavía).
    Route::post('forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:otp-send');
    Route::post('reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:otp-check');

    // OAuth: el callback llega desde accounts.google.com, no desde el front.
    // Sanctum statefulApi() solo da sesión si el request viene de un dominio
    // stateful, así que aquí se fuerza 'web' para poder iniciar sesión.
    Route::get('{provider}/redirect', [SocialAuthController::class, 'redirect'])->middleware('web');
    Route::get('{provider}/callback', [SocialAuthController::class, 'callback'])->middleware('web');
    // Aspirante que entró con Google y todavía no eligió su ficha: la cuenta se crea al elegirla.
    Route::get('google/pendiente', [SocialAuthController::class, 'pendiente'])->middleware('web');
    Route::post('google/pendiente/ficha', [SocialAuthController::class, 'completarPendiente'])
        ->middleware(['web', 'throttle:login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('me', [AuthController::class, 'me']);
        Route::post('logout', [AuthController::class, 'logout']);

        // Confirmar correo: requieren sesión pero NO 'verified' (si no, no se podría confirmar).
        Route::post('verification/send', [AuthController::class, 'sendVerification'])->middleware('throttle:otp-send');
        Route::post('verification/confirm', [AuthController::class, 'confirmVerification'])->middleware('throttle:otp-check');

        // El aspirante se asocia a su ficha (Google o registro por correo).
        // Exige correo verificado: es una acción de negocio, no de autenticación.
        Route::middleware('verified')->post('ficha', [AuthController::class, 'guardarFicha']);
    });
});

// ── Usuarios y fichas ──────────────────────────────────────────────────
// Lo tienen el super admin y el instructor (usuarios:gestionar). 'verified'
// exige correo confirmado, igual que el resto de rutas de negocio.
Route::middleware(['auth:sanctum', 'verified', 'ficha', 'can:usuarios:gestionar'])->group(function () {
    Route::get('usuarios', [UserController::class, 'index']);
    Route::patch('usuarios/{user}', [UserController::class, 'update']);

    // Fichas: el listado alimenta los selectores (misma forma de respuesta:
    // { data: [ { id, codigo, nombre_programa, estado } ] }). El alcance por
    // ficha y la propiedad (creador) los aplica el controlador: fuera de
    // alcance -> 404. Asignar/quitar instructor es solo del super admin.
    Route::get('fichas', [FichaController::class, 'index']);
    Route::post('fichas', [FichaController::class, 'store']);
    Route::get('fichas/{ficha}', [FichaController::class, 'show'])
        ->whereNumber('ficha')
        ->missing(fn () => abort(404, 'Ficha no encontrada.'));
    Route::patch('fichas/{ficha}', [FichaController::class, 'update'])
        ->whereNumber('ficha')
        ->missing(fn () => abort(404, 'Ficha no encontrada.'));
    Route::delete('fichas/{ficha}', [FichaController::class, 'destroy'])
        ->whereNumber('ficha')
        ->missing(fn () => abort(404, 'Ficha no encontrada.'));
    Route::post('fichas/{ficha}/instructores', [FichaController::class, 'asignarInstructor'])
        ->whereNumber('ficha')
        ->missing(fn () => abort(404, 'Ficha no encontrada.'));
    Route::delete('fichas/{ficha}/instructores', [FichaController::class, 'quitarInstructor'])
        ->whereNumber('ficha')
        ->missing(fn () => abort(404, 'Ficha no encontrada.'));
});

// ── Convocatorias y parámetros ─────────────────────────────────────────
// 'ver' para aspirante/gestores visualizar; 'gestionar' para crear, editar y
// cambiar estado. El selector de fichas del formulario es GET /api/fichas
// (FichaController:index): GET /api/convocatorias/fichas se retiró en el Paso 2b.
Route::middleware(['auth:sanctum', 'verified', 'ficha'])->group(function () {
    Route::get('parametros', [ParametroController::class, 'index'])->middleware('can:convocatorias:ver');
    // Documento NUEVO del catálogo (especialización, maestría, etc.) para
    // sumarlo a una convocatoria. Solo quien gestiona convocatorias.
    Route::post('parametros/documentos', [ParametroController::class, 'storeDocumento'])
        ->middleware('can:convocatorias:gestionar');

    Route::get('convocatorias', [ListadoConvocatoriasController::class, 'index'])->middleware('can:convocatorias:ver');
    Route::post('convocatorias', [ConvocatoriaController::class, 'store'])->middleware('can:convocatorias:gestionar');
    Route::get('convocatorias/{convocatoria}', [ConvocatoriaController::class, 'show'])
        ->whereNumber('convocatoria')->middleware('can:convocatorias:ver');
    Route::patch('convocatorias/{convocatoria}', [ConvocatoriaController::class, 'update'])
        ->whereNumber('convocatoria')->middleware('can:convocatorias:gestionar');
    Route::patch('convocatorias/{convocatoria}/estado', [ConvocatoriaController::class, 'estado'])
        ->whereNumber('convocatoria')->middleware('can:convocatorias:gestionar');

    // El aspirante se postula. El permiso deja pasar a aspirante/instructor/super
    // admin (el aprendiz no lo tiene); el controlador responde 403 a instructor y
    // super admin. Una convocatoria inexistente responde el mismo 404 en español.
    Route::post('convocatorias/{convocatoria}/postulaciones', [PostulacionController::class, 'store'])
        ->whereNumber('convocatoria')
        ->missing(fn () => abort(404, 'Convocatoria no encontrada.'))
        ->middleware('can:convocatorias:postular');

    // ── Mis postulaciones (Paso 3) ────────────────────────────────────────
    // 'propio:postulaciones' lo tienen super admin, instructor y aspirante:
    // cada quien ve y cambia SOLO las suyas (el filtro por user_id va en el
    // controlador). Una postulación ajena o inexistente -> 404 en español.
    Route::get('postulaciones', [PostulacionController::class, 'index'])
        ->middleware('can:propio:postulaciones');

    Route::patch('postulaciones/{postulacion}/estado', [PostulacionEstadoController::class, 'estado'])
        ->whereNumber('postulacion')
        ->missing(fn () => abort(404, 'Postulación no encontrada.'))
        ->middleware('can:propio:postulaciones');

    // Envío del borrador: valida los documentos obligatorios y pasa a
    // 'postulada'. 'postulada' ya NO se alcanza con el PATCH anterior.
    Route::post('postulaciones/{postulacion}/enviar', [PostulacionEstadoController::class, 'enviar'])
        ->whereNumber('postulacion')
        ->missing(fn () => abort(404, 'Postulación no encontrada.'))
        ->middleware('can:propio:postulaciones');

    // ── Almacenamiento: uso (base y R2 por ficha) y documentos por ficha ──
    // Solo super admin e instructor. El alcance por ficha lo aplica el controlador.
    Route::middleware('can:almacenamiento:ver')->prefix('almacenamiento')->group(function () {
        Route::get('resumen', [AlmacenamientoController::class, 'resumen']);
        Route::get('fichas/{ficha}/documentos', [AlmacenamientoController::class, 'documentos'])->whereNumber('ficha');
    });
    Route::middleware('can:almacenamiento:gestionar')->prefix('almacenamiento')->group(function () {
        Route::post('documentos/borrar', [AlmacenamientoController::class, 'borrar']);
    });

    // ── Notificaciones (campana) ─────────────────────────────────────────
    // Cada usuario ve solo las suyas: el controlador filtra por el autenticado.
    Route::middleware('can:propio:notificaciones')->group(function () {
        Route::get('notificaciones', [NotificacionController::class, 'index']);
        Route::post('notificaciones/leer-todas', [NotificacionController::class, 'leerTodas']);
        Route::post('notificaciones/{notificacion}/leer', [NotificacionController::class, 'leer'])
            ->whereNumber('notificacion');
    });

    // ── Documentos y skills de la postulación (Paso 4) ────────────────────
    // 'propio:documentos' lo tienen aspirante, instructor y super admin: cada
    // quien edita SOLO lo suyo (filtro por user_id en el controlador). Una
    // postulación ajena o inexistente -> 404 en español. Estos pasos se editan
    // con la convocatoria publicada y dentro de la ventana, en 'borrador',
    // 'postulada' o 'retirada'.
    Route::middleware('can:propio:documentos')->group(function () {
        // Perfil mínimo del aspirante (sexo), prellenado entre postulaciones.
        // Elegir el sexo NO crea postulación: es del perfil del usuario.
        Route::get('perfil', [PerfilAspiranteController::class, 'show']);
        Route::put('perfil/sexo', [PerfilAspiranteController::class, 'sexo']);

        Route::get('postulaciones/{postulacion}/documentos', [PostulacionDocumentoController::class, 'show'])
            ->whereNumber('postulacion')
            ->missing(fn () => abort(404, 'Postulación no encontrada.'));
        Route::post('postulaciones/{postulacion}/documentos', [PostulacionDocumentoController::class, 'store'])
            ->whereNumber('postulacion')
            ->missing(fn () => abort(404, 'Postulación no encontrada.'));
        Route::post('postulaciones/{postulacion}/documentos/adicionales', [PostulacionDocumentoController::class, 'storeAdicional'])
            ->whereNumber('postulacion')
            ->missing(fn () => abort(404, 'Postulación no encontrada.'));
        Route::delete('postulaciones/{postulacion}/documentos/{documento}', [PostulacionDocumentoController::class, 'destroy'])
            ->whereNumber('postulacion')->whereNumber('documento')
            ->missing(fn () => abort(404, 'Postulación no encontrada.'));
        Route::get('postulaciones/{postulacion}/documentos/{documento}/archivo', [PostulacionDocumentoController::class, 'archivo'])
            ->whereNumber('postulacion')->whereNumber('documento')
            ->missing(fn () => abort(404, 'Postulación no encontrada.'));

        Route::post('postulaciones/{postulacion}/skills', [PostulacionSkillController::class, 'store'])
            ->whereNumber('postulacion')
            ->missing(fn () => abort(404, 'Postulación no encontrada.'));
        Route::delete('postulaciones/{postulacion}/skills/{skill}', [PostulacionSkillController::class, 'destroy'])
            ->whereNumber('postulacion')->whereNumber('skill')
            ->missing(fn () => abort(404, 'Postulación no encontrada.'));
        Route::post('postulaciones/{postulacion}/skills/{skill}/certificado', [PostulacionSkillController::class, 'certificado'])
            ->whereNumber('postulacion')->whereNumber('skill')
            ->missing(fn () => abort(404, 'Postulación no encontrada.'));
        Route::delete('postulaciones/{postulacion}/skills/{skill}/certificado', [PostulacionSkillController::class, 'quitarCertificado'])
            ->whereNumber('postulacion')->whereNumber('skill')
            ->missing(fn () => abort(404, 'Postulación no encontrada.'));
        Route::get('postulaciones/{postulacion}/skills/{skill}/certificado/archivo', [PostulacionSkillController::class, 'certificadoArchivo'])
            ->whereNumber('postulacion')->whereNumber('skill')
            ->missing(fn () => abort(404, 'Postulación no encontrada.'));
    });

    // Revisores (permiso 'documentos:ver'): solo LECTURA de postulaciones ajenas,
    // con alcance por ficha (fuera de su ficha -> 404, sin revelar existencia).
    Route::middleware('can:documentos:ver')->prefix('revision')->group(function () {
        Route::get('postulaciones/{postulacion}/documentos', [PostulacionDocumentoController::class, 'showRevision'])
            ->whereNumber('postulacion')
            ->missing(fn () => abort(404, 'Postulación no encontrada.'));
        Route::get('postulaciones/{postulacion}/documentos/{documento}/archivo', [PostulacionDocumentoController::class, 'archivoRevision'])
            ->whereNumber('postulacion')->whereNumber('documento')
            ->missing(fn () => abort(404, 'Postulación no encontrada.'));
        Route::get('postulaciones/{postulacion}/skills/{skill}/certificado/archivo', [PostulacionSkillController::class, 'certificadoRevision'])
            ->whereNumber('postulacion')->whereNumber('skill')
            ->missing(fn () => abort(404, 'Postulación no encontrada.'));
    });

    // Portafolio documental de un usuario (permiso 'documentos:ver'): sus
    // postulaciones y el estado de sus documentos. Alcance por ficha dentro del
    // controlador (fuera de su ficha -> 404, sin revelar existencia).
    Route::get('portafolios/{user}', [PortafolioController::class, 'show'])
        ->middleware('can:documentos:ver')
        ->whereNumber('user')
        ->missing(fn () => abort(404, 'Portafolio no encontrado.'));

    // ── Módulo de Evaluación ──────────────────────────────────────────
    Route::get('evaluaciones', [EvaluacionListadoController::class, 'index'])->middleware('can:evaluacion:ver');
    Route::get('evaluaciones/{postulacion}', [EvaluacionController::class, 'show'])->middleware('can:evaluacion:ver');
    Route::put('evaluaciones/{postulacion}', [EvaluacionController::class, 'upsert'])->middleware('can:evaluacion:evaluar');
    // Autorización excepcional de una ronda de corrección (Instructor en sus fichas, Superadmin en todas).
    Route::post('evaluaciones/{postulacion}/correcciones/autorizacion', [EvaluacionCorreccionController::class, 'autorizarReapertura'])
        ->middleware('can:evaluacion:evaluar')->whereNumber('postulacion');

    Route::get('mis-evaluaciones', [EvaluacionAspiranteController::class, 'misEvaluaciones'])->middleware('can:propio:evaluaciones');
    Route::post('postulaciones/{postulacion}/correccion/enviar', [EvaluacionAspiranteController::class, 'enviarCorreccion'])
        ->middleware('can:propio:postulaciones')
        ->whereNumber('postulacion');

    // ── Módulo de Selección y Preselección (etapa 1, solo lectura) ─────
    // Fuera del alcance de fichas del actor, la convocatoria responde 404.
    Route::get('seleccion/convocatorias', [SeleccionController::class, 'convocatorias'])
        ->middleware('can:seleccion:ver');
    Route::get('seleccion/convocatorias/{convocatoria}/candidatos', [SeleccionController::class, 'candidatos'])
        ->whereNumber('convocatoria')
        ->missing(fn () => abort(404, 'Convocatoria no encontrada.'))
        ->middleware('can:seleccion:ver');

    // Etapa 3: configuración de entrevista y prueba técnica por convocatoria. La
    // escritura sigue la matriz de Selección (seleccion:decidir), sin restringir el rol.
    Route::get('seleccion/convocatorias/{convocatoria}/configuracion', [ConfiguracionSeleccionController::class, 'show'])
        ->whereNumber('convocatoria')
        ->middleware('can:seleccion:ver');
    Route::put('seleccion/convocatorias/{convocatoria}/configuracion', [ConfiguracionSeleccionController::class, 'update'])
        ->whereNumber('convocatoria')
        ->middleware('can:seleccion:decidir');

    // Etapa 2: evaluación documental de referencia. Decide solo Instructor o
    // Superadministrador (el rol se exige en el controlador, además del permiso).
    Route::get('seleccion/convocatorias/{convocatoria}/candidatos/{postulacion}/referencia-documental', [ReferenciaDocumentalController::class, 'show'])
        ->whereNumber(['convocatoria', 'postulacion'])
        ->middleware('can:seleccion:decidir');
    Route::post('seleccion/convocatorias/{convocatoria}/candidatos/{postulacion}/referencia-documental', [ReferenciaDocumentalController::class, 'store'])
        ->whereNumber(['convocatoria', 'postulacion'])
        ->middleware('can:seleccion:decidir');

    // Etapa 4: calificaciones presenciales. Consultar pide seleccion:ver; registrar o corregir,
    // seleccion:decidir. Las reglas de estado y habilitación se validan en el controlador.
    Route::get('seleccion/convocatorias/{convocatoria}/candidatos/{postulacion}/calificaciones', [CalificacionSeleccionController::class, 'show'])
        ->whereNumber(['convocatoria', 'postulacion'])
        ->middleware('can:seleccion:ver');
    Route::put('seleccion/convocatorias/{convocatoria}/candidatos/{postulacion}/calificaciones/{actividad}', [CalificacionSeleccionController::class, 'update'])
        ->whereNumber(['convocatoria', 'postulacion'])
        ->whereIn('actividad', ['entrevista', 'prueba_tecnica'])
        ->middleware('can:seleccion:decidir');

    // Etapa 5: ranking de candidatos listos y finalistas. Consultar pide seleccion:ver;
    // fijar el objetivo y confirmar la lista, seleccion:decidir.
    Route::get('seleccion/convocatorias/{convocatoria}/ranking', [RankingSeleccionController::class, 'show'])
        ->whereNumber('convocatoria')
        ->middleware('can:seleccion:ver');
    Route::put('seleccion/convocatorias/{convocatoria}/ranking/objetivo', [RankingSeleccionController::class, 'objetivo'])
        ->whereNumber('convocatoria')
        ->middleware('can:seleccion:decidir');
    Route::get('seleccion/convocatorias/{convocatoria}/finalistas', [FinalistasSeleccionController::class, 'index'])
        ->whereNumber('convocatoria')
        ->middleware('can:seleccion:ver');
    Route::post('seleccion/convocatorias/{convocatoria}/finalistas', [FinalistasSeleccionController::class, 'store'])
        ->whereNumber('convocatoria')
        ->middleware('can:seleccion:decidir');

    // Etapa 6: decisiones internas, cupos e historial. Consultar pide seleccion:ver; decidir o rectificar, seleccion:decidir.
    Route::get('seleccion/convocatorias/{convocatoria}/decisiones', [DecisionSeleccionController::class, 'index'])
        ->whereNumber('convocatoria')
        ->middleware('can:seleccion:ver');
    Route::put('seleccion/convocatorias/{convocatoria}/decisiones/{postulacion}', [DecisionSeleccionController::class, 'update'])
        ->whereNumber(['convocatoria', 'postulacion'])
        ->middleware('can:seleccion:decidir');
    Route::get('seleccion/convocatorias/{convocatoria}/decisiones/{postulacion}/historial', [DecisionSeleccionController::class, 'historial'])
        ->whereNumber(['convocatoria', 'postulacion'])
        ->middleware('can:seleccion:ver');

    // CU-SEL-017: comparar de 2 a 5 candidatos de una convocatoria (solo lectura). Pide seleccion:ver; el evaluador y la
    // referencia documental solo se envían a Instructor y Superadministrador.
    Route::get('seleccion/convocatorias/{convocatoria}/comparacion', [ComparacionSeleccionController::class, 'show'])
        ->whereNumber('convocatoria')
        ->middleware('can:seleccion:ver');
});

// ── Ejemplo: cada ruta de negocio declara el permiso que exige (403 si el rol no lo tiene) ──
// Las rutas de negocio deben llevar 'verified' además de 'can:' para exigir
// que el correo esté confirmado. Al crear cada endpoint:
//   Route::middleware(['auth:sanctum', 'verified'])
//       ->get('...', ...)->middleware('can:...');

// Disparador externo de tareas programadas (plan gratuito, sin cron). Fuera del grupo autenticado: lo protege el
// token CRON_TOKEN dentro del controlador. Limitado a 10 peticiones por minuto.
Route::post('tareas/{tarea}', [TareasProgramadasController::class, 'ejecutar'])
    ->where('tarea', '[a-z\-]+')
    ->middleware('throttle:10,1');
