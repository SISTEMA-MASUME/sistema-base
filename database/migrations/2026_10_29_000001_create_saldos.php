<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mis finanzas (patrimonio): no se captura movimiento por movimiento; de vez en cuando se llenan los saldos de todas las
 * cuentas ("corte"). `cuentas.tipo` ahora también acepta 'por_cobrar' (préstamos personales que le deben): es texto, sin cambio de columna.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saldo_cortes', function (Blueprint $table) {
            $table->id();
            $table->date('fecha');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notas')->nullable();
            $table->timestamps();
            $table->index('fecha');
        });

        Schema::create('saldo_lineas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('saldo_corte_id')->constrained('saldo_cortes')->cascadeOnDelete();
            $table->foreignId('cuenta_id')->constrained('cuentas')->cascadeOnDelete();
            $table->decimal('saldo', 14, 2)->default(0);                       // en tarjetas y créditos: lo que se debe
            $table->decimal('pago_sin_intereses', 14, 2)->nullable();          // tarjetas: pago para no generar intereses
            $table->date('fecha_limite')->nullable();                          // tarjetas: fecha límite de ese pago
            $table->timestamps();
            $table->unique(['saldo_corte_id', 'cuenta_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saldo_lineas');
        Schema::dropIfExists('saldo_cortes');
    }
};
