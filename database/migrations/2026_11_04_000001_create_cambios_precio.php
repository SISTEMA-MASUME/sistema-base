<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cambios de precio de venta al dar de alta un pedido (la OC del cliente trae otro precio que el cotizado).
 * El vendedor pide el cambio y Daniel lo autoriza; si lo hace Daniel queda como "directo". Sirve de evidencia
 * y para saber la utilidad real final (utilidad = (precio − costo) × cantidad).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cambios_precio', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pedido_id')->constrained('pedidos')->cascadeOnDelete();
            $table->foreignId('pedido_partida_id')->nullable()->constrained('pedido_partidas')->nullOnDelete();
            $table->foreignId('cotizacion_partida_id')->nullable()->constrained('cotizacion_partidas')->nullOnDelete();
            $table->string('descripcion', 2000)->nullable();            // copia, por si la partida se quita después
            $table->decimal('cantidad', 14, 3)->default(0);             // copia al momento de pedirlo
            $table->decimal('precio_anterior', 14, 4);
            $table->decimal('precio_solicitado', 14, 4);
            $table->decimal('precio_final', 14, 4)->nullable();
            $table->decimal('costo_anterior', 14, 4)->nullable();
            $table->decimal('costo_nuevo', 14, 4)->nullable();
            $table->foreignId('proveedor_id')->nullable()->constrained('proveedores')->nullOnDelete();
            $table->text('motivo');
            $table->string('estatus', 20)->default('pendiente')->index();   // pendiente|autorizado|rechazado|directo
            $table->foreignId('solicitado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('resuelto_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resuelto_at')->nullable();
            $table->text('nota_resolucion')->nullable();
            $table->decimal('utilidad_antes', 14, 2)->default(0);
            $table->decimal('utilidad_despues', 14, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cambios_precio');
    }
};
