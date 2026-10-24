<?php

use App\Livewire\Mcp\Conexion;
use Illuminate\Support\Facades\Route;

Route::get('/conexion-ia', Conexion::class)->middleware('admin')->name('mcp.conexion');
