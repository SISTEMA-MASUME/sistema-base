<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo de artículos (camino para dejar SICAR): cada artículo una sola vez, con todos los proveedores que lo venden
 * y sus datos para facturar. Las partidas de pedidos (y luego de cotizaciones) se ligan a su artículo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('articulos', function (Blueprint $table) {
            $table->id();
            $table->text('descripcion');
            $table->char('descripcion_llave', 40)->nullable()->index();   // sha1 de Nombres::llave(descripción)
            $table->string('marca', 120)->nullable();
            $table->string('unidad', 20)->default('PZA');                // como se escribe (PZA, CAJA, METRO…)
            $table->string('unidad_sat', 5)->nullable();                 // c_ClaveUnidad (H87, XBX, MTR…)
            $table->string('clave_sat', 10)->nullable();                 // c_ClaveProdServ
            $table->string('codigo_barras', 40)->nullable()->index();
            $table->unsignedBigInteger('sicar_art_id')->nullable()->index();
            $table->string('sicar_clave', 60)->nullable();
            $table->string('origen', 12)->default('manual');             // sicar | pedido | lista | manual
            $table->timestamp('ultimo_pedido_at')->nullable()->index();  // para ordenar la lista: lo más vendido reciente arriba
            $table->foreignId('unido_a_id')->nullable()->constrained('articulos')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        // Otros nombres con los que se conoce (al unir repetidos, el nombre del otro queda aquí).
        Schema::create('articulo_nombres', function (Blueprint $table) {
            $table->id();
            $table->foreignId('articulo_id')->constrained('articulos')->cascadeOnDelete();
            $table->text('nombre');
            $table->char('llave', 40)->index();
            $table->timestamps();
        });

        // Quién lo vende: un renglón por proveedor, con su clave (el "modelo" suele ser su SKU) y el último costo.
        Schema::create('articulo_proveedores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('articulo_id')->constrained('articulos')->cascadeOnDelete();
            $table->foreignId('proveedor_id')->constrained('proveedores')->cascadeOnDelete();
            $table->string('clave', 191)->nullable();
            $table->char('clave_llave', 40)->nullable()->index();
            $table->decimal('ultimo_costo', 14, 4)->nullable();          // SIN IVA, unitario
            $table->date('costo_fecha')->nullable();
            $table->foreignId('pedido_id')->nullable()->constrained('pedidos')->nullOnDelete();
            $table->text('link_compra')->nullable();
            $table->timestamps();
            $table->unique(['articulo_id', 'proveedor_id']);
        });

        Schema::table('pedido_partidas', function (Blueprint $table) {
            $table->foreignId('articulo_id')->nullable()->index()->constrained('articulos')->nullOnDelete();
        });
        Schema::table('cotizacion_partidas', function (Blueprint $table) {
            $table->foreignId('articulo_id')->nullable()->index()->constrained('articulos')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('cotizacion_partidas', fn (Blueprint $table) => $table->dropConstrainedForeignId('articulo_id'));
        Schema::table('pedido_partidas', fn (Blueprint $table) => $table->dropConstrainedForeignId('articulo_id'));
        Schema::dropIfExists('articulo_proveedores');
        Schema::dropIfExists('articulo_nombres');
        Schema::dropIfExists('articulos');
    }
};
