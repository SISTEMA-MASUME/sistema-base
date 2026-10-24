<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Costo de cada partida de Ned (sin IVA) para calcular el precio con una utilidad. Viene de la cotización
 * de MASUME al pasarla; solo lo ve el admin y nunca sale en el PDF.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ned_partidas', fn (Blueprint $table) => $table->decimal('costo', 14, 4)->nullable()->after('unidad'));
    }

    public function down(): void
    {
        Schema::table('ned_partidas', fn (Blueprint $table) => $table->dropColumn('costo'));
    }
};
