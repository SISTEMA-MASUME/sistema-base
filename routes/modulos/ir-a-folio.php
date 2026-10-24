<?php

use App\Http\Controllers\IrAFolioController;
use Illuminate\Support\Facades\Route;

// "Ir a folio" del menú: cotización (90123), pedido (P-45), orden al proveedor (OC-12) o solicitud (S-8).
Route::get('/ir/{folio}', IrAFolioController::class)->where('folio', '.*')->name('ir-a-folio');
