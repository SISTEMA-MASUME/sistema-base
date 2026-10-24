<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Copia del catálogo de SICAR (la PC de Daniel lo envía por HTTPS) y decisiones de alta por partida. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sicar_articulos', function (Blueprint $table) {
            $table->unsignedBigInteger('art_id')->primary();
            $table->string('clave', 60)->nullable()->index();
            $table->string('clave_alterna', 60)->nullable();
            $table->text('descripcion')->nullable();
            $table->string('unidad', 20)->nullable();
            $table->decimal('precio_compra', 14, 4)->nullable();
            $table->decimal('precio1', 14, 4)->nullable();
            $table->string('clave_prod_serv', 10)->nullable();
            $table->string('categoria', 80)->nullable();
            $table->tinyInteger('status')->default(1);
            $table->string('clave_llave', 60)->nullable()->index();
            $table->timestamp('sincronizado_at')->nullable();
            $table->timestamps();
        });

        Schema::create('sicar_sincronizaciones', function (Blueprint $table) {
            $table->id();
            $table->timestamp('inicio')->nullable();
            $table->timestamp('fin')->nullable();
            $table->unsignedInteger('recibidos')->default(0);
            $table->unsignedInteger('activos')->default(0);
            $table->string('origen', 80)->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });

        Schema::table('pedido_partidas', function (Blueprint $table) {
            $table->unsignedBigInteger('sicar_art_id')->nullable();
            $table->string('sicar_clave', 60)->nullable();
            $table->string('sicar_clave_sat', 10)->nullable();
            // Decisiones de alta de un artículo nuevo (para no repetir trabajo).
            $table->string('sicar_unidad', 20)->nullable();
            $table->string('sicar_categoria', 80)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('pedido_partidas', fn (Blueprint $table) => $table->dropColumn(['sicar_art_id', 'sicar_clave', 'sicar_clave_sat', 'sicar_unidad', 'sicar_categoria']));
        Schema::dropIfExists('sicar_sincronizaciones');
        Schema::dropIfExists('sicar_articulos');
    }
};
