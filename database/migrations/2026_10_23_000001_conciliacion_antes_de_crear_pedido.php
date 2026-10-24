<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La OC del cliente se revisa con IA mientras se da de alta el pedido (antes de que exista): la lectura se
 * guarda sin pedido y se le liga al crearlo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pedido_conciliaciones', function (Blueprint $table) {
            $table->unsignedBigInteger('pedido_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Sin vuelta atrás: puede haber lecturas sin pedido.
    }
};
