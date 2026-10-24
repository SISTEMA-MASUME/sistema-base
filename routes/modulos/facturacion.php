<?php

use App\Livewire\Facturacion\Index;
use Illuminate\Support\Facades\Route;

Route::get('/facturacion', Index::class)->name('facturacion.index');
