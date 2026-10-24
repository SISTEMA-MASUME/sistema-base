<?php

use App\Livewire\Proveedores\Index;
use App\Livewire\Proveedores\Ver;
use Illuminate\Support\Facades\Route;

Route::get('/proveedores', Index::class)->name('proveedores.index');
Route::get('/proveedores/{proveedor}', Ver::class)->whereNumber('proveedor')->name('proveedores.ver');
