<?php

use App\Models\Articulo;
use App\Support\Articulos;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Listas de precios de las marcas (Truper, Urrea…): una por proveedor, se reemplaza cada mes al subir la nueva.
 * Sus renglones no llenan el catálogo: un artículo nace de la lista cuando se cotiza, se escanea o ya existía.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listas_precios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proveedor_id')->unique()->constrained('proveedores')->cascadeOnDelete();
            $table->string('nombre', 120);                              // "Truper octubre 2026"
            $table->string('columna_precio', 120);                      // encabezado del precio que es el costo
            $table->boolean('precio_con_iva')->default(false);
            $table->string('descuentos', 60)->default('0');              // como se escribieron: "20 + 5" (encadenados)
            $table->decimal('descuento_pct', 6, 3)->default(0);         // el total: 20 + 5 = 24%
            $table->string('archivo', 191)->nullable();
            $table->unsignedInteger('renglones')->default(0);
            $table->unsignedInteger('ligados')->default(0);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('importada_at')->nullable();
            $table->timestamps();
        });

        Schema::create('lista_precio_renglones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lista_precio_id')->constrained('listas_precios')->cascadeOnDelete();
            $table->string('clave', 60);
            $table->char('clave_llave', 40);
            $table->string('codigo', 60)->nullable();
            $table->text('descripcion');
            $table->text('busqueda')->nullable();                        // descripción, marca y clave sin acentos (para buscar)
            $table->string('marca', 120)->nullable();
            $table->string('unidad', 20)->nullable();
            $table->string('unidad_sat', 5)->nullable();
            $table->string('clave_sat', 10)->nullable();
            $table->string('codigo_barras', 40)->nullable()->index();
            $table->decimal('precio_lista', 14, 4)->nullable();         // como viene en la columna elegida
            $table->decimal('costo', 14, 4)->nullable();                // SIN IVA y con el descuento
            $table->string('familia', 120)->nullable();
            $table->foreignId('articulo_id')->nullable()->index()->constrained('articulos')->nullOnDelete();
            $table->timestamps();
            $table->unique(['lista_precio_id', 'clave_llave']);
            $table->index('clave_llave');
        });

        // Buscar sin que estorben los acentos ("presion" encuentra "PRESIÓN").
        Schema::table('articulos', function (Blueprint $table) {
            $table->text('busqueda')->nullable();
        });
        Articulo::query()->chunkById(500, function ($articulos) {
            foreach ($articulos as $a) {
                DB::table('articulos')->where('id', $a->id)->update(['busqueda' => Articulos::textoBusqueda($a->descripcion, $a->marca)]);
            }
        });

        // El costo que vino de una lista se distingue del de una compra real.
        Schema::table('articulo_proveedores', function (Blueprint $table) {
            $table->foreignId('lista_precio_id')->nullable()->constrained('listas_precios')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('articulo_proveedores', fn (Blueprint $table) => $table->dropConstrainedForeignId('lista_precio_id'));
        Schema::table('articulos', fn (Blueprint $table) => $table->dropColumn('busqueda'));
        Schema::dropIfExists('lista_precio_renglones');
        Schema::dropIfExists('listas_precios');
    }
};
