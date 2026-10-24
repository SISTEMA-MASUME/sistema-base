<?php

use App\Livewire\Duplicados\Index;
use Illuminate\Support\Facades\Route;

Route::get('/duplicados', Index::class)->middleware('admin')->name('duplicados.index');
