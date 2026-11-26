<?php

use App\Http\Controllers\Api\AlmacenamientoController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ContrasenaController;
use App\Http\Controllers\Api\ConvocatoriaController;
use App\Http\Controllers\Api\ListadoConvocatoriasController;
use App\Http\Controllers\Api\TareasProgramadasController;
use App\Http\Controllers\Api\EvaluacionController;
use App\Http\Controllers\Api\EvaluacionAspiranteController;
use App\Http\Controllers\Api\EvaluacionCorreccionController;
use App\Http\Controllers\Api\EvaluacionListadoController;
use App\Http\Controllers\Api\FichaController;
use App\Http\Controllers\Api\NotificacionController;
use App\Http\Controllers\Api\ParametroController;
use App\Http\Controllers\Api\PerfilAspiranteController;
use App\Http\Controllers\Api\PortafolioController;
use App\Http\Controllers\Api\PostulacionController;
use App\Http\Controllers\Api\PostulacionEstadoController;
use App\Http\Controllers\Api\PostulacionDatosPersonalesController;
use App\Http\Controllers\Api\PostulacionDocumentoController;
use App\Http\Controllers\Api\PostulacionSkillController;
use App\Http\Controllers\Api\SocialAuthController;
use App\Http\Controllers\Api\UbicacionController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('register', [AuthController::class, 'register'])->middleware('throttle:login');
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login');

    // Recuperar contraseña: públicas (no hay sesión todavía).
    Route::post('forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:otp-send');
    Route::post('reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:otp-check');

    // OAuth: el callback llega desde accounts.google.com, no desde el front, y Sanctum statefulApi() solo da sesión a las
    // peticiones del front. 'sesion.web' abre la sesión web cuando Sanctum no lo hizo, y NUNCA dos veces: con 'web' a secas,
    // una petición del front con cookie previa perdía la sesión (ver SesionWebUnaVez).
    Route::get('{provider}/redirect', [SocialAuthController::class, 'redirect'])->middleware('sesion.web');
    Route::get('{provider}/callback', [SocialAuthController::class, 'callback'])->middleware('sesion.web');
    // Aspirante que entró con Google y todavía no eligió su ficha: la cuenta se crea al elegirla.
    Route::get('google/pendiente', [SocialAuthController::class, 'pendiente'])->middleware('sesion.web');
    Route::post('google/pendiente/ficha', [SocialAuthController::class, 'completarPendiente'])
        ->middleware(['sesion.web', 'throttle:login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('me', [AuthController::class, 'me']);
        Route::post('logout', [AuthController::class, 'logout']);

        // Confirmar correo: requieren sesión pero NO 'verified' (si no, no se podría confirmar).
        Route::post('verification/send', [AuthController::class, 'sendVerification'])->middleware('throttle:otp-send');
        Route::post('verification/confirm', [AuthController::class, 'confirmVerification'])->middleware('throttle:otp-check');

        // Establecer o cambiar la contraseña desde la sesión, con código al correo de ESA sesión. Exige correo verificado y los
        // límites existentes (otp-send / otp-check). Apagado por defecto: ver config/seguridad.php.
        Route::middleware('verified')->prefix('password')->group(function () {
            Route::post('codigo', [ContrasenaController::class, 'codigo'])->middleware('throttle:otp-send');
            Route::post('establecer', [ContrasenaController::class, 'establecer'])->middleware('throttle:otp-check');
        });

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
        Route::get('fichas/{ficha}/documentos/ids', [AlmacenamientoController::class, 'idsBorrables'])->whereNumber('ficha');
        Route::post('documentos/borrar', [AlmacenamientoController::class, 'borrar']);
    });

    // ── Notificaciones (campana) ─────────────────────────────────────────
    // Cada usuario ve solo las suyas: el controlador filtra por el autenticado.
    Route::middleware('can:propio:notificaciones')->group(function () {
        Route::get('notificaciones', [NotificacionController::class, 'index']);
        Route::post('notificaciones/leer-todas', [NotificacionController::class, 'leerTodas']);
        Route::post('notificaciones/{notificacion}/leer', [NotificacionController::class, 'leer'])
            ->whereNumber('notificacion');
        Route::post('notificaciones/{notificacion}/ocultar', [NotificacionController::class, 'ocultar'])
            ->whereNumber('notificacion');
        Route::post('notificaciones/{notificacion}/restaurar', [NotificacionController::class, 'restaurar'])
            ->whereNumber('notificacion');
    });

    // ── Documentos y skills de la postulación (Paso 4) ────────────────────
    // 'propio:documentos' lo tienen aspirante, instructor y super admin: cada
    // quien edita SOLO lo suyo (filtro por user_id en el controlador). Una
    // postulación ajena o inexistente -> 404 en español. Estos pasos se editan
    // con la convocatoria publicada y dentro de la ventana, en 'borrador',
    // 'postulada' o 'retirada'.
    Route::middleware('can:propio:documentos')->group(function () {
        // Perfil mínimo del aspirante (sexo). Se conserva por compatibilidad: el sexo ahora se pide dentro de los datos
        // personales de cada postulación.
        // Departamentos y municipios de Colombia (DIVIPOLA) para el formulario de datos personales.
        Route::get('ubicaciones/departamentos', [UbicacionController::class, 'departamentos']);
        Route::get('ubicaciones/departamentos/{departamento}/municipios', [UbicacionController::class, 'municipios'])
            ->where('departamento', '[0-9]{2}');

        Route::get('perfil', [PerfilAspiranteController::class, 'show']);
        Route::put('perfil/sexo', [PerfilAspiranteController::class, 'sexo']);

        Route::get('postulaciones/{postulacion}/datos-personales', [PostulacionDatosPersonalesController::class, 'show'])
            ->whereNumber('postulacion')
            ->missing(fn () => abort(404, 'Postulación no encontrada.'));
        Route::put('postulaciones/{postulacion}/datos-personales', [PostulacionDatosPersonalesController::class, 'update'])
            ->whereNumber('postulacion')
            ->missing(fn () => abort(404, 'Postulación no encontrada.'));

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
        Route::get('postulaciones/{postulacion}/datos-personales', [PostulacionDatosPersonalesController::class, 'showRevision'])
            ->whereNumber('postulacion')
            ->missing(fn () => abort(404, 'Postulación no encontrada.'));
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
    Route::get('postulaciones/{postulacion}/correccion', [EvaluacionAspiranteController::class, 'correccionPropia'])
        ->middleware('can:propio:postulaciones')
        ->whereNumber('postulacion');
    Route::post('postulaciones/{postulacion}/correccion/enviar', [EvaluacionAspiranteController::class, 'enviarCorreccion'])
        ->middleware('can:propio:postulaciones')
        ->whereNumber('postulacion');

    // Módulo de Selección y Preselección: ver routes/seleccion.php (mismo grupo de autenticación; incluye las citaciones).
    require __DIR__.'/seleccion.php';

    // Textos legales y aceptaciones (apagado por defecto): ver routes/legal.php.
    require __DIR__.'/legal.php';
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
