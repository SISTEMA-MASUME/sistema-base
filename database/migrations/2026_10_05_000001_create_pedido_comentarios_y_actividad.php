<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Comentarios de la tarjeta, como en Trello (autor, fecha y hora).
        Schema::create('pedido_comentarios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pedido_id')->constrained('pedidos')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('texto');
            $table->timestamps();
            $table->index(['pedido_id', 'created_at']);
        });

        // Registro de actividad (solo inserción): quién movió, cambió estados, subió archivos, archivó…
        Schema::create('pedido_actividades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pedido_id')->constrained('pedidos')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('tipo', 40);
            $table->string('descripcion', 500);
            $table->timestamp('created_at')->nullable();
            $table->index(['pedido_id', 'created_at']);
        });

        // Título editable en línea; si está vacío se arma "CLIENTE - OC 123 - INICIALES".
        Schema::table('pedidos', function (Blueprint $table) {
            $table->string('titulo_personalizado', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('pedidos', function (Blueprint $table) {
            $table->dropColumn('titulo_personalizado');
        });
        Schema::dropIfExists('pedido_actividades');
        Schema::dropIfExists('pedido_comentarios');
    }
};
