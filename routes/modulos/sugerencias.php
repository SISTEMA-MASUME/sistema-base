<?php

use App\Http\Controllers\SugerenciasController;
use Illuminate\Support\Facades\Route;

Route::get('/clientes/{cliente}/sugerencias', [SugerenciasController::class, 'cliente'])
    ->whereNumber('cliente')->name('clientes.sugerencias');
Route::get('/sugerencias/articulos', [SugerenciasController::class, 'articulos'])->name('sugerencias.articulos');
Route::get('/sugerencias/global', [SugerenciasController::class, 'global'])->name('sugerencias.global');
