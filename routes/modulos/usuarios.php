<?php

use App\Livewire\Usuarios\Index;
use Illuminate\Support\Facades\Route;

Route::get('/usuarios', Index::class)->middleware('admin')->name('usuarios.index');
