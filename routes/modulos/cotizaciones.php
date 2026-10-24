<?php

use App\Http\Controllers\CotizacionFolioController;
use App\Http\Controllers\CotizacionPdfController;
use App\Livewire\Cotizaciones\Editor;
use App\Livewire\Cotizaciones\Index;
use App\Livewire\Cotizaciones\RevisionMovil;
use Illuminate\Support\Facades\Route;

Route::get('/cotizaciones', Index::class)->name('cotizaciones.index');
Route::get('/cotizaciones/nueva', Editor::class)->name('cotizaciones.nueva');
Route::get('/cotizaciones/folio/{folio}', CotizacionFolioController::class)->name('cotizaciones.folio');
Route::get('/cotizaciones/{cotizacion}/pdf', CotizacionPdfController::class)->name('cotizaciones.pdf');
Route::get('/cotizaciones/{cotizacion}/revision', RevisionMovil::class)->name('cotizaciones.revision');
Route::get('/cotizaciones/{cotizacion}', Editor::class)->name('cotizaciones.editar');
