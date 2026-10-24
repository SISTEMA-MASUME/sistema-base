<?php

use App\Http\Controllers\OrdenProveedorImagenController;
use App\Http\Controllers\OrdenProveedorPdfController;
use App\Livewire\OrdenesProveedor\Generar;
use App\Livewire\OrdenesProveedor\Index;
use App\Livewire\OrdenesProveedor\Ver;
use Illuminate\Support\Facades\Route;

// Rol campo: puede consultar, ver el PDF y marcar recibida; no genera órdenes (403 aquí y en las acciones de Ver).
Route::get('/pedidos/{pedido}/ordenes-proveedor/nueva', Generar::class)->middleware('sin_campo')->name('ordenes-proveedor.generar');
Route::get('/ordenes-proveedor', Index::class)->name('ordenes-proveedor.index');
Route::get('/ordenes-proveedor/{orden}/pdf', OrdenProveedorPdfController::class)->name('ordenes-proveedor.pdf');
Route::get('/ordenes-proveedor/{orden}/imagen', OrdenProveedorImagenController::class)->name('ordenes-proveedor.imagen');
Route::get('/ordenes-proveedor/{orden}', Ver::class)->name('ordenes-proveedor.ver');
