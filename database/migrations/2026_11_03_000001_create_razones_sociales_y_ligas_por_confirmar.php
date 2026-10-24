<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Candado de las ligas pedido ↔ factura por OC: la factura sale a la RAZÓN SOCIAL ("HOTELES XY") y el pedido está con el
 * nombre comercial ("HOTEL AURORA EJECUTIVO"). Solo se liga sola si esa razón social ya está registrada como del cliente
 * del pedido; si no, queda una PROPUESTA (pedido_venta.por_confirmar) que Daniel confirma una vez.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cliente_razones_sociales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cliente_id')->constrained('clientes')->cascadeOnDelete();
            $table->string('nombre');
            $table->string('nombre_llave');
            $table->string('rfc', 20)->nullable();
            $table->foreignId('confirmada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['cliente_id', 'nombre_llave']);
        });

        Schema::create('ligas_rechazadas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cliente_id')->constrained('clientes')->cascadeOnDelete();
            $table->string('nombre_llave');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['cliente_id', 'nombre_llave']);
        });

        Schema::table('pedido_venta', function (Blueprint $table) {
            $table->boolean('por_confirmar')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('pedido_venta', function (Blueprint $table) {
            $table->dropColumn('por_confirmar');
        });
        Schema::dropIfExists('ligas_rechazadas');
        Schema::dropIfExists('cliente_razones_sociales');
    }
};
