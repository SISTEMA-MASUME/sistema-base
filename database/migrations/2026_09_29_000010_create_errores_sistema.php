<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('errores_sistema', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 12)->unique();
            $table->string('huella', 64)->index();
            $table->string('clase');
            $table->text('mensaje');
            $table->string('archivo', 500)->nullable();
            $table->unsignedInteger('linea')->nullable();
            $table->string('url', 1000)->nullable();
            $table->string('metodo', 10)->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedInteger('ocurrencias')->default(1);
            $table->timestamp('primera_vez')->nullable();
            $table->timestamp('ultima_vez')->nullable()->index();
            $table->boolean('resuelto')->default(false)->index();
            $table->unsignedBigInteger('resuelto_por')->nullable();
            $table->timestamp('resuelto_en')->nullable();
            $table->text('notas')->nullable();
            $table->text('traza')->nullable();
            $table->json('contexto')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('errores_sistema');
    }
};
