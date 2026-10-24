<?php

use App\Http\Controllers\PedidoArchivoController;
use App\Http\Controllers\PedidoExpedienteController;
use App\Livewire\Pedidos\Nuevo;
use App\Livewire\Pedidos\Ver;
use Illuminate\Support\Facades\Route;

// El tablero (/pedidos) lo define routes/modulos/tablero.php.
Route::get('/pedidos/nuevo', Nuevo::class)->name('pedidos.nuevo');
Route::get('/pedidos/{pedido}/oc-cliente', PedidoArchivoController::class)->whereNumber('pedido')->name('pedidos.oc-cliente');
Route::get('/pedidos/{pedido}/archivos/{archivo}', PedidoExpedienteController::class)->whereNumber(['pedido', 'archivo'])->name('pedidos.archivo');
Route::get('/pedidos/{pedido}', Ver::class)->whereNumber('pedido')->name('pedidos.ver');
