<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Desde qué cuenta se le paga a cada empleado (BBVA, Hey Banco…): la de siempre va en el empleado y la de cada semana en su
 * línea de nómina. Vacío = la cuenta general de la nómina (Hey Banco Débito), como antes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('cuenta_nomina_id')->nullable()->constrained('cuentas')->nullOnDelete();
        });
        Schema::table('nomina_lineas', function (Blueprint $table) {
            $table->foreignId('cuenta_id')->nullable()->constrained('cuentas')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('nomina_lineas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cuenta_id');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cuenta_nomina_id');
        });
    }
};
