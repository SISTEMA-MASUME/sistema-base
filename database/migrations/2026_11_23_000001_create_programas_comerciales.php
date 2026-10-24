<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Programa comercial de un proveedor (Urrea M1 2026): niveles por lo comprado en el trimestre que dan descuento en
 * factura el trimestre siguiente y bono de volumen. Se lleva la cuenta con las OC al proveedor + ajustes a mano.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('programas_comerciales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proveedor_id')->unique()->constrained('proveedores')->cascadeOnDelete();
            $table->string('nombre', 120);
            $table->decimal('descuento_base', 5, 2)->default(0);       // Urrea: 20%
            $table->decimal('anticipado', 5, 2)->default(0);           // descuento extra por pago anticipado (3%)
            $table->json('niveles');                                   // [{desde, factura, bono}] por consumo trimestral sin IVA
            $table->json('acelerador')->nullable();                    // [{skus, bono}] por SKUs distintos en 12 meses
            $table->decimal('acelerador_desde', 14, 2)->default(0);    // consumo mínimo para que cuente el acelerador
            $table->decimal('bono_maximo', 5, 2)->nullable();
            $table->boolean('bono_en_factura')->default(false);        // el bono de volumen también baja la factura (Urreanet lo dice)
            $table->decimal('bono_factura', 5, 2)->nullable();          // el % que trae la factura real ("4% de bono por volumen")
            $table->string('bono_factura_trimestre', 7)->nullable();   // "2026-4": solo vale para ese trimestre
            $table->timestamps();
        });

        Schema::create('programa_ajustes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('programa_comercial_id')->constrained('programas_comerciales')->cascadeOnDelete();
            $table->string('tipo', 10)->default('compra');             // compra (suma) | total (lo que dice Urreanet al día: manda)
            $table->date('fecha');
            $table->decimal('monto', 14, 2);                           // sin IVA; compra negativa = nota de crédito o devolución
            $table->string('nota', 191)->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['programa_comercial_id', 'fecha']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('programa_ajustes');
        Schema::dropIfExists('programas_comerciales');
    }
};
