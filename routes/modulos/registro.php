<?php

use App\Livewire\Registro\Importar;
use Illuminate\Support\Facades\Route;

Route::get('/importar-registro', Importar::class)->middleware('admin')->name('registro.importar');
