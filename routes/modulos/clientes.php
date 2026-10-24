<?php

use App\Livewire\Clientes\Index;
use App\Livewire\Clientes\Ver;
use Illuminate\Support\Facades\Route;

Route::get('/clientes', Index::class)->name('clientes.index');
Route::get('/clientes/{cliente}', Ver::class)->whereNumber('cliente')->name('clientes.ver');
