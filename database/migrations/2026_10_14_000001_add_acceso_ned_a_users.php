<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Vendedores que también trabajan cotizaciones de Ned Tracking (entrar a /ned y "Pasar a Ned Tracking").
 * Daniel lo pidió para Rosa y Alberto; se cambia en Usuarios.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->boolean('acceso_ned')->default(false)->after('activo'));
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('acceso_ned'));
    }
};
