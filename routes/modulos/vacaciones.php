<?php

use App\Livewire\Vacaciones\Index;
use Illuminate\Support\Facades\Route;

// Vacaciones: cualquier usuario de MASUME (también campo); Ned Tracking no entra (middleware solo_masume desde routes/web.php).
Route::get('/vacaciones', Index::class)->name('vacaciones.index');
