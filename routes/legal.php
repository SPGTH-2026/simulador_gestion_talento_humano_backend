<?php

// Textos legales (versiones) y aceptaciones. Se incluye desde routes/api.php DENTRO del grupo autenticado. Todo responde 404 mientras la
// bandera legal.aceptaciones_activas esté apagada (por defecto). No hay permisos generales nuevos: aprobar es del superadministrador y se
// comprueba en el controlador; aceptar es siempre del propio usuario autenticado.

use App\Http\Controllers\Api\AceptacionLegalController;
use App\Http\Controllers\Api\DocumentoLegalController;
use App\Http\Middleware\RequiereAceptacionesLegales;
use Illuminate\Support\Facades\Route;

Route::middleware(RequiereAceptacionesLegales::class)->prefix('legal')->group(function () {
    Route::get('documentos', [DocumentoLegalController::class, 'index']);
    Route::get('documentos/vigentes', [DocumentoLegalController::class, 'vigentes']);
    Route::post('documentos', [DocumentoLegalController::class, 'store']);
    Route::put('documentos/{documento}', [DocumentoLegalController::class, 'update'])->whereNumber('documento');
    Route::post('documentos/{documento}/aprobar', [DocumentoLegalController::class, 'aprobar'])->whereNumber('documento');
    Route::post('documentos/{documento}/aceptar', [AceptacionLegalController::class, 'store'])->whereNumber('documento');
    Route::get('aceptaciones', [AceptacionLegalController::class, 'index']);
});
