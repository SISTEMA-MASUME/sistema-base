<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clientes', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');                    // nombre corto: "FANOSA" (Nombres::limpiar)
            $table->string('nombre_llave')->unique();    // Nombres::llave(): evita PROVEX vs PROVEX.
            $table->string('razon_social')->nullable();
            $table->string('rfc', 13)->nullable();
            $table->string('direccion')->nullable();
            $table->string('telefono', 30)->nullable();
            $table->string('correo')->nullable();
            $table->unsignedSmallInteger('dias_credito')->default(30);
            $table->text('notas')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('contactos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cliente_id')->constrained('clientes')->cascadeOnDelete();
            $table->string('nombre');
            $table->string('puesto')->nullable();
            $table->string('correo')->nullable();
            $table->string('telefono', 30)->nullable();
            $table->timestamps();
        });

        Schema::create('proveedores', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->string('nombre_llave')->unique();
            $table->string('contacto')->nullable();
            $table->string('telefono', 30)->nullable();
            $table->string('correo')->nullable();
            $table->text('notas')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // Contadores de folio. Convención heredada de CONFIG!B2: "ultimo" = último folio YA usado.
        Schema::create('contadores', function (Blueprint $table) {
            $table->string('clave', 30)->primary();      // 'cotizacion'
            $table->unsignedBigInteger('ultimo')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contadores');
        Schema::dropIfExists('proveedores');
        Schema::dropIfExists('contactos');
        Schema::dropIfExists('clientes');
    }
};
