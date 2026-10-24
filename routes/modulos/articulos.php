<?php

use App\Livewire\Articulos\Index;
use App\Livewire\Articulos\Listas;
use App\Livewire\Articulos\Programas;
use App\Livewire\Articulos\Ver;
use Illuminate\Support\Facades\Route;

// Catálogo de artículos (admin y vendedores; sin campo: trae precios de venta).
Route::get('/articulos', Index::class)->name('articulos.index');
Route::get('/articulos/listas', Listas::class)->name('articulos.listas');
Route::get('/articulos/{articulo}', Ver::class)->whereNumber('articulo')->name('articulos.ver');

// Metas con proveedores (programa comercial de Urrea): solo Daniel.
Route::get('/programas', Programas::class)->middleware('admin')->name('programas');
