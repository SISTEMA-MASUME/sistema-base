<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A qué cliente/proveedor se unió un duplicado. Así, si el REGISTRO de Sheets trae el nombre viejo
 * ("MAXIFERO"), la cotización importada se liga al que se quedó y no revive el duplicado.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['clientes', 'proveedores'] as $tabla) {
            Schema::table($tabla, fn (Blueprint $table) => $table->unsignedBigInteger('unido_a_id')->nullable());
        }
    }

    public function down(): void
    {
        foreach (['clientes', 'proveedores'] as $tabla) {
            Schema::table($tabla, fn (Blueprint $table) => $table->dropColumn('unido_a_id'));
        }
    }
};
