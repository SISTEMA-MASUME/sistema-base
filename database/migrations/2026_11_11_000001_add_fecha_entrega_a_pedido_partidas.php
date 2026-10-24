<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fecha estimada de entrega por partida (en Trello se ponía en cada elemento del checklist).
// La pone quien da de alta el pedido; el personal de campo solo la ve.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pedido_partidas', fn (Blueprint $table) => $table->date('fecha_entrega')->nullable()->after('facturada'));
    }

    public function down(): void
    {
        Schema::table('pedido_partidas', fn (Blueprint $table) => $table->dropColumn('fecha_entrega'));
    }
};
