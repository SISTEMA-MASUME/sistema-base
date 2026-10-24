<?php

use App\Http\Controllers\ComprobantePagoController;
use App\Livewire\Finanzas\CategoriasGastos;
use App\Livewire\Finanzas\Cobranza;
use App\Livewire\Finanzas\Depositos;
use App\Livewire\Finanzas\Gastos;
use App\Livewire\Finanzas\Importar;
use App\Livewire\Finanzas\LigasPorConfirmar;
use App\Livewire\Finanzas\MisFinanzas;
use App\Livewire\Finanzas\Nomina;
use App\Livewire\Finanzas\PorPagar;
use App\Livewire\Finanzas\Tablero;
use App\Livewire\Finanzas\Ventas;
use Illuminate\Support\Facades\Route;

// Finanzas (reemplaza el Google Sheets de ventas, cobranza y nómina): solo el admin o a quien él le dé permiso.
Route::get('/finanzas/tablero', Tablero::class)->middleware('permiso:tablero')->name('finanzas.tablero');
Route::get('/finanzas/ventas', Ventas::class)->middleware('permiso:ventas')->name('finanzas.ventas');
Route::get('/finanzas/depositos', Depositos::class)->middleware('permiso:ventas')->name('finanzas.depositos');
Route::get('/finanzas/ligas-por-confirmar', LigasPorConfirmar::class)->middleware('permiso:ventas')->name('finanzas.ligas-por-confirmar');
Route::get('/finanzas/cobranza', Cobranza::class)->middleware('permiso:cobranza')->name('finanzas.cobranza');
Route::get('/finanzas/nomina', Nomina::class)->middleware('permiso:nomina')->name('finanzas.nomina');
Route::get('/finanzas/gastos', Gastos::class)->middleware('permiso:gastos')->name('finanzas.gastos');
Route::get('/finanzas/gastos/categorias', CategoriasGastos::class)->middleware('permiso:gastos')->name('finanzas.gastos.categorias');
Route::get('/finanzas/mis-finanzas', MisFinanzas::class)->middleware('permiso:finanzas')->name('finanzas.mis-finanzas');
Route::get('/finanzas/importar', Importar::class)->middleware('admin')->name('finanzas.importar');
// A quién le debo (compras a crédito a proveedores y sus pagos con comprobante): solo el admin.
Route::get('/finanzas/por-pagar', PorPagar::class)->middleware('admin')->name('finanzas.por-pagar');
Route::get('/finanzas/por-pagar/comprobante/{pago}', ComprobantePagoController::class)->middleware('admin')->whereNumber('pago')->name('finanzas.por-pagar.comprobante');
