<?php

use App\Http\Controllers\DocumentoPublicoController;
use Illuminate\Support\Facades\Route;

// Rutas SIN sesión (routes/web.php no les pone "auth"). Solo con firma válida y vigente.
Route::get('/documentos/{tipo}/{id}', DocumentoPublicoController::class)
    ->whereIn('tipo', ['cotizacion', 'orden_proveedor'])->whereNumber('id')
    ->middleware(['signed:relative', 'throttle:60,1'])
    ->name('documentos.publico');
