<?php

use App\Livewire\Pedidos\PorRecoger;
use Illuminate\Support\Facades\Route;

// Lo que falta recoger de los proveedores (también lo usa el personal de campo).
Route::get('/por-recoger', PorRecoger::class)->name('pedidos.por-recoger');
