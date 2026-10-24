<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Puente SICAR: lotes que la web manda a la PC de Daniel, catálogo de proveedores de SICAR y ajustes. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ajustes', function (Blueprint $table) {
            $table->string('clave', 80)->primary();
            $table->text('valor')->nullable();
            $table->timestamps();
        });

        Schema::create('sicar_lotes', function (Blueprint $table) {
            $table->id();
            $table->string('estado', 20)->default('pendiente')->index();
            $table->json('datos');
            $table->json('resultado')->nullable();
            $table->text('mensaje')->nullable();
            $table->foreignId('creado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('confirmado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('simulado_at')->nullable();
            $table->timestamp('confirmado_at')->nullable();
            $table->timestamp('aplicado_at')->nullable();
            $table->timestamps();
        });

        Schema::create('sicar_lote_pedido', function (Blueprint $table) {
            $table->foreignId('lote_id')->constrained('sicar_lotes')->cascadeOnDelete();
            $table->foreignId('pedido_id')->constrained('pedidos')->cascadeOnDelete();
            $table->primary(['lote_id', 'pedido_id']);
        });

        Schema::create('sicar_proveedores', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('pro_id')->unique();
            $table->string('nombre', 200);
            $table->integer('status')->default(1);
            $table->timestamp('sincronizado_at')->nullable();
            $table->timestamps();
        });

        Schema::table('proveedores', function (Blueprint $table) {
            $table->unsignedBigInteger('sicar_pro_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('proveedores', fn (Blueprint $table) => $table->dropColumn('sicar_pro_id'));
        Schema::dropIfExists('sicar_proveedores');
        Schema::dropIfExists('sicar_lote_pedido');
        Schema::dropIfExists('sicar_lotes');
        Schema::dropIfExists('ajustes');
    }
};
