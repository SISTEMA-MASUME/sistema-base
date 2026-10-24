<?php

use App\Livewire\Sicar\Alta;
use App\Livewire\Sicar\Lotes;
use Illuminate\Support\Facades\Route;

// Se carga con auth + solo_masume + sin_campo (routes/web.php): solo admin y vendedores.
Route::get('/sicar/alta', Alta::class)->name('sicar.alta');
Route::get('/sicar/lotes', Lotes::class)->name('sicar.lotes');
