<?php

use App\Http\Controllers\SolicitudArchivoController;
use App\Livewire\Solicitudes\Index;
use App\Livewire\Solicitudes\Nueva;
use App\Livewire\Solicitudes\Ver;
use Illuminate\Support\Facades\Route;

// Bandeja "Por cotizar". Se carga con sin_campo (routes/web.php): Hugo no la ve.
Route::get('/por-cotizar', Index::class)->name('solicitudes.index');
Route::get('/por-cotizar/nueva', Nueva::class)->name('solicitudes.nueva');
Route::get('/por-cotizar/archivos/{archivo}', SolicitudArchivoController::class)->whereNumber('archivo')->name('solicitudes.archivo');
Route::get('/por-cotizar/{solicitud}/editar', Nueva::class)->whereNumber('solicitud')->name('solicitudes.editar');
Route::get('/por-cotizar/{solicitud}', Ver::class)->whereNumber('solicitud')->name('solicitudes.ver');
