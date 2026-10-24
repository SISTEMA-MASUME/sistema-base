<?php

use App\Http\Controllers\VisorPdfController;
use Illuminate\Support\Facades\Route;

// Visor de PDF con flecha para regresar (celular). Lo usan MASUME y Ned Tracking: va sin solo_masume (routes/web.php).
Route::get('/visor-pdf', VisorPdfController::class)->name('visor.pdf');
