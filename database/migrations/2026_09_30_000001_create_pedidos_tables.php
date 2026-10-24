<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Módulo 2: pedidos (OC del cliente), sus partidas y las OC a proveedores.
 * Reemplaza el tablero de Trello "ORDENES DE COMPRA" y las OC internas del REGISTRO.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pedidos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('folio')->unique();                  // Folios::siguiente('pedido')
            $table->string('tipo', 20)->default('oc');                     // App\Enums\TipoPedido: oc | nota_venta
            $table->foreignId('cliente_id')->constrained('clientes');
            $table->string('numero_oc_cliente', 80)->nullable();           // lo que dice la OC del cliente
            $table->string('numero_oc_llave', 80)->nullable();             // normalizado para evitar "OC 45" ≈ "OC 4567"
            $table->foreignId('responsable_id')->constrained('users');
            $table->date('fecha_oc');
            $table->date('fecha_compromiso')->nullable();                  // entrega prometida al cliente
            $table->string('columna', 40)->default('oc_recibida');         // App\Enums\ColumnaPedido
            $table->unsignedInteger('posicion')->default(0);               // orden dentro de la columna
            $table->boolean('alta_sicar')->default(false);                 // etiqueta "MAT DADO DE ALTA" (manual hasta módulo 3)
            $table->boolean('revisar')->default(false);                    // "REVISAR/DAR SEGUIMIENTO"
            $table->boolean('modificar_oc')->default(false);               // "SE ENVIA A MODIFICAR OC"
            $table->timestamp('cancelado_at')->nullable();                 // "OC CANCELADA POR CLIENTE"
            $table->string('oc_cliente_path')->nullable();                 // PDF o foto de la OC (disco local)
            $table->text('notas')->nullable();
            $table->decimal('total_venta', 14, 2)->default(0);             // sin IVA
            $table->decimal('total_costo', 14, 2)->default(0);             // sin IVA
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['cliente_id', 'numero_oc_llave']);
            $table->index(['columna', 'posicion']);
        });

        Schema::create('ordenes_proveedor', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('folio')->unique();                  // Folios::siguiente('oc_proveedor') → "OC-0001"
            $table->foreignId('pedido_id')->constrained('pedidos');
            $table->foreignId('proveedor_id')->constrained('proveedores');
            $table->foreignId('user_id')->constrained('users');            // quién la generó
            $table->string('metodo_pago', 20);                             // TARJETA | TRANSFERENCIA | EFECTIVO | CHEQUE
            $table->text('comentarios')->nullable();
            $table->string('estado', 20)->default('emitida');              // App\Enums\EstadoOrdenProveedor
            $table->decimal('total_costo', 14, 2)->default(0);             // sin IVA
            $table->timestamps();
            $table->softDeletes();
            $table->index(['proveedor_id', 'created_at']);
        });

        Schema::create('pedido_partidas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pedido_id')->constrained('pedidos')->cascadeOnDelete();
            $table->foreignId('cotizacion_partida_id')->nullable()->constrained('cotizacion_partidas')->nullOnDelete();
            $table->foreignId('orden_proveedor_id')->nullable()->constrained('ordenes_proveedor')->nullOnDelete();
            $table->unsignedSmallInteger('orden');
            $table->text('descripcion');
            $table->string('marca')->nullable();
            $table->string('modelo')->nullable();
            $table->string('unidad', 10)->default('PZA');
            $table->decimal('cantidad', 12, 3);
            $table->foreignId('proveedor_id')->nullable()->constrained('proveedores')->nullOnDelete();
            $table->string('proveedor_vendedor')->nullable();
            $table->decimal('costo', 14, 4)->nullable();                   // SIN IVA, unitario
            $table->decimal('precio', 14, 2);                              // SIN IVA, unitario (venta)
            $table->string('estado', 20)->default('pendiente');            // App\Enums\EstadoPartidaPedido
            $table->boolean('facturada')->default(false);
            $table->string('imagen_path')->nullable();
            $table->timestamps();
            $table->index(['pedido_id', 'orden']);
        });

        // Registro de cada vez que se comparte un documento (cotización u OC a proveedor).
        Schema::create('documento_envios', function (Blueprint $table) {
            $table->id();
            $table->morphs('documento');                                   // Cotizacion | OrdenProveedor
            $table->foreignId('user_id')->constrained('users');
            $table->string('canal', 20);                                   // correo | whatsapp | descarga
            $table->string('destinatario')->nullable();                    // correo(s) o teléfono
            $table->unsignedSmallInteger('revision')->default(0);
            $table->boolean('exito')->default(true);
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documento_envios');
        Schema::dropIfExists('pedido_partidas');
        Schema::dropIfExists('ordenes_proveedor');
        Schema::dropIfExists('pedidos');
    }
};
