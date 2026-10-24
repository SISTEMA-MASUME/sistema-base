<?php

use App\Http\Controllers\Auth\LoginController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'formulario'])->name('login');
    Route::post('/login', [LoginController::class, 'entrar'])->middleware('throttle:10,1')->name('login.entrar');
});

Route::post('/salir', [LoginController::class, 'salir'])->middleware('auth')->name('salir');

// Inicio: cuando exista la lista de cotizaciones (T4, ruta 'cotizaciones.index')
// redirige ahí; mientras tanto muestra una pantalla de bienvenida.
Route::get('/', function () {
    if (auth()->user()?->esNed()) {
        return redirect()->route('ned.index');
    }
    if (auth()->user()?->esCampo() && Route::has('pedidos.index')) {
        return redirect()->route('pedidos.index');
    }
    if (Route::has('cotizaciones.index')) {
        return redirect()->route('cotizaciones.index');
    }

    return view('auth.inicio');
})->middleware('auth')->name('inicio');
