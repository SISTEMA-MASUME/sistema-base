<?php

use App\Livewire\CambiosPrecio\Index;
use Illuminate\Support\Facades\Route;

Route::get('/cambios-precio', Index::class)->middleware('admin')->name('cambios-precio.index');
Route::get('/cambios-precio/{cambio}', Index::class)->whereNumber('cambio')->middleware('admin')->name('cambios-precio.ver');
