<?php

// Rutas del módulo de Selección y Preselección (etapas 1 a 6 y comparación). Se incluyen desde routes/api.php DENTRO del grupo
// autenticado (auth:sanctum, verified, ficha), así que heredan su middleware y su prefijo; el orden de registro es el mismo de antes.
// Cada ruta declara su permiso (seleccion:ver o seleccion:decidir) y los controladores validan el alcance por ficha.

use App\Http\Controllers\Api\CalificacionSeleccionController;
use App\Http\Controllers\Api\ComparacionSeleccionController;
use App\Http\Controllers\Api\ConfiguracionSeleccionController;
use App\Http\Controllers\Api\DecisionSeleccionController;
use App\Http\Controllers\Api\FinalistasSeleccionController;
use App\Http\Controllers\Api\RankingSeleccionController;
use App\Http\Controllers\Api\ReferenciaDocumentalController;
use App\Http\Controllers\Api\SeleccionController;
use Illuminate\Support\Facades\Route;

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

// Citaciones de Selección y las citas del aspirante.
require __DIR__.'/citaciones.php';
