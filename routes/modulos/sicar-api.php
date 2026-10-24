<?php

use App\Http\Controllers\SicarCatalogoController;
use App\Http\Controllers\SicarPuenteController;
use App\Http\Controllers\SicarVentasController;
use App\Http\Middleware\TokenSicar;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;

// Sin sesión ni CSRF (routes/web.php lo carga aparte): lo llama el programa de la PC con token Bearer.
Route::post('/api/sicar/catalogo', SicarCatalogoController::class)
    ->withoutMiddleware([ValidateCsrfToken::class])
    ->middleware('throttle:60,1')
    ->name('sicar.catalogo');

// Puente SICAR: la PC consulta si hay un lote, sube el simulacro/resultado y el catálogo de proveedores.
Route::middleware([TokenSicar::class, 'throttle:120,1'])->withoutMiddleware([ValidateCsrfToken::class])->group(function () {
    Route::get('/api/sicar/puente', [SicarPuenteController::class, 'estado'])->name('sicar.puente');
    Route::post('/api/sicar/puente/lotes/{id}', [SicarPuenteController::class, 'actualizar'])->whereNumber('id')->name('sicar.puente.lote');
    Route::post('/api/sicar/proveedores', [SicarPuenteController::class, 'proveedores'])->name('sicar.proveedores');
    // Finanzas: facturas, notas de venta y pagos (complementos) leídos de SICAR.
    Route::post('/api/sicar/ventas', [SicarVentasController::class, 'recibir'])->name('sicar.ventas');
    Route::get('/api/sicar/ventas/estado', [SicarVentasController::class, 'estado'])->name('sicar.ventas.estado');
});
