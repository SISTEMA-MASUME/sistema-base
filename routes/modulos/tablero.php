<?php

use App\Livewire\Tablero\Index;
use Illuminate\Support\Facades\Route;

Route::get('/pedidos', Index::class)->name('pedidos.index');
