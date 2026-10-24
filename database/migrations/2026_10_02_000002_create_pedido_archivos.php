<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pedido_archivos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pedido_id')->constrained('pedidos')->cascadeOnDelete();
            $table->string('tipo', 20);                       // oc_cliente | factura_pdf | factura_xml | evidencia | otro
            $table->string('disco', 20)->default('local');
            $table->string('ruta');
            $table->string('nombre_original');
            $table->string('mime', 100)->nullable();
            $table->unsignedBigInteger('tamano')->default(0); // bytes
            $table->foreignId('user_id')->constrained('users');
            $table->text('notas')->nullable();
            $table->json('partidas_ids')->nullable();        // partidas a las que corresponde la foto (informativo)
            $table->timestamps();

            $table->index(['pedido_id', 'tipo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pedido_archivos');
    }
};
