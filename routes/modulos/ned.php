<?php

use App\Http\Controllers\NedController;
use App\Livewire\Ned\Datos;
use App\Livewire\Ned\Editor;
use App\Livewire\Ned\Index;
use Illuminate\Support\Facades\Route;

// Solo el usuario de Ned Tracking (routes/web.php le pone el middleware solo_ned).
Route::get('/ned', Index::class)->name('ned.index');
Route::get('/ned/datos', Datos::class)->name('ned.datos');
Route::get('/ned/logo', [NedController::class, 'logo'])->name('ned.logo');
Route::get('/ned/cotizaciones/{cotizacion}/pdf', [NedController::class, 'pdf'])->whereNumber('cotizacion')->name('ned.pdf');
Route::get('/ned/cotizaciones/{cotizacion}', Editor::class)->whereNumber('cotizacion')->name('ned.editar');
