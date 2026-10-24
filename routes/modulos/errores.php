<?php

use App\Livewire\Errores\Index;
use Illuminate\Support\Facades\Route;

Route::get('/errores', Index::class)->middleware('admin')->name('errores.index');
