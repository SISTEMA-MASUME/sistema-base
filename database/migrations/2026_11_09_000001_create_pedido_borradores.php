<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pedido nuevo a medio capturar: si se cierra la ventana o se sale por error, se retoma en el mismo paso.
 * Uno por usuario (el último que dejó sin terminar).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pedido_borradores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->foreignId('cliente_id')->nullable()->constrained('clientes')->nullOnDelete();
            $table->unsignedTinyInteger('paso')->default(1);
            $table->unsignedInteger('partidas')->default(0);
            $table->longText('datos');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pedido_borradores');
    }
};
