<?php

use App\Livewire\Catalogo\Imagenes;
use Illuminate\Support\Facades\Route;

Route::get('/catalogo-imagenes', Imagenes::class)->name('catalogo.imagenes');
