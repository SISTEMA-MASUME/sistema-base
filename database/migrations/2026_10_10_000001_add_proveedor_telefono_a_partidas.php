<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Teléfono/WhatsApp desde el que el vendedor del proveedor pasó la cotización. Solo interno. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cotizacion_partidas', function (Blueprint $table) {
            $table->string('proveedor_telefono', 30)->nullable();
        });
        Schema::table('pedido_partidas', function (Blueprint $table) {
            $table->string('proveedor_telefono', 30)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('pedido_partidas', fn (Blueprint $table) => $table->dropColumn('proveedor_telefono'));
        Schema::table('cotizacion_partidas', fn (Blueprint $table) => $table->dropColumn('proveedor_telefono'));
    }
};
