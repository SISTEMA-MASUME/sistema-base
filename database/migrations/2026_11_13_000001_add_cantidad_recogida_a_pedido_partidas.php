<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recogidas parciales: cuánto se ha recogido de cada partida (el proveedor a veces entrega 5 de 10). Nulo = no se ha
 * registrado en partes; una partida "Recogida" o "Entregada" cuenta como completa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pedido_partidas', function (Blueprint $table) {
            $table->decimal('cantidad_recogida', 12, 3)->nullable()->after('estado');
        });
    }

    public function down(): void
    {
        Schema::table('pedido_partidas', function (Blueprint $table) {
            $table->dropColumn('cantidad_recogida');
        });
    }
};
