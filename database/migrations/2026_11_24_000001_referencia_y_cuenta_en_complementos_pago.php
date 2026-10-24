<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La "Referencia" que Daniel escribe en SICAR al hacer el abono/complemento (p. ej. "HEY" o "BBVA") dice a qué cuenta
 * de banco llegó el dinero: el cobro se suma al saldo de esa cuenta en Mis finanzas hasta el siguiente corte.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('complementos_pago', function (Blueprint $t) {
            $t->string('referencia', 120)->nullable()->after('moneda');
            $t->foreignId('cuenta_id')->nullable()->after('referencia')->constrained('cuentas')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('complementos_pago', function (Blueprint $t) {
            $t->dropConstrainedForeignId('cuenta_id');
            $t->dropColumn('referencia');
        });
    }
};
