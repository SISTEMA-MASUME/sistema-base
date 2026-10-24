<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Dónde comprar el material (p. ej. un enlace de Mercado Libre). Solo interno: nunca sale al cliente. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cotizacion_partidas', function (Blueprint $table) {
            $table->string('link_compra', 1000)->nullable();
        });
        Schema::table('pedido_partidas', function (Blueprint $table) {
            $table->string('link_compra', 1000)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('pedido_partidas', fn (Blueprint $table) => $table->dropColumn('link_compra'));
        Schema::table('cotizacion_partidas', fn (Blueprint $table) => $table->dropColumn('link_compra'));
    }
};
