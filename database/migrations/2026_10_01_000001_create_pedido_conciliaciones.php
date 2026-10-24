<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pedido_conciliaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pedido_id')->constrained('pedidos')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users');
            $table->string('archivo_hash', 64)->index();                 // SHA-256 del archivo de la OC
            $table->string('proveedor_ia', 30)->default('openai');
            $table->string('modelo', 60);
            $table->string('response_id')->nullable();
            $table->string('estado', 20)->default('procesando');         // procesando | completada | error
            $table->json('extraccion')->nullable();                      // JSON que devolvió la IA
            $table->json('resultado')->nullable();                       // salida del ConciliadorOc (PHP)
            $table->json('resoluciones')->nullable();                    // filas ignoradas / resueltas
            $table->string('resumen')->nullable();
            $table->boolean('hay_diferencias')->default(false);
            $table->text('error')->nullable();
            $table->unsignedInteger('tokens_entrada')->default(0);
            $table->unsignedInteger('tokens_salida')->default(0);
            $table->unsignedInteger('tokens_cache')->default(0);
            $table->decimal('costo_usd', 10, 5)->default(0);
            $table->unsignedInteger('duracion_seg')->default(0);
            $table->timestamps();
            $table->index(['pedido_id', 'estado']);
        });

        Schema::table('ordenes_proveedor', function (Blueprint $table) {
            $table->text('motivo_sin_conciliar')->nullable();
            $table->foreignId('autorizo_sin_conciliar_id')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ordenes_proveedor', function (Blueprint $table) {
            $table->dropConstrainedForeignId('autorizo_sin_conciliar_id');
            $table->dropColumn('motivo_sin_conciliar');
        });
        Schema::dropIfExists('pedido_conciliaciones');
    }
};
