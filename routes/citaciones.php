<?php

// Rutas de citaciones de Selección. Se incluyen desde routes/api.php DENTRO del grupo autenticado (auth:sanctum, verified, ficha),
// así que heredan su middleware y su prefijo.

use App\Http\Controllers\Api\CitacionAspiranteController;
use App\Http\Controllers\Api\CitacionSeleccionController;
use Illuminate\Support\Facades\Route;

// Citaciones de Selección (entrevista o prueba técnica) por candidato. Consultar pide seleccion:ver; programar, reprogramar y
// cancelar, seleccion:decidir. No condicionan las calificaciones ni la elegibilidad.
Route::get('seleccion/convocatorias/{convocatoria}/candidatos/{postulacion}/citaciones', [CitacionSeleccionController::class, 'index'])
    ->whereNumber(['convocatoria', 'postulacion'])
    ->middleware('can:seleccion:ver');
Route::post('seleccion/convocatorias/{convocatoria}/candidatos/{postulacion}/citaciones', [CitacionSeleccionController::class, 'store'])
    ->whereNumber(['convocatoria', 'postulacion'])
    ->middleware('can:seleccion:decidir');
Route::put('seleccion/convocatorias/{convocatoria}/candidatos/{postulacion}/citaciones/{citacion}', [CitacionSeleccionController::class, 'update'])
    ->whereNumber(['convocatoria', 'postulacion', 'citacion'])
    ->middleware('can:seleccion:decidir');
Route::post('seleccion/convocatorias/{convocatoria}/candidatos/{postulacion}/citaciones/{citacion}/cancelar', [CitacionSeleccionController::class, 'cancelar'])
    ->whereNumber(['convocatoria', 'postulacion', 'citacion'])
    ->middleware('can:seleccion:decidir');

// Las citas del propio aspirante: solo las de sus postulaciones.
Route::get('citaciones', [CitacionAspiranteController::class, 'index'])->middleware('can:propio:postulaciones');
